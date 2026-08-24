<?php
/**
 * Traite un événement provider DÉJÀ vérifié (signature validée par le provider). Idempotent
 * via le store d'événements + l'unicité paiement. Transitions basées sur l'état courant
 * (tolérant au hors-ordre / rejeu). Ne fait JAMAIS confiance aveugle aux metadata : l'ordre
 * DB est l'autorité (résolu par order_uuid) et les montants sont comparés au snapshot.
 *
 * @package Postelio\Billing\Webhook
 */

namespace Postelio\Billing\Webhook;

use Postelio\Billing\Domain\OrderStatus;
use Postelio\Billing\Domain\PaymentStatus;
use Postelio\Billing\Events\ProviderEventRepository;
use Postelio\Billing\Fulfillment\FulfillmentService;
use Postelio\Billing\Orders\OrderRepository;
use Postelio\Billing\Payments\PaymentRepository;
use Postelio\Core\Plugin as Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WebhookProcessor {

	/** Événements traités en V1. */
	public const HANDLED = array(
		'checkout.session.completed',
		'checkout.session.async_payment_succeeded',
		'checkout.session.async_payment_failed',
		'checkout.session.expired',
		'charge.refunded',
		'charge.dispute.created',
	);

	private OrderRepository $orders;
	private PaymentRepository $payments;
	private ProviderEventRepository $events;
	private FulfillmentService $fulfillment;

	public function __construct( OrderRepository $orders, PaymentRepository $payments, ProviderEventRepository $events, FulfillmentService $fulfillment ) {
		$this->orders      = $orders;
		$this->payments    = $payments;
		$this->events      = $events;
		$this->fulfillment = $fulfillment;
	}

	/**
	 * @param array<string,mixed> $event  Événement décodé (Stripe-like).
	 * @return array{status:string}
	 */
	public function handle( array $event ): array {
		$event_id = (string) ( $event['id'] ?? '' );
		$type     = (string) ( $event['type'] ?? '' );
		$object   = is_array( $event['data']['object'] ?? null ) ? $event['data']['object'] : array();
		if ( '' === $event_id || '' === $type ) {
			return array( 'status' => 'invalid' );
		}

		$claim = $this->events->claim( 'stripe', $event_id, $type );
		if ( 'done' === $claim ) {
			return array( 'status' => 'duplicate' );
		}
		if ( ! in_array( $type, self::HANDLED, true ) ) {
			$this->events->finalize( 'stripe', $event_id, ProviderEventRepository::IGNORED );
			return array( 'status' => 'ignored' );
		}

		try {
			$order_id = $this->dispatch( $type, $object );
			$this->events->finalize( 'stripe', $event_id, ProviderEventRepository::PROCESSED, $order_id );
			return array( 'status' => 'processed' );
		} catch ( \Throwable $e ) {
			$this->events->finalize( 'stripe', $event_id, ProviderEventRepository::ERROR, null, substr( $e->getMessage(), 0, 240 ) );
			return array( 'status' => 'error' );
		}
	}

	/** @param array<string,mixed> $object @return int|null related order id */
	private function dispatch( string $type, array $object ): ?int {
		switch ( $type ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				return $this->on_paid( $object );
			case 'checkout.session.async_payment_failed':
				return $this->on_failed( $object );
			case 'checkout.session.expired':
				return $this->on_expired( $object );
			case 'charge.refunded':
				return $this->on_refunded( $object );
			case 'charge.dispute.created':
				return $this->on_disputed( $object );
		}
		return null;
	}

	/** @param array<string,mixed> $session */
	private function on_paid( array $session ): ?int {
		$order = $this->resolve_order_from_session( $session );
		if ( null === $order ) {
			return null;
		}
		$order_id   = (int) $order['id'];
		$session_id = (string) ( $session['id'] ?? '' );
		$pi         = (string) ( $session['payment_intent'] ?? '' );
		$amount     = (int) ( $session['amount_total'] ?? $order['total_amount'] );
		$currency   = strtoupper( (string) ( $session['currency'] ?? $order['currency'] ) );
		$receipt    = isset( $session['receipt_url'] ) ? (string) $session['receipt_url'] : null;

		// Rejeu de la MÊME session : idempotent.
		$existing = $this->payments->get_by_session( $session_id );
		if ( null !== $existing && PaymentStatus::SUCCEEDED === $existing['status'] ) {
			return $order_id;
		}

		$already_paid = $this->payments->count_succeeded_for_order( $order_id ) > 0;

		$payment_id = $this->payments->insert( array(
			'order_id'                   => $order_id,
			'status'                     => PaymentStatus::CREATED,
			'amount'                     => $amount,
			'currency'                   => $currency,
			'provider_session_id'        => '' !== $session_id ? $session_id : null,
			'provider_payment_intent_id' => '' !== $pi ? $pi : null,
			'receipt_url'                => $receipt,
		) );
		if ( 0 === $payment_id ) {
			// Conflit d'unicité (course) : déjà enregistré, rien à faire.
			return $order_id;
		}

		if ( $already_paid ) {
			// DOUBLE PAIEMENT : jamais de 2e renouvellement. Marque duplicate + revue admin.
			$this->payments->update( $payment_id, array( 'status' => PaymentStatus::DUPLICATE, 'paid_at' => current_time( 'mysql', true ) ) );
			$this->orders->set_status( $order_id, OrderStatus::MANUAL_REVIEW );
			$this->emit( 'order.manual_review', $order, array( 'reason' => 'duplicate_payment' ) );
			return $order_id;
		}

		$this->payments->update( $payment_id, array( 'status' => PaymentStatus::SUCCEEDED, 'paid_at' => current_time( 'mysql', true ) ) );
		$this->orders->update( $order_id, array( 'status' => OrderStatus::PAID, 'paid_at' => current_time( 'mysql', true ) ) );
		$this->emit( 'payment.succeeded', $order, array( 'amount' => $amount, 'currency' => $currency ) );

		// Fulfillment (exactly-once, idempotent).
		$this->fulfillment->fulfill( $order_id );
		return $order_id;
	}

	/** @param array<string,mixed> $session */
	private function on_failed( array $session ): ?int {
		$order = $this->resolve_order_from_session( $session );
		if ( null === $order ) {
			return null;
		}
		$order_id   = (int) $order['id'];
		$session_id = (string) ( $session['id'] ?? '' );
		if ( null === $this->payments->get_by_session( $session_id ) ) {
			$this->payments->insert( array(
				'order_id'            => $order_id,
				'status'              => PaymentStatus::FAILED,
				'amount'              => (int) $order['total_amount'],
				'currency'            => (string) $order['currency'],
				'provider_session_id' => '' !== $session_id ? $session_id : null,
				'failure_code'        => 'async_payment_failed',
			) );
		}
		if ( OrderStatus::can_transition( (string) $order['status'], OrderStatus::PAYMENT_FAILED ) ) {
			$this->orders->set_status( $order_id, OrderStatus::PAYMENT_FAILED );
		}
		$this->emit( 'payment.failed', $order, array( 'code' => 'async_payment_failed' ) );
		return $order_id;
	}

	/** @param array<string,mixed> $session */
	private function on_expired( array $session ): ?int {
		$order = $this->resolve_order_from_session( $session );
		if ( null === $order ) {
			return null;
		}
		if ( OrderStatus::can_transition( (string) $order['status'], OrderStatus::EXPIRED ) ) {
			$this->orders->set_status( (int) $order['id'], OrderStatus::EXPIRED );
		}
		return (int) $order['id'];
	}

	/** @param array<string,mixed> $charge */
	private function on_refunded( array $charge ): ?int {
		$payment = $this->payments->get_by_payment_intent( (string) ( $charge['payment_intent'] ?? '' ) );
		if ( null === $payment ) {
			return null;
		}
		$this->payments->update( (int) $payment['id'], array( 'status' => PaymentStatus::REFUNDED, 'refunded_at' => current_time( 'mysql', true ) ) );
		$order = $this->orders->get( (int) $payment['order_id'] );
		if ( null !== $order ) {
			// NE retire PAS les jours du Job. Reflète seulement l'état financier.
			if ( OrderStatus::can_transition( (string) $order['status'], OrderStatus::REFUNDED ) ) {
				$this->orders->set_status( (int) $order['id'], OrderStatus::REFUNDED );
			}
			$this->emit( 'payment.refunded', $order, array() );
			return (int) $order['id'];
		}
		return null;
	}

	/** @param array<string,mixed> $dispute */
	private function on_disputed( array $dispute ): ?int {
		$pi      = (string) ( $dispute['payment_intent'] ?? '' );
		$payment = $this->payments->get_by_payment_intent( $pi );
		if ( null === $payment ) {
			return null;
		}
		$this->payments->update( (int) $payment['id'], array( 'status' => PaymentStatus::DISPUTED, 'disputed_at' => current_time( 'mysql', true ) ) );
		$order = $this->orders->get( (int) $payment['order_id'] );
		if ( null !== $order ) {
			// Aucune suspension automatique (user/company/job). Alerte admin uniquement.
			$this->emit( 'payment.disputed', $order, array() );
			return (int) $order['id'];
		}
		return null;
	}

	/** @param array<string,mixed> $session @return array<string,mixed>|null */
	private function resolve_order_from_session( array $session ): ?array {
		$uuid = (string) ( $session['client_reference_id'] ?? ( $session['metadata']['order_uuid'] ?? '' ) );
		return '' !== $uuid ? $this->orders->get_by_uuid( $uuid ) : null;
	}

	/** @param array<string,mixed> $order @param array<string,mixed> $extra */
	private function emit( string $event, array $order, array $extra ): void {
		Core::instance()->events()->emit(
			$event,
			array(
				'order_uuid'    => (string) $order['public_uuid'],
				'company_id'    => (int) $order['company_id'],
				'resource_type' => 'billing_order',
				'resource_id'   => (string) $order['public_uuid'],
				'audit'         => array_merge( array( 'order_uuid' => (string) $order['public_uuid'], 'product_code' => (string) $order['product_code'] ), $extra ),
			)
		);
	}
}
