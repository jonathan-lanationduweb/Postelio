<?php
/**
 * Persistance des paiements Billing (`wp_postelio_billing_payments`). Unicité sur
 * `provider_session_id` / `provider_payment_intent_id` (idempotence provider). Montants en
 * centimes.
 *
 * @package Postelio\Billing\Payments
 */

namespace Postelio\Billing\Payments;

use Postelio\Billing\Domain\PaymentStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PaymentRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_billing_payments';
	}

	/** @param array<string,mixed> $data @return int id (0 si conflit d'unicité) */
	public function insert( array $data ): int {
		global $wpdb;
		$ok = $wpdb->insert(
			self::table(),
			array(
				'public_uuid'                => $this->unique_uuid(),
				'order_id'                   => (int) $data['order_id'],
				'status'                     => (string) ( $data['status'] ?? PaymentStatus::CREATED ),
				'amount'                     => (int) ( $data['amount'] ?? 0 ),
				'currency'                   => (string) ( $data['currency'] ?? 'EUR' ),
				'provider'                   => (string) ( $data['provider'] ?? 'stripe' ),
				'provider_session_id'        => $data['provider_session_id'] ?? null,
				'provider_payment_intent_id' => $data['provider_payment_intent_id'] ?? null,
				'provider_charge_id'         => $data['provider_charge_id'] ?? null,
				'receipt_url'                => $data['receipt_url'] ?? null,
				'created_at'                 => current_time( 'mysql', true ),
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** @return array<string,mixed>|null */
	public function get_by_session( string $session_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE provider_session_id = %s', $session_id ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	/** @return array<string,mixed>|null */
	public function get_by_payment_intent( string $pi ): ?array {
		global $wpdb;
		if ( '' === $pi ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE provider_payment_intent_id = %s', $pi ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	/** @return array<int,array<string,mixed>> */
	public function list_for_order( int $order_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d ORDER BY id ASC', $order_id ), ARRAY_A );
		return array_map( array( $this, 'decode' ), $rows ?: array() );
	}

	public function count_succeeded_for_order( int $order_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE order_id = %d AND status IN (%s,%s)', $order_id, PaymentStatus::SUCCEEDED, PaymentStatus::DUPLICATE ) );
	}

	/** @param array<string,mixed> $fields */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) );
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function decode( array $row ): array {
		$row['id']       = (int) $row['id'];
		$row['order_id'] = (int) $row['order_id'];
		$row['amount']   = (int) $row['amount'];
		return $row;
	}

	private function unique_uuid(): string {
		global $wpdb;
		$table = self::table();
		do {
			$uuid = wp_generate_uuid4();
		} while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE public_uuid = %s", $uuid ) ) > 0 );
		return $uuid;
	}
}
