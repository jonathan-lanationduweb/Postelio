<?php
/**
 * Contrat public de LECTURE (autres plugins / admin health). Les consommateurs ne lisent
 * jamais les tables moderation directement.
 *
 * @package Postelio\Moderation\Api
 */

namespace Postelio\Moderation\Api;

use Postelio\Moderation\Cases\CaseRepository;
use Postelio\Moderation\Cases\CaseStateMachine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ModerationDirectory {

	/** Nombre de cases actives (open|in_review|escalated). */
	public static function open_cases_count(): int {
		global $wpdb;
		$table = CaseRepository::table();
		$in    = implode( ',', array_fill( 0, count( CaseStateMachine::ACTIVE ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status IN ($in)", CaseStateMachine::ACTIVE ) );
	}

	/**
	 * Statut de modération d'une ressource (case active ?).
	 *
	 * @return array{has_active_case:bool, status:?string, priority:?string}
	 */
	public static function resource_status( string $resource_type, string $resource_uuid ): array {
		$case = ( new CaseRepository() )->active_for_resource( $resource_type, $resource_uuid );
		return array(
			'has_active_case' => null !== $case,
			'status'          => $case['status'] ?? null,
			'priority'        => $case['priority'] ?? null,
		);
	}
}
