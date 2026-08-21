<?php
/**
 * Accès aux notifications in-app (`wp_postelio_notifications`). Idempotence garantie par
 * `dedup_key` UNIQUE : un insert en doublon est ignoré (retourne 0). Aucun ID interne
 * n'est exposé (l'API utilise `public_uuid`).
 *
 * @package Postelio\Notifications\Notifications
 */

namespace Postelio\Notifications\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NotificationRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_notifications';
	}

	/**
	 * Insère une notification. Retourne l'id, ou 0 si un doublon (dedup_key) existe déjà.
	 *
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$prev = $wpdb->suppress_errors( true );
		$ok   = $wpdb->insert(
			self::table(),
			array(
				'public_uuid'    => $this->unique_uuid(),
				'user_id'        => (int) $data['user_id'],
				'type'           => (string) $data['type'],
				'event_name'     => (string) $data['event_name'],
				'priority'       => (string) ( $data['priority'] ?? 'normal' ),
				'title'          => (string) $data['title'],
				'body'           => $data['body'] ?? null,
				'resource_type'  => $data['resource_type'] ?? null,
				'resource_uuid'  => $data['resource_uuid'] ?? null,
				'action_type'    => $data['action_type'] ?? null,
				'action_payload' => isset( $data['action_payload'] ) ? wp_json_encode( $data['action_payload'] ) : null,
				'group_key'      => $data['group_key'] ?? null,
				'dedup_key'      => (string) $data['dedup_key'],
				'expires_at'     => $data['expires_at'] ?? null,
				'created_at'     => current_time( 'mysql', true ),
			)
		);
		$wpdb->suppress_errors( $prev );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** @return array<string, mixed>|null */
	public function get_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE public_uuid = %s', $uuid ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	/**
	 * Liste paginée d'un utilisateur (filtres unread, type).
	 *
	 * @param array<string, mixed> $filters
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	public function list_for_user( int $user_id, array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$where = 'user_id = %d';
		$args  = array( $user_id );
		if ( ! empty( $filters['unread'] ) ) {
			// « Non lu » côté badge = non lu ET non résolu ET non expiré (même sémantique
			// que unread_count). L'historique complet reste visible sans ce filtre.
			$where .= ' AND read_at IS NULL AND resolved_at IS NULL AND ( expires_at IS NULL OR expires_at > %s )';
			$args[] = current_time( 'mysql', true );
		}
		if ( ! empty( $filters['type'] ) ) {
			$where .= ' AND type = %s';
			$args[] = (string) $filters['type'];
		}
		$total  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where}", $args ) );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$rows   = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, $offset ) ) ),
			ARRAY_A
		);
		return array( 'items' => array_map( array( $this, 'decode' ), $rows ?: array() ), 'total' => $total );
	}

	/**
	 * Compteur de la cloche : notifications **non lues ET non résolues ET non expirées**.
	 * Une notification résolue (action devenue caduque) ou expirée ne gonfle pas le badge,
	 * mais reste consultable dans la liste.
	 */
	public function unread_count( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . ' WHERE user_id = %d AND read_at IS NULL AND resolved_at IS NULL AND ( expires_at IS NULL OR expires_at > %s )',
				$user_id,
				current_time( 'mysql', true )
			)
		);
	}

	/** Marque lue une notification de l'utilisateur. Retourne true si une ligne a changé. */
	public function mark_read( string $uuid, int $user_id ): bool {
		global $wpdb;
		$n = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET read_at = %s WHERE public_uuid = %s AND user_id = %d AND read_at IS NULL',
				current_time( 'mysql', true ),
				$uuid,
				$user_id
			)
		);
		return (int) $n > 0;
	}

	public function mark_all_read( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare( 'UPDATE ' . self::table() . ' SET read_at = %s WHERE user_id = %d AND read_at IS NULL', current_time( 'mysql', true ), $user_id )
		);
	}

	/**
	 * Résout (lu + resolved) les notifications actives d'un groupe pour un utilisateur —
	 * utilisé quand l'action requise devient caduque (ex. conversation lue).
	 */
	public function resolve_group( int $user_id, string $group_key ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET resolved_at = %s, read_at = COALESCE(read_at, %s) WHERE user_id = %d AND group_key = %s AND resolved_at IS NULL',
				$now,
				$now,
				$user_id,
				$group_key
			)
		);
	}

	public function delete_for_user( int $user_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/** @return array<string, mixed> */
	private function decode( array $row ): array {
		$row['id']             = (int) $row['id'];
		$row['user_id']        = (int) $row['user_id'];
		$row['action_payload'] = ( isset( $row['action_payload'] ) && '' !== $row['action_payload'] && null !== $row['action_payload'] )
			? json_decode( (string) $row['action_payload'], true )
			: null;
		return $row;
	}

	private function unique_uuid(): string {
		do {
			$uuid = wp_generate_uuid4();
		} while ( null !== $this->get_by_uuid( $uuid ) );
		return $uuid;
	}
}
