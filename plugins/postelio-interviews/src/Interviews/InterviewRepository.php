<?php
/**
 * Accès aux entretiens (`wp_postelio_interviews`). Aucune règle métier ici : uniquement
 * la persistance. Les colonnes JSON (`location_data`/`video_data`/`phone_data`) sont
 * encodées/décodées ici. Les IDs internes ne sortent jamais via l'API (présentation).
 *
 * @package Postelio\Interviews\Interviews
 */

namespace Postelio\Interviews\Interviews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class InterviewRepository {

	private const JSON_COLS = array( 'location_data', 'video_data', 'phone_data' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_interviews';
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::table(),
			array(
				'public_uuid'       => $this->unique_uuid(),
				'application_id'    => (int) $data['application_id'],
				'application_uuid'  => (string) $data['application_uuid'],
				'job_uuid'          => $data['job_uuid'] ?? null,
				'candidate_user_id' => (int) $data['candidate_user_id'],
				'company_id'        => (int) $data['company_id'],
				'company_uuid'      => $data['company_uuid'] ?? null,
				'created_by'        => (int) $data['created_by'],
				'type'              => (string) $data['type'],
				'status'            => (string) ( $data['status'] ?? 'proposed' ),
				'scheduled_at'      => (string) $data['scheduled_at'],
				'duration_minutes'  => (int) $data['duration_minutes'],
				'timezone'          => (string) $data['timezone'],
				'location_data'     => isset( $data['location_data'] ) ? wp_json_encode( $data['location_data'] ) : null,
				'video_data'        => isset( $data['video_data'] ) ? wp_json_encode( $data['video_data'] ) : null,
				'phone_data'        => isset( $data['phone_data'] ) ? wp_json_encode( $data['phone_data'] ) : null,
				'instructions'      => $data['instructions'] ?? null,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Mise à jour partielle (les clés JSON sont ré-encodées). Rafraîchit `updated_at`.
	 *
	 * @param array<string, mixed> $fields
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		foreach ( self::JSON_COLS as $c ) {
			if ( array_key_exists( $c, $fields ) && null !== $fields[ $c ] && ! is_string( $fields[ $c ] ) ) {
				$fields[ $c ] = wp_json_encode( $fields[ $c ] );
			}
		}
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) );
	}

	public function set_status( int $id, string $status ): void {
		$this->update( $id, array( 'status' => $status ) );
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

	/** Un entretien actif (non terminal) existe-t-il déjà pour cette candidature ? */
	public function has_active_for_application( int $application_id ): bool {
		global $wpdb;
		$states = InterviewStateMachine::ACTIVE;
		$in     = implode( ',', array_fill( 0, count( $states ), '%s' ) );
		$sql    = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE application_id = %d AND status IN ($in)";
		$args   = array_merge( array( $application_id ), $states );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) > 0;
	}

	/**
	 * Liste paginée + filtres (status, from/to date UTC). `$scope` = 'candidate'|'company'.
	 *
	 * @param array<string, mixed> $filters
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	public function list( string $scope, int $owner_id, array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$col   = 'candidate' === $scope ? 'candidate_user_id' : 'company_id';
		$where = "{$col} = %d";
		$args  = array( $owner_id );

		if ( ! empty( $filters['status'] ) && InterviewStateMachine::is_status( (string) $filters['status'] ) ) {
			$where .= ' AND status = %s';
			$args[] = (string) $filters['status'];
		}
		if ( ! empty( $filters['from'] ) ) {
			$where .= ' AND scheduled_at >= %s';
			$args[] = (string) $filters['from'];
		}
		if ( ! empty( $filters['to'] ) ) {
			$where .= ' AND scheduled_at <= %s';
			$args[] = (string) $filters['to'];
		}
		if ( ! empty( $filters['application_uuid'] ) ) {
			$where .= ' AND application_uuid = %s';
			$args[] = (string) $filters['application_uuid'];
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where}", $args ) );

		$offset = max( 0, ( $page - 1 ) * $per_page );
		$sql    = 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY scheduled_at ASC, id ASC LIMIT %d OFFSET %d";
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A );

		return array(
			'items' => array_map( array( $this, 'decode' ), $rows ?: array() ),
			'total' => $total,
		);
	}

	public function delete_for_application( int $application_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'application_id' => $application_id ), array( '%d' ) );
	}

	/** @return array<string, mixed> */
	private function decode( array $row ): array {
		$row['id']                = (int) $row['id'];
		$row['application_id']     = (int) $row['application_id'];
		$row['candidate_user_id']  = (int) $row['candidate_user_id'];
		$row['company_id']         = (int) $row['company_id'];
		$row['created_by']         = (int) $row['created_by'];
		$row['duration_minutes']   = (int) $row['duration_minutes'];
		foreach ( self::JSON_COLS as $c ) {
			$row[ $c ] = ( isset( $row[ $c ] ) && '' !== $row[ $c ] && null !== $row[ $c ] )
				? json_decode( (string) $row[ $c ], true )
				: null;
		}
		return $row;
	}

	private function unique_uuid(): string {
		do {
			$uuid = wp_generate_uuid4();
		} while ( null !== $this->get_by_uuid( $uuid ) );
		return $uuid;
	}
}
