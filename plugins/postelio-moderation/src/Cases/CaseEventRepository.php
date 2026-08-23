<?php
/**
 * Historique append-only des cases (`wp_postelio_moderation_case_events`) : décisions,
 * actions, notes internes, changements d'état. Jamais de contenu litigieux complet.
 *
 * @package Postelio\Moderation\Cases
 */

namespace Postelio\Moderation\Cases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CaseEventRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_moderation_case_events';
	}

	/** @param array<string, mixed> $data */
	public function add( int $case_id, array $data ): void {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'case_id'        => $case_id,
				'actor_user_id'  => isset( $data['actor_user_id'] ) ? (int) $data['actor_user_id'] : null,
				'actor_role'     => $data['actor_role'] ?? null,
				'event'          => (string) $data['event'],
				'decision'       => $data['decision'] ?? null,
				'action'         => $data['action'] ?? null,
				'reason_codes'   => isset( $data['reason_codes'] ) ? wp_json_encode( $data['reason_codes'] ) : null,
				'from_state'     => $data['from_state'] ?? null,
				'to_state'       => $data['to_state'] ?? null,
				'note'           => isset( $data['note'] ) ? (string) $data['note'] : null,
				'policy_version' => $data['policy_version'] ?? null,
				'created_at'     => current_time( 'mysql', true ),
			)
		);
	}

	/** @return array<int, array<string,mixed>> */
	public function list_for_case( int $case_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE case_id = %d ORDER BY id ASC', $case_id ), ARRAY_A );
		return array_map(
			static function ( array $r ): array {
				$r['reason_codes'] = ( isset( $r['reason_codes'] ) && '' !== $r['reason_codes'] && null !== $r['reason_codes'] ) ? json_decode( (string) $r['reason_codes'], true ) : array();
				return $r;
			},
			$rows ?: array()
		);
	}
}
