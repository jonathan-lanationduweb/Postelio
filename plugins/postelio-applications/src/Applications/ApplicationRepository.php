<?php
/**
 * Accès DB aux candidatures (`wp_postelio_applications`).
 *
 * @package Postelio\Applications\Applications
 */

namespace Postelio\Applications\Applications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApplicationRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_applications';
	}

	/**
	 * Insère une candidature. Retourne l'ID, ou 0 si un doublon (contrainte unique
	 * job/candidat) empêche l'insertion (concurrence gérée par la base).
	 *
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$row = array(
			'public_uuid'       => $this->unique_uuid(),
			'candidate_user_id' => (int) $data['candidate_user_id'],
			'job_id'            => (int) $data['job_id'],
			'job_uuid'          => (string) $data['job_uuid'],
			'company_id'        => (int) $data['company_id'],
			'company_uuid'      => (string) $data['company_uuid'],
			'status'            => (string) $data['status'],
			'cv_reference'      => isset( $data['cv_reference'] ) ? (string) $data['cv_reference'] : null,
			'job_revision'      => (int) $data['job_revision'],
			'job_snapshot'      => wp_json_encode( $data['job_snapshot'] ?? array() ),
			'screening_answers' => wp_json_encode( $data['screening_answers'] ?? array() ),
			'candidate_message' => isset( $data['candidate_message'] ) ? (string) $data['candidate_message'] : null,
			'sort_order'        => 0,
			'source'            => isset( $data['source'] ) ? (string) $data['source'] : 'web',
			'created_at'        => $now,
			'updated_at'        => $now,
		);
		// La violation de la contrainte unique (job_id, candidate_user_id) est un
		// résultat NORMAL (doublon/concurrence) : on masque l'erreur SQL attendue et
		// on la traduit par un retour 0 (le service lève alors `conflict`).
		$prev = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			self::table(),
			$row,
			array( '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $prev );
		return false !== $ok ? (int) $wpdb->insert_id : 0;
	}

	public function exists_for_job_candidate( int $job_id, int $candidate_user_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE job_id = %d AND candidate_user_id = %d', $job_id, $candidate_user_id )
		);
	}

	/** @return array<string, mixed>|null */
	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	/** @return array<string, mixed>|null */
	public function get_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE public_uuid = %s', $uuid ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	/**
	 * Liste candidat (ses candidatures).
	 *
	 * @param array<string, mixed> $filters
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	public function list_for_candidate( int $candidate_user_id, array $filters, int $page, int $per_page ): array {
		return $this->query( array( 'candidate_user_id' => $candidate_user_id ), $filters, $page, $per_page, 'created_at DESC' );
	}

	/**
	 * Liste entreprise (pipeline recruteur).
	 *
	 * @param array<string, mixed> $filters
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	public function list_for_company( int $company_id, array $filters, int $page, int $per_page ): array {
		return $this->query( array( 'company_id' => $company_id ), $filters, $page, $per_page, 'sort_order ASC, created_at DESC' );
	}

	/** @param array<string, mixed> $extra */
	public function update_status( int $id, string $status, array $extra = array() ): void {
		global $wpdb;
		$data    = array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) );
		$formats = array( '%s', '%s' );
		if ( array_key_exists( 'withdrawn_at', $extra ) ) {
			$data['withdrawn_at'] = $extra['withdrawn_at'];
			$formats[]            = '%s';
		}
		if ( array_key_exists( 'sort_order', $extra ) ) {
			$data['sort_order'] = (int) $extra['sort_order'];
			$formats[]          = '%d';
		}
		$wpdb->update( self::table(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	// --- Interne -----------------------------------------------------------

	/**
	 * @param array<string, int|string> $base
	 * @param array<string, mixed>      $filters
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	private function query( array $base, array $filters, int $page, int $per_page, string $order ): array {
		global $wpdb;
		$where  = array();
		$params = array();
		foreach ( $base as $col => $val ) {
			$where[]  = "{$col} = %d";
			$params[] = (int) $val;
		}
		if ( ! empty( $filters['status'] ) && ApplicationStateMachine::is_status( (string) $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $filters['status'];
		}
		if ( ! empty( $filters['job_id'] ) ) {
			$where[]  = 'job_id = %d';
			$params[] = (int) $filters['job_id'];
		}
		$sql_where = implode( ' AND ', $where );
		$offset    = ( max( 1, $page ) - 1 ) * $per_page;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}", $params ) );
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY {$order} LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ),
			ARRAY_A
		);
		return array( 'items' => array_map( array( $this, 'decode' ), $rows ?: array() ), 'total' => $total );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function decode( array $row ): array {
		$row['id']                = (int) $row['id'];
		$row['candidate_user_id'] = (int) $row['candidate_user_id'];
		$row['job_id']            = (int) $row['job_id'];
		$row['company_id']        = (int) $row['company_id'];
		$row['job_revision']      = (int) $row['job_revision'];
		$row['sort_order']        = (int) $row['sort_order'];
		$row['job_snapshot']      = $this->json( $row['job_snapshot'] ?? '' );
		$row['screening_answers'] = $this->json( $row['screening_answers'] ?? '' );
		return $row;
	}

	/** @return array<string, mixed> */
	private function json( $raw ): array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$d = json_decode( $raw, true );
		return is_array( $d ) ? $d : array();
	}

	private function unique_uuid(): string {
		do {
			$uuid = wp_generate_uuid4();
		} while ( null !== $this->get_by_uuid( $uuid ) );
		return $uuid;
	}
}
