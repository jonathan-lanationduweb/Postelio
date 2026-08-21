<?php
/**
 * Messages (`wp_postelio_messages`). Immuables (D6) : jamais d'UPDATE du `body`.
 * Ordre déterministe `(created_at, id)` ; pagination par curseur `before` (id interne,
 * non exposé — le curseur public est l'UUID d'un message).
 *
 * @package Postelio\Messaging\Conversations
 */

namespace Postelio\Messaging\Conversations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MessageRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_messages';
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'public_uuid'    => $this->unique_uuid(),
				'conversation_id' => (int) $data['conversation_id'],
				'sender_user_id' => (int) $data['sender_user_id'],
				'sender_role'    => (string) $data['sender_role'],
				'body'           => (string) $data['body'],
				'status'         => 'sent',
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
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
	 * Page de messages (curseur `before_id` exclusif). Retourne l'ordre chronologique
	 * ASC + s'il reste des messages plus anciens.
	 *
	 * @return array{items: array<int, array<string,mixed>>, has_more:bool}
	 */
	public function page( int $conversation_id, int $before_id, int $limit ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		$where = 'conversation_id = %d';
		$args  = array( $conversation_id );
		if ( $before_id > 0 ) {
			$where .= ' AND id < %d';
			$args[] = $before_id;
		}
		// On récupère limit+1 en DESC pour savoir s'il reste des plus anciens.
		$sql  = 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY id DESC LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql . '', array_merge( $args, array( $limit + 1 ) ) ), ARRAY_A );
		$rows = $rows ?: array();
		$has_more = count( $rows ) > $limit;
		if ( $has_more ) {
			array_pop( $rows );
		}
		$rows = array_reverse( $rows ); // chronologique ASC
		return array( 'items' => array_map( array( $this, 'decode' ), $rows ), 'has_more' => $has_more );
	}

	/** Non lus = messages d'id supérieur au curseur de lecture, non émis par l'utilisateur. */
	public function count_unread_for( int $conversation_id, int $user_id, int $since_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . " WHERE conversation_id = %d AND sender_user_id <> %d AND status = 'sent' AND id > %d",
				$conversation_id,
				$user_id,
				$since_id
			)
		);
	}

	public function max_id( int $conversation_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id),0) FROM ' . self::table() . ' WHERE conversation_id = %d', $conversation_id ) );
	}

	public function delete_for_conversation( int $conversation_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'conversation_id' => $conversation_id ), array( '%d' ) );
	}

	/** @return array<string, mixed> */
	private function decode( array $row ): array {
		$row['id']              = (int) $row['id'];
		$row['conversation_id'] = (int) $row['conversation_id'];
		$row['sender_user_id']  = (int) $row['sender_user_id'];
		return $row;
	}

	private function unique_uuid(): string {
		do {
			$uuid = wp_generate_uuid4();
		} while ( null !== $this->get_by_uuid( $uuid ) );
		return $uuid;
	}
}
