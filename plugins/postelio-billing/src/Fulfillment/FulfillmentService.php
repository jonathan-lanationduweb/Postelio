<?php
/**
 * Applique l'effet métier d'un ordre PAYÉ : renouvellement de l'offre via le contrat Jobs,
 * en EXACTLY-ONCE (idempotency_key = order_uuid). Étape distincte du paiement : un paiement
 * réussi n'est jamais perdu si l'appel métier échoue (retry borné + manual_review). Revalide
 * les règles (suspension) survenues APRÈS le paiement : ne renouvelle jamais aveuglément.
 *
 * @package Postelio\Billing\Fulfillment
 */

namespace Postelio\Billing\Fulfillment;

use Postelio\Billing\Domain\OrderStatus;
use Postelio\Billing\Orders\OrderRepository;
use Postelio\Core\ApiError;
use Postelio\Core\Plugin as Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FulfillmentService {

	public const MAX_ATTEMPTS = 5;

	private OrderRepository $orders;

	public function __construct( OrderRepository $orders ) {
		$this->orders = $orders;
	}

	/** Balaye les ordres à traiter (worker cron). */
	public function run_due(): int {
		$done = 0;
		foreach ( $this->orders->due_for_fulfillment( self::MAX_ATTEMPTS ) as $order ) {
			$this->fulfill( (int) $order['id'] );
			++$done;
		}
		return $done;
	}

	/**
	 * Traite un ordre. Idempotent : rejouable sans effet double.
	 *
	 * @return array<string,mixed> ordre à jour
	 */
	public function fulfill( int $order_id ): array {
		$order = $this->orders->get( $order_id );
		if ( null === $order ) {
			return array();
		}
		// Déjà fulfilé : no-op (idempotent).
		if ( OrderStatus::FULFILLED === $order['status'] || OrderStatus::F_DONE === $order['fulfillment_status'] ) {
			return $order;
		}
		// Ne traite que des ordres payés.
		if ( ! in_array( $order['status'], array( OrderStatus::PAID, OrderStatus::FULFILLMENT_PENDING, OrderStatus::FULFILLMENT_FAILED ), true ) ) {
			return $order;
		}

		$this->orders->update( $order_id, array( 'status' => OrderStatus::FULFILLMENT_PENDING, 'fulfillment_status' => OrderStatus::F_PENDING ) );

		// Revalidation métier post-paiement : suspension entreprise/utilisateur ⇒ ne pas
		// renouveler aveuglément → manual_review (le paiement reste succeeded).
		if ( $this->buyer_or_company_suspended( $order ) ) {
			$this->orders->update( $order_id, array( 'status' => OrderStatus::MANUAL_REVIEW ) );
			$this->emit( 'order.manual_review', $order, array( 'reason' => 'suspended_after_payment' ) );
			return $this->orders->get( $order_id );
		}

		if ( 'job' !== $order['resource_type'] || ! class_exists( '\\Postelio\\Jobs\\Api\\JobLifecycle' ) || ! class_exists( '\\Postelio\\Jobs\\Api\\JobDirectory' ) ) {
			return $this->fail( $order_id, $order, 'unsupported_resource' );
		}
		$job_id = \Postelio\Jobs\Api\JobDirectory::id_from_uuid( (string) $order['resource_uuid'] );
		if ( $job_id <= 0 ) {
			return $this->fail( $order_id, $order, 'job_not_found' );
		}

		try {
			\Postelio\Jobs\Api\JobLifecycle::renew_after_payment(
				$job_id,
				(int) $order['duration_days'],
				array( 'idempotency_key' => (string) $order['public_uuid'], 'provider_ref' => (string) $order['public_uuid'] )
			);
		} catch ( ApiError $e ) {
			// Offre devenue non renouvelable (ex. suspendue) et jamais appliquée → revue.
			if ( 'invalid_transition' === $e->error_code() ) {
				$this->orders->update( $order_id, array( 'status' => OrderStatus::MANUAL_REVIEW ) );
				$this->emit( 'order.manual_review', $order, array( 'reason' => 'not_renewable' ) );
				return $this->orders->get( $order_id );
			}
			return $this->fail( $order_id, $order, $e->error_code() );
		} catch ( \Throwable $e ) {
			return $this->fail( $order_id, $order, 'exception' );
		}

		$this->orders->update( $order_id, array( 'status' => OrderStatus::FULFILLED, 'fulfillment_status' => OrderStatus::F_DONE, 'fulfilled_at' => current_time( 'mysql', true ) ) );
		// Événement billing (audit/observabilité). La NOTIFICATION recruteur passe par
		// l'événement propriétaire `job.renewed` (émis par Jobs) — pas de doublon ici.
		$this->emit( 'renewal.applied', $order, array( 'resource_uuid' => $order['resource_uuid'] ) );
		return $this->orders->get( $order_id );
	}

	/** @param array<string,mixed> $order */
	private function fail( int $order_id, array $order, string $error ): array {
		$this->orders->bump_fulfillment_attempt( $order_id, $error );
		$fresh = $this->orders->get( $order_id );
		if ( (int) ( $fresh['fulfillment_attempts'] ?? 0 ) >= self::MAX_ATTEMPTS ) {
			$this->orders->update( $order_id, array( 'status' => OrderStatus::FULFILLMENT_FAILED, 'fulfillment_status' => OrderStatus::F_FAILED ) );
			$this->emit( 'fulfillment.failed', $order, array( 'error' => $error ) );
		} else {
			// reste fulfillment_pending → sera repris par le worker
			$this->orders->update( $order_id, array( 'fulfillment_status' => OrderStatus::F_PENDING ) );
		}
		return $this->orders->get( $order_id );
	}

	/** @param array<string,mixed> $order */
	private function buyer_or_company_suspended( array $order ): bool {
		$company_suspended = false;
		if ( class_exists( '\\Postelio\\Companies\\Api\\CompanyBilling' ) ) {
			$identity = \Postelio\Companies\Api\CompanyBilling::identity( (int) $order['company_id'] );
			$company_suspended = is_array( $identity ) && ! empty( $identity['suspended'] );
		}
		$user_suspended = false;
		if ( class_exists( '\\Postelio\\Users\\Api\\UserModeration' ) ) {
			$user_suspended = \Postelio\Users\Api\UserModeration::is_suspended( (int) $order['buyer_user_id'] );
		}
		return $company_suspended || $user_suspended;
	}

	/** @param array<string,mixed> $order @param array<string,mixed> $extra */
	private function emit( string $event, array $order, array $extra = array() ): void {
		Core::instance()->events()->emit(
			$event,
			array_merge(
				array(
					'order_uuid'    => (string) $order['public_uuid'],
					'company_id'    => (int) $order['company_id'],
					'resource_type' => 'billing_order',
					'resource_id'   => (string) $order['public_uuid'],
					'audit'         => array( 'order_uuid' => (string) $order['public_uuid'], 'product_code' => (string) $order['product_code'] ) + $extra,
				),
				$extra
			)
		);
	}
}
