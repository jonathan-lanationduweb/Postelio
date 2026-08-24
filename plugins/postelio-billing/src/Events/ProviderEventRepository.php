<?php
/**
 * Store d'événements provider (`wp_postelio_billing_events`) — idempotence + reprise +
 * observabilité webhook. UNIQUE(provider,event_id). Ne stocke PAS le payload brut Stripe :
 * seulement type + statut + erreur. Distinct du Core Audit.
 *
 * @package Postelio\Billing\Events
 */

namespace Postelio\Billing\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProviderEventRepository {

	public const RECEIVED  = 'received';
	public const PROCESSED = 'processed';
	public const IGNORED   = 'ignored';
	public const ERROR     = 'error';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_billing_events';
	}

	/**
	 * Réserve un event pour traitement (idempotent). Retourne :
	 *   - 'new'        : première réception → à traiter ;
	 *   - 'retry'      : déjà vu mais non finalisé (received/error) → retraitable ;
	 *   - 'done'       : déjà `processed` ou `ignored` → no-op.
	 */
	public function claim( string $provider, string $event_id, string $event_type ): string {
		global $wpdb;
		$table = self::table();
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE provider = %s AND event_id = %s", $provider, $event_id ), ARRAY_A );
		if ( null === $existing ) {
			$wpdb->insert( $table, array(
				'provider'    => $provider,
				'event_id'    => $event_id,
				'event_type'  => $event_type,
				'status'      => self::RECEIVED,
				'attempts'    => 1,
				'received_at' => current_time( 'mysql', true ),
			) );
			return 'new';
		}
		if ( in_array( (string) $existing['status'], array( self::PROCESSED, self::IGNORED ), true ) ) {
			return 'done';
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d", (int) $existing['id'] ) );
		return 'retry';
	}

	public function finalize( string $provider, string $event_id, string $status, ?int $order_id = null, ?string $error = null ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array( 'status' => $status, 'related_order_id' => $order_id, 'last_error' => $error, 'processed_at' => current_time( 'mysql', true ) ),
			array( 'provider' => $provider, 'event_id' => $event_id )
		);
	}

	/** @return array<string,mixed>|null */
	public function last_received(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT event_type, received_at FROM ' . self::table() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		return $row ?: null;
	}
}
