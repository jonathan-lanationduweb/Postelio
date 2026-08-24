<?php
/**
 * Persistance des commentaires (« Avis » V1) — table dédiée `wp_postelio_skill_comments`.
 * Soft-delete, UUID public, statut published/hidden/deleted. AUCUN pending.
 *
 * @package Postelio\Skills\Comments
 */

namespace Postelio\Skills\Comments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CommentRepository {

	public const PUBLISHED = 'published';
	public const HIDDEN    = 'hidden';
	public const DELETED   = 'deleted';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_skill_comments';
	}

	/** @param array<string,mixed> $data @return int id */
	public function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert( self::table(), array(
			'public_uuid'    => $this->unique_uuid(),
			'skill_id'       => (int) $data['skill_id'],
			'skill_uuid'     => (string) $data['skill_uuid'],
			'author_user_id' => (int) $data['author_user_id'],
			'author_role'    => (string) ( $data['author_role'] ?? '' ),
			'body'           => (string) $data['body'],
			'status'         => (string) ( $data['status'] ?? self::PUBLISHED ),
			'created_at'     => $now,
			'updated_at'     => $now,
		) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** @return array{items:array<int,array<string,mixed>>, total:int} */
	public function list_published_for_skill( int $skill_id, int $page, int $per_page ): array {
		global $wpdb;
		$table  = self::table();
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE skill_id = %d AND status = %s", $skill_id, self::PUBLISHED ) );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE skill_id = %d AND status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $skill_id, self::PUBLISHED, $per_page, $offset ), ARRAY_A );
		return array( 'items' => array_map( array( $this, 'decode' ), $rows ?: array() ), 'total' => $total );
	}

	/** @return array<string,mixed>|null */
	public function get_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE public_uuid = %s', $uuid ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	public function count_recent_by_author( int $user_id, int $window_seconds ): int {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - $window_seconds );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE author_user_id = %d AND created_at >= %s', $user_id, $since ) );
	}

	public function set_status( int $id, string $status ): void {
		global $wpdb;
		$fields = array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) );
		if ( self::DELETED === $status ) {
			$fields['deleted_at'] = current_time( 'mysql', true );
		}
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) );
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function decode( array $row ): array {
		$row['id']             = (int) $row['id'];
		$row['skill_id']       = (int) $row['skill_id'];
		$row['author_user_id'] = (int) $row['author_user_id'];
		return $row;
	}

	private function unique_uuid(): string {
		global $wpdb;
		$table = self::table();
		do {
			$uuid = wp_generate_uuid4();
		} while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE public_uuid = %s", $uuid ) ) > 0 );
		return $uuid;
	}
}
