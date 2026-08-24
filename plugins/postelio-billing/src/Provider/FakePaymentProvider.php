<?php
/**
 * Provider de test — AUCUN appel réseau. Permet de piloter customer/checkout/webhook/refund
 * de façon déterministe dans les tests unitaires et smoke. Aucune suite ne dépend de Stripe.
 * `verify_webhook` accepte l'en-tête littéral 'fake-valid' (ou tout sauf 'fake-invalid') et
 * décode le corps JSON ; 'fake-invalid' simule une signature invalide.
 *
 * @package Postelio\Billing\Provider
 */

namespace Postelio\Billing\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_BILLING_TESTING' ) ) {
		exit;
	}
}

final class FakePaymentProvider implements PaymentProvider {

	public string $mode = 'test';
	public bool $configured = true;
	public bool $customer_fails = false;
	public bool $checkout_fails = false;
	/** @var array<int,array<string,mixed>> */
	public array $created_checkouts = array();

	public function name(): string {
		return 'fake';
	}
	public function mode(): string {
		return $this->mode;
	}
	public function is_configured(): bool {
		return $this->configured;
	}

	public function create_customer( array $company ): ?string {
		if ( $this->customer_fails ) {
			return null;
		}
		return 'cus_fake_' . substr( md5( (string) ( $company['company_uuid'] ?? 'x' ) ), 0, 12 );
	}

	public function create_checkout( array $order, string $success_url, string $cancel_url ): ?array {
		if ( $this->checkout_fails ) {
			return null;
		}
		$sid = 'cs_fake_' . substr( md5( (string) $order['public_uuid'] ), 0, 16 );
		$out = array(
			'session_id'        => $sid,
			'url'               => 'https://checkout.stripe.test/pay/' . $sid,
			'payment_intent_id' => 'pi_fake_' . substr( md5( (string) $order['public_uuid'] ), 0, 16 ),
			'expires_at'        => null,
		);
		$this->created_checkouts[] = array( 'order' => $order['public_uuid'], 'success_url' => $success_url, 'cancel_url' => $cancel_url );
		return $out;
	}

	public function verify_webhook( string $raw_body, string $signature_header ): ?array {
		if ( 'fake-invalid' === $signature_header ) {
			return null;
		}
		$event = json_decode( $raw_body, true );
		return is_array( $event ) ? $event : null;
	}

	public function refund( string $payment_intent_id ): bool {
		return '' !== $payment_intent_id;
	}

	public function health(): array {
		return array( 'provider' => 'fake', 'mode' => $this->mode, 'configured' => $this->configured, 'webhook_configured' => true );
	}
}
