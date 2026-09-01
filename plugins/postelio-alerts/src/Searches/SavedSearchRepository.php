<?php
/**
 * Accès aux recherches sauvegardées. Modèle de vérité UNIQUE de l'état d'alerte :
 * `alert_frequency` (disabled|daily|weekly) — pas de drapeau `enabled` redondant. `filters` en
 * JSON (whitelist validée en amont), `filters_hash` pour la déduplication par candidat (§14).
 *
 * @package Postelio\Alerts\Searches
 */

namespace Postelio\Alerts\Searches;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_ALERTS_TESTING' ) ) {
		exit;
	}
}

final class SavedSearchRepository {

	public const FREQ_DISABLED = 'disabled';
	public const FREQ_DAILY    = 'daily';
	public const FREQ_WEEKLY   = 'weekly';

	/** @return string[] */
	public static function frequencies(): array {
		return array( self::FREQ_DISABLED, self::FREQ_DAILY, self::FREQ_WEEKLY );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_saved_searches';
	}

	/**
	 * Insère une recherche. Retourne l'id créé, ou 0 si la contrainte UNIQUE (doublon de
	 * filtres) a bloqué l'insertion.
	 *
	 * @param array<string, mixed> $row
	 */
	public function insert( array $row ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert( self::table(), array(
			'public_uuid'       => (string) $row['public_uuid'],
			'candidate_user_id' => (int) $row['candidate_user_id'],
			'name'              => (string) $row['name'],
			'filters'           => (string) $row['filters'],
			'filters_hash'      => (string) $row['filters_hash'],
			'alert_frequency'   => (string) $row['alert_frequency'],
			'timezone'          => (string) ( $row['timezone'] ?? 'Europe/Paris' ),
			'cursor_ts'         => $row['cursor_ts'] ?? null,
			'last_run_at'       => null,
			'next_run_at'       => $row['next_run_at'] ?? null,
			'created_at'        => $now,
			'updated_at'        => $now,
		) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** @return array<string,mixed>|null */
	public function get_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE public_uuid = %s', $uuid ), ARRAY_A );
		return $row ?: null;
	}

	/** @return array<string,mixed>|null */
	public function get_by_hash( int $candidate_user_id, string $filters_hash ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE candidate_user_id = %d AND filters_hash = %s',
			$candidate_user_id,
			$filters_hash
		), ARRAY_A );
		return $row ?: null;
	}

	/** @return array<int, array<string,mixed>> */
	public function list_for_candidate( int $candidate_user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE candidate_user_id = %d ORDER BY id DESC',
			$candidate_user_id
		), ARRAY_A );
		return $rows ?: array();
	}

	public function count_for_candidate( int $candidate_user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE candidate_user_id = %d', $candidate_user_id ) );
	}

	/** Nombre de recherches avec alerte ACTIVE (fréquence != disabled) pour un candidat. */
	public function count_active_for_candidate( int $candidate_user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . ' WHERE candidate_user_id = %d AND alert_frequency <> %s',
			$candidate_user_id,
			self::FREQ_DISABLED
		) );
	}

	/**
	 * Met à jour les champs modifiables d'une recherche.
	 *
	 * @param array<string, mixed> $fields
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) );
	}

	/** Met à jour l'état d'exécution (curseur/horodatages) après un cycle. */
	public function update_run_state( int $id, ?string $cursor_ts, string $last_run_at, ?string $next_run_at ): void {
		global $wpdb;
		$wpdb->update( self::table(), array(
			'cursor_ts'   => $cursor_ts,
			'last_run_at' => $last_run_at,
			'next_run_at' => $next_run_at,
			'updated_at'  => current_time( 'mysql', true ),
		), array( 'id' => $id ) );
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ) );
	}

	/** Purge totale des recherches d'un candidat (RGPD). Retourne les IDs supprimés. @return int[] */
	public function delete_for_candidate( int $candidate_user_id ): array {
		global $wpdb;
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE candidate_user_id = %d',
			$candidate_user_id
		) ) );
		if ( $ids ) {
			$wpdb->delete( self::table(), array( 'candidate_user_id' => $candidate_user_id ) );
		}
		return $ids;
	}

	/**
	 * Lot de recherches échues (alerte active, next_run_at atteint). Sélection bornée et indexée
	 * (KEY due) — ne scanne jamais toutes les recherches.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function due_batch( string $now_utc, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table()
			. ' WHERE alert_frequency <> %s AND next_run_at IS NOT NULL AND next_run_at <= %s'
			. ' ORDER BY next_run_at ASC LIMIT %d',
			self::FREQ_DISABLED,
			$now_utc,
			max( 1, $limit )
		), ARRAY_A );
		return $rows ?: array();
	}

	// --- Agrégats supervision admin (privacy-first : compteurs seulement) ----

	public function count_all(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}

	public function count_active_all(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE alert_frequency <> %s', self::FREQ_DISABLED ) );
	}
}
