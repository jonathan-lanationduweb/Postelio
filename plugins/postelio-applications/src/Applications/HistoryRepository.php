<?php
/**
 * Historique append-only des candidatures (`wp_postelio_application_history`).
 * Ne contient JAMAIS de données sensibles complètes (motif interne, corps de note…),
 * seulement des métadonnées minimales.
 *
 * @package Postelio\Applications\Applications
 */

namespace Postelio\Applications\Applications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HistoryRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_application_history';
	}

	/**
	 * @param array<string, mixed> $entry action, from_status?, to_status?, actor_id?, actor_role?, metadata?
	 */
	public function add( int $application_id, array $entry ): void {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'application_id' => $application_id,
				'from_status'    => isset( $entry['from_status'] ) ? (string) $entry['from_status'] : null,
				'to_status'      => isset( $entry['to_status'] ) ? (string) $entry['to_status'] : null,
				'action'         => (string) ( $entry['action'] ?? 'updated' ),
				'actor_id'       => isset( $entry['actor_id'] ) ? (int) $entry['actor_id'] : null,
				'actor_role'     => isset( $entry['actor_role'] ) ? (string) $entry['actor_role'] : null,
				'metadata'       => ! empty( $entry['metadata'] ) ? wp_json_encode( $entry['metadata'] ) : null,
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Timeline d'une candidature (chronologique).
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function timeline( int $application_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT from_status, to_status, action, actor_role, metadata, created_at FROM ' . self::table() . ' WHERE application_id = %d ORDER BY id ASC', $application_id ),
			ARRAY_A
		);
		return array_map(
			static function ( array $r ): array {
				$r['metadata'] = ( is_string( $r['metadata'] ) && '' !== $r['metadata'] ) ? json_decode( $r['metadata'], true ) : null;
				return $r;
			},
			$rows ?: array()
		);
	}

	public function delete_for_application( int $application_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'application_id' => $application_id ), array( '%d' ) );
	}
}
