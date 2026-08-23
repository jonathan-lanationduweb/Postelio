<?php
/**
 * Journal d'observabilité des synchronisations (`wp_postelio_job_source_sync_runs`).
 * Métriques agrégées uniquement — jamais de gros payloads ni de traces complètes.
 *
 * @package Postelio\JobSources\Jobs
 */

namespace Postelio\JobSources\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SyncRunRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_job_source_sync_runs';
	}

	public function start( string $provider_key, string $slice_key ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::table(),
			array(
				'public_uuid'  => wp_generate_uuid4(),
				'provider_key' => $provider_key,
				'slice_key'    => $slice_key,
				'status'       => 'running',
				'started_at'   => $now,
				'created_at'   => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $metrics
	 */
	public function finish( int $id, string $status, array $metrics ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array_merge(
				array( 'status' => $status, 'finished_at' => current_time( 'mysql', true ) ),
				array_intersect_key(
					$metrics,
					array_flip( array( 'sync_cursor', 'pages', 'fetched', 'created_count', 'updated_count', 'unchanged_count', 'stale_count', 'removed_count', 'error_count', 'last_error' ) )
				)
			),
			array( 'id' => $id )
		);
	}

	/** @return array<string, mixed>|null Dernier run (réussi ou non) pour un provider. */
	public function latest( string $provider_key, ?string $only_status = null ): ?array {
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::table() . ' WHERE provider_key = %s';
		$args = array( $provider_key );
		if ( null !== $only_status ) {
			$sql   .= ' AND status = %s';
			$args[] = $only_status;
		}
		$sql .= ' ORDER BY id DESC LIMIT 1';
		$row  = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return $row ?: null;
	}
}
