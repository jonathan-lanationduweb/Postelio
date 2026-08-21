<?php
/**
 * Accès DB aux conversations (`wp_postelio_conversations`).
 *
 * @package Postelio\Messaging\Conversations
 */

namespace Postelio\Messaging\Conversations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ConversationRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_conversations';
	}

	/**
	 * @param array<string, mixed> $data
	 * @return int ID, ou 0 si une conversation existe déjà pour cette candidature.
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$prev = $wpdb->suppress_errors( true ); // la contrainte unique(application_id) est un résultat normal
		$ok   = $wpdb->insert(
			self::table(),
			array(
				'public_uuid'       => $this->unique_uuid(),
				'type'              => (string) ( $data['type'] ?? 'application' ),
				'application_id'    => (int) $data['application_id'],
				'application_uuid'  => (string) $data['application_uuid'],
				'job_uuid'          => isset( $data['job_uuid'] ) ? (string) $data['job_uuid'] : null,
				'company_id'        => (int) $data['company_id'],
				'company_uuid'      => isset( $data['company_uuid'] ) ? (string) $data['company_uuid'] : null,
				'company_name'      => isset( $data['company_name'] ) ? (string) $data['company_name'] : null,
				'subject'           => isset( $data['subject'] ) ? (string) $data['subject'] : null,
				'candidate_user_id' => (int) $data['candidate_user_id'],
				'status'            => ConversationStateMachine::ACTIVE,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $prev );
		return false !== $ok ? (int) $wpdb->insert_id : 0;
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

	/** @return array<string, mixed>|null */
	public function get_by_application( int $application_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE application_id = %d', $application_id ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	public function set_status( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
	}

	public function touch_last_message( int $id, string $ts ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'last_message_at' => $ts, 'updated_at' => $ts ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public function list_for_candidate( int $candidate_id ): array {
		return $this->query( 'candidate_user_id = %d', array( $candidate_id ) );
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public function list_for_company( int $company_id ): array {
		return $this->query( 'company_id = %d', array( $company_id ) );
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * @param array<int, int> $params
	 * @return array<int, array<string,mixed>>
	 */
	private function query( string $where, array $params ): array {
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY (last_message_at IS NULL), last_message_at DESC, id DESC LIMIT 200";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return array_map( array( $this, 'decode' ), $rows ?: array() );
	}

	/** @return array<string, mixed> */
	private function decode( array $row ): array {
		$row['id']                = (int) $row['id'];
		$row['application_id']    = null !== $row['application_id'] ? (int) $row['application_id'] : null;
		$row['company_id']        = (int) $row['company_id'];
		$row['candidate_user_id'] = (int) $row['candidate_user_id'];
		return $row;
	}

	private function unique_uuid(): string {
		do {
			$uuid = wp_generate_uuid4();
		} while ( null !== $this->get_by_uuid( $uuid ) );
		return $uuid;
	}
}
