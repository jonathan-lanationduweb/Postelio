<?php
/**
 * Accès DB aux fichiers (`wp_postelio_files`).
 *
 * @package Postelio\Files\Files
 */

namespace Postelio\Files\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FileRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_files';
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
				'public_uuid'      => $this->unique_uuid(),
				'owner_user_id'    => (int) $data['owner_user_id'],
				'type'             => (string) ( $data['type'] ?? 'cv' ),
				'storage_provider' => (string) ( $data['storage_provider'] ?? 'local' ),
				'storage_key'      => (string) $data['storage_key'],
				'original_name'    => isset( $data['original_name'] ) ? (string) $data['original_name'] : null,
				'stored_name'      => (string) $data['stored_name'],
				'mime_type'        => (string) $data['mime_type'],
				'size_bytes'       => (int) $data['size_bytes'],
				'sha256'           => isset( $data['sha256'] ) ? (string) $data['sha256'] : null,
				'status'           => (string) ( $data['status'] ?? 'ready' ),
				'is_primary'       => ! empty( $data['is_primary'] ) ? 1 : 0,
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
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
	 * @param string[] $statuses
	 * @return array<int, array<string,mixed>>
	 */
	public function list_for_owner( int $owner, string $type, array $statuses ): array {
		global $wpdb;
		$in  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql = 'SELECT * FROM ' . self::table() . ' WHERE owner_user_id = %d AND type = %s AND status IN (' . $in . ') ORDER BY is_primary DESC, id DESC';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $owner, $type ), $statuses ) ), ARRAY_A );
		return array_map( array( $this, 'decode' ), $rows ?: array() );
	}

	public function count_for_owner( int $owner, string $type, array $statuses ): int {
		global $wpdb;
		$in  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE owner_user_id = %d AND type = %s AND status IN (' . $in . ')';
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( $owner, $type ), $statuses ) ) );
	}

	public function set_primary( int $owner, string $type, int $file_id ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET is_primary = 0, updated_at = %s WHERE owner_user_id = %d AND type = %s', $now, $owner, $type ) );
		$wpdb->update( self::table(), array( 'is_primary' => 1, 'updated_at' => $now ), array( 'id' => $file_id ), array( '%d', '%s' ), array( '%d' ) );
	}

	public function update_status( int $id, string $status, ?string $deleted_at = null ): void {
		global $wpdb;
		$data    = array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) );
		$formats = array( '%s', '%s' );
		if ( 'deleted' === $status || null !== $deleted_at ) {
			$data['deleted_at'] = $deleted_at ?? current_time( 'mysql', true );
			$formats[]          = '%s';
		}
		if ( in_array( $status, array( 'deleted', 'archived' ), true ) ) {
			$data['is_primary'] = 0;
			$formats[]          = '%d';
		}
		$wpdb->update( self::table(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	public function hard_delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/** @return array<string, mixed> */
	private function decode( array $row ): array {
		$row['id']            = (int) $row['id'];
		$row['owner_user_id'] = (int) $row['owner_user_id'];
		$row['size_bytes']    = (int) $row['size_bytes'];
		$row['is_primary']    = (bool) $row['is_primary'];
		return $row;
	}

	private function unique_uuid(): string {
		do {
			$uuid = wp_generate_uuid4();
		} while ( null !== $this->get_by_uuid( $uuid ) );
		return $uuid;
	}
}
