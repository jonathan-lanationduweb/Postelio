<?php
/**
 * Historique des entretiens (`wp_postelio_interview_history`). Append-only : une ligne
 * par transition. La métadonnée est **minimale** et ne contient jamais d'instructions
 * privées ni de coordonnées.
 *
 * @package Postelio\Interviews\Interviews
 */

namespace Postelio\Interviews\Interviews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class InterviewHistoryRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_interview_history';
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	public function add( int $interview_id, string $interview_uuid, ?int $actor_user_id, string $actor_role, string $action, ?string $from, ?string $to, array $metadata = array() ): void {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'interview_id'   => $interview_id,
				'interview_uuid' => $interview_uuid,
				'actor_user_id'  => $actor_user_id,
				'actor_role'     => $actor_role,
				'action'         => $action,
				'from_status'    => $from,
				'to_status'      => $to,
				'metadata'       => $metadata ? wp_json_encode( $metadata ) : null,
				'created_at'     => current_time( 'mysql', true ),
			)
		);
	}

	/** @return array<int, array<string,mixed>> */
	public function list_for_interview( int $interview_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE interview_id = %d ORDER BY id ASC', $interview_id ),
			ARRAY_A
		);
		return array_map(
			static function ( array $r ): array {
				$r['metadata'] = ( isset( $r['metadata'] ) && '' !== $r['metadata'] && null !== $r['metadata'] )
					? json_decode( (string) $r['metadata'], true )
					: array();
				return $r;
			},
			$rows ?: array()
		);
	}

	public function delete_for_interview( int $interview_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'interview_id' => $interview_id ), array( '%d' ) );
	}
}
