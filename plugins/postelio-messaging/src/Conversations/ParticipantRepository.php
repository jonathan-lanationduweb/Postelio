<?php
/**
 * Participants d'une conversation (`wp_postelio_conversation_participants`) : porte
 * l'état de lecture PAR utilisateur via un curseur monotone (`last_read_message_id`,
 * doublé de `last_read_at` informatif), condition d'un vrai lu/non-lu multi-participant
 * (une entreprise peut avoir plusieurs recruteurs). Le curseur par id évite l'ambiguïté
 * des comparaisons DATETIME à la même seconde (§33).
 *
 * @package Postelio\Messaging\Conversations
 */

namespace Postelio\Messaging\Conversations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ParticipantRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_conversation_participants';
	}

	/** Crée la ligne participant si absente. Idempotent (unique conv+user). */
	public function ensure( int $conversation_id, int $user_id, string $role ): void {
		global $wpdb;
		if ( null !== $this->get( $conversation_id, $user_id ) ) {
			return;
		}
		$prev = $wpdb->suppress_errors( true );
		$wpdb->insert(
			self::table(),
			array(
				'conversation_id' => $conversation_id,
				'user_id'         => $user_id,
				'role'            => $role,
				'joined_at'       => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
		$wpdb->suppress_errors( $prev );
	}

	/** @return array<string, mixed>|null */
	public function get( int $conversation_id, int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE conversation_id = %d AND user_id = %d', $conversation_id, $user_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Marque lu jusqu'au message `$up_to_message_id` (id interne monotone : ordre
	 * déterministe même à la même seconde — §33).
	 */
	public function mark_read( int $conversation_id, int $user_id, string $role, string $ts, int $up_to_message_id ): void {
		$this->ensure( $conversation_id, $user_id, $role );
		global $wpdb;
		// Ne recule jamais le curseur de lecture.
		$current = (int) ( $this->get( $conversation_id, $user_id )['last_read_message_id'] ?? 0 );
		$new     = max( $current, $up_to_message_id );
		$wpdb->update(
			self::table(),
			array( 'last_read_at' => $ts, 'last_read_message_id' => $new ),
			array( 'conversation_id' => $conversation_id, 'user_id' => $user_id ),
			array( '%s', '%d' ),
			array( '%d', '%d' )
		);
	}

	public function last_read_message_id( int $conversation_id, int $user_id ): int {
		$p = $this->get( $conversation_id, $user_id );
		return $p ? (int) ( $p['last_read_message_id'] ?? 0 ) : 0;
	}

	public function delete_for_conversation( int $conversation_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'conversation_id' => $conversation_id ), array( '%d' ) );
	}
}
