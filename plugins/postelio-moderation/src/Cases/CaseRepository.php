<?php
/**
 * Persistance des cases de modération. Une seule case ACTIVE par ressource.
 *
 * @package Postelio\Moderation\Cases
 */

namespace Postelio\Moderation\Cases;

use Postelio\Moderation\Reports\ReasonCodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CaseRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_moderation_cases';
	}

	/** @param array<string, mixed> $data */
	public function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::table(),
			array(
				'public_uuid'   => $this->unique_uuid(),
				'resource_type' => (string) $data['resource_type'],
				'resource_uuid' => (string) $data['resource_uuid'],
				'status'        => CaseStateMachine::OPEN,
				'priority'      => (string) ( $data['priority'] ?? 'medium' ),
				'risk_level'    => (string) ( $data['risk_level'] ?? 'medium' ),
				'origin'        => (string) ( $data['origin'] ?? 'report' ),
				'reports_count' => (int) ( $data['reports_count'] ?? 0 ),
				'opened_at'     => $now,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Case active (open|in_review|escalated) pour une ressource, ou null. @return array<string,mixed>|null */
	public function active_for_resource( string $resource_type, string $resource_uuid ): ?array {
		global $wpdb;
		$in  = implode( ',', array_fill( 0, count( CaseStateMachine::ACTIVE ), '%s' ) );
		$sql = 'SELECT * FROM ' . self::table() . " WHERE resource_type = %s AND resource_uuid = %s AND status IN ($in) ORDER BY id DESC LIMIT 1";
		$row = $wpdb->get_row( $wpdb->prepare( $sql, array_merge( array( $resource_type, $resource_uuid ), CaseStateMachine::ACTIVE ) ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
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

	public function bump_priority_if_higher( int $id, string $priority ): void {
		$c = $this->get( $id );
		if ( null === $c ) {
			return;
		}
		if ( ReasonCodes::rank( $priority ) > ReasonCodes::rank( (string) $c['priority'] ) ) {
			$this->update( $id, array( 'priority' => $priority ) );
		}
	}

	public function increment_reports( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET reports_count = reports_count + 1, updated_at = %s WHERE id = %d', current_time( 'mysql', true ), $id ) );
	}

	/** @param array<string, mixed> $fields */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) );
	}

	public function set_status( int $id, string $status ): void {
		$extra = array( 'status' => $status );
		if ( in_array( $status, array( CaseStateMachine::RESOLVED, CaseStateMachine::DISMISSED ), true ) ) {
			$extra['resolved_at'] = current_time( 'mysql', true );
		}
		$this->update( $id, $extra );
	}

	public function assign( int $id, int $moderator_id ): void {
		$this->update( $id, array( 'assigned_to' => $moderator_id, 'assigned_at' => current_time( 'mysql', true ) ) );
	}

	/**
	 * Liste paginée + filtres. @param array<string,mixed> $filters
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	public function list( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$where = '1=1';
		$args  = array();
		if ( ! empty( $filters['status'] ) && CaseStateMachine::is_status( (string) $filters['status'] ) ) {
			$where .= ' AND status = %s';
			$args[] = (string) $filters['status'];
		}
		if ( ! empty( $filters['priority'] ) ) {
			$where .= ' AND priority = %s';
			$args[] = (string) $filters['priority'];
		}
		if ( ! empty( $filters['resource_type'] ) ) {
			$where .= ' AND resource_type = %s';
			$args[] = (string) $filters['resource_type'];
		}
		$count = $args ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where}", $args ) ) : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where}" );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$sql    = 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY FIELD(priority,'critical','high','medium','low'), id DESC LIMIT %d OFFSET %d";
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A );
		return array( 'items' => array_map( array( $this, 'decode' ), $rows ?: array() ), 'total' => $count );
	}

	/** @return array<string,mixed> */
	private function decode( array $row ): array {
		$row['id']            = (int) $row['id'];
		$row['reports_count'] = (int) $row['reports_count'];
		$row['assigned_to']   = null !== $row['assigned_to'] ? (int) $row['assigned_to'] : null;
		return $row;
	}

	private function unique_uuid(): string {
		do {
			$uuid = wp_generate_uuid4();
		} while ( null !== $this->get_by_uuid( $uuid ) );
		return $uuid;
	}
}
