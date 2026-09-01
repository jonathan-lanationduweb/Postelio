<?php
/**
 * Accès aux favoris d'offres. Identité canonique (job_source, job_reference) — cf. migration.
 * Insertion IDEMPOTENTE via la contrainte UNIQUE (candidate, job_source, job_reference) :
 * ajouter deux fois le même favori ne crée jamais deux lignes.
 *
 * @package Postelio\Alerts\Favorites
 */

namespace Postelio\Alerts\Favorites;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FavoriteRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_job_favorites';
	}

	/**
	 * Insère un favori s'il n'existe pas déjà. Retourne true si NOUVEAU (ligne créée), false si
	 * déjà présent (doublon silencieux). Atomique via `INSERT IGNORE`.
	 */
	public function add( int $candidate_user_id, string $job_source, string $job_reference ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$sql = 'INSERT IGNORE INTO ' . self::table()
			. ' (public_uuid, candidate_user_id, job_source, job_reference, created_at) VALUES (%s, %d, %s, %s, %s)';
		$wpdb->query( $wpdb->prepare( $sql, wp_generate_uuid4(), $candidate_user_id, $job_source, $job_reference, $now ) ); // phpcs:ignore WordPress.DB
		return 1 === (int) $wpdb->rows_affected;
	}

	/** Supprime un favori. Retourne true si une ligne a été supprimée. */
	public function remove( int $candidate_user_id, string $job_source, string $job_reference ): bool {
		global $wpdb;
		$n = $wpdb->delete( self::table(), array(
			'candidate_user_id' => $candidate_user_id,
			'job_source'        => $job_source,
			'job_reference'     => $job_reference,
		) );
		return (int) $n > 0;
	}

	public function exists( int $candidate_user_id, string $job_source, string $job_reference ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM ' . self::table() . ' WHERE candidate_user_id = %d AND job_source = %s AND job_reference = %s',
			$candidate_user_id,
			$job_source,
			$job_reference
		) );
	}

	public function count_for_candidate( int $candidate_user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE candidate_user_id = %d', $candidate_user_id ) );
	}

	/**
	 * Liste paginée des favoris d'un candidat (plus récents d'abord).
	 *
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	public function list_for_candidate( int $candidate_user_id, int $page, int $per_page ): array {
		global $wpdb;
		$total  = $this->count_for_candidate( $candidate_user_id );
		$offset = ( max( 1, $page ) - 1 ) * max( 1, $per_page );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT public_uuid, job_source, job_reference, created_at FROM ' . self::table()
				. ' WHERE candidate_user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
				$candidate_user_id,
				max( 1, $per_page ),
				max( 0, $offset )
			),
			ARRAY_A
		);
		return array( 'items' => $rows ?: array(), 'total' => $total );
	}

	/** Purge totale des favoris d'un candidat (RGPD / suppression de compte). */
	public function delete_for_candidate( int $candidate_user_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), array( 'candidate_user_id' => $candidate_user_id ) );
	}

	/** Total des favoris (supervision admin, agrégé). */
	public function count_all(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}
}
