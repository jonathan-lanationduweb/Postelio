<?php
/**
 * Persistance des ordres Billing (`wp_postelio_billing_orders`). Montants en centimes. UUID
 * public exposé, jamais l'ID SQL. `snapshot_json` figé à la création.
 *
 * @package Postelio\Billing\Orders
 */

namespace Postelio\Billing\Orders;

use Postelio\Billing\Domain\OrderStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_billing_orders';
	}

	/** @param array<string,mixed> $data @return int id */
	public function insert( array $data ): int {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$uuid = $this->unique_uuid();
		$snapshot = isset( $data['snapshot'] ) ? wp_json_encode( $data['snapshot'] ) : null;
		$ok = $wpdb->insert(
			self::table(),
			array(
				'public_uuid'     => $uuid,
				'company_id'      => (int) $data['company_id'],
				'company_uuid'    => (string) $data['company_uuid'],
				'buyer_user_id'   => (int) $data['buyer_user_id'],
				'product_code'    => (string) $data['product_code'],
				'resource_type'   => (string) $data['resource_type'],
				'resource_uuid'   => (string) $data['resource_uuid'],
				'status'          => OrderStatus::CREATED,
				'fulfillment_status' => OrderStatus::F_NONE,
				'currency'        => (string) $data['currency'],
				'unit_amount'     => (int) $data['unit_amount'],
				'tax_mode'        => (string) $data['tax_mode'],
				'tax_rate'        => (int) $data['tax_rate'],
				'tax_amount'      => (int) $data['tax_amount'],
				'total_amount'    => (int) $data['total_amount'],
				'duration_days'   => (int) $data['duration_days'],
				'snapshot_json'   => $snapshot,
				'provider'        => (string) ( $data['provider'] ?? 'stripe' ),
				'idempotency_key' => $uuid,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** @return array<string,mixed>|null */
	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	/** @return array<string,mixed>|null */
	public function get_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE public_uuid = %s', $uuid ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	/**
	 * Ordre RÉUTILISABLE (awaiting_payment / created non expiré) pour un triplet
	 * entreprise + ressource + produit. Sert l'anti double-clic.
	 *
	 * @return array<string,mixed>|null
	 */
	public function reusable( int $company_id, string $resource_type, string $resource_uuid, string $product_code ): ?array {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . '
				 WHERE company_id = %d AND resource_type = %s AND resource_uuid = %s AND product_code = %s
				 AND status IN (%s, %s)
				 AND ( checkout_expires_at IS NULL OR checkout_expires_at > %s )
				 ORDER BY id DESC LIMIT 1',
				$company_id, $resource_type, $resource_uuid, $product_code,
				OrderStatus::CREATED, OrderStatus::AWAITING_PAYMENT, $now
			),
			ARRAY_A
		);
		return $row ? $this->decode( $row ) : null;
	}

	/** @param array<string,mixed> $fields */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) );
	}

	public function set_status( int $id, string $status ): void {
		$this->update( $id, array( 'status' => $status ) );
	}

	/** Incrémente le compteur de tentatives de fulfillment et journalise l'erreur. */
	public function bump_fulfillment_attempt( int $id, ?string $error = null ): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET fulfillment_attempts = fulfillment_attempts + 1, last_fulfillment_error = %s, updated_at = %s WHERE id = %d", $error, current_time( 'mysql', true ), $id ) );
	}

	/**
	 * Ordres à (re)traiter par le worker de fulfillment.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function due_for_fulfillment( int $max_attempts, int $limit = 20 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . '
				 WHERE status IN (%s, %s, %s) AND fulfillment_status IN (%s, %s)
				 AND fulfillment_attempts < %d
				 ORDER BY id ASC LIMIT %d',
				OrderStatus::PAID, OrderStatus::FULFILLMENT_PENDING, OrderStatus::FULFILLMENT_FAILED,
				OrderStatus::F_PENDING, OrderStatus::F_FAILED, $max_attempts, $limit
			),
			ARRAY_A
		);
		return array_map( array( $this, 'decode' ), $rows ?: array() );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array{items: array<int,array<string,mixed>>, total:int}
	 */
	public function list( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['company_id'] ) ) {
			$where[] = 'company_id = %d';
			$args[]  = (int) $filters['company_id'];
		}
		if ( ! empty( $filters['status'] ) && OrderStatus::is_status( (string) $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = (string) $filters['status'];
		}
		$clause = implode( ' AND ', $where );
		$table  = self::table();
		$total  = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$clause}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$clause}" ) );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$q      = "SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows   = $wpdb->get_results( $wpdb->prepare( $q, array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A );
		return array( 'items' => array_map( array( $this, 'decode' ), $rows ?: array() ), 'total' => $total );
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function decode( array $row ): array {
		$row['id']            = (int) $row['id'];
		$row['company_id']    = (int) $row['company_id'];
		$row['buyer_user_id'] = (int) $row['buyer_user_id'];
		foreach ( array( 'unit_amount', 'tax_rate', 'tax_amount', 'total_amount', 'duration_days', 'fulfillment_attempts' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		$row['snapshot'] = $row['snapshot_json'] ? (array) json_decode( (string) $row['snapshot_json'], true ) : array();
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
