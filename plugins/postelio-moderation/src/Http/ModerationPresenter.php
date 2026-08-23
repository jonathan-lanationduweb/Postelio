<?php
/**
 * Présentation API. Case (vue modérateur/admin) : UUID uniquement, notes internes visibles
 * SEULEMENT dans la file admin. Report (vue utilisateur) : statut générique, jamais de note,
 * d'identité modérateur, de reporter tiers ni d'ID SQL.
 *
 * @package Postelio\Moderation\Http
 */

namespace Postelio\Moderation\Http;

use Postelio\Moderation\Reports\ReportRepository;
use Postelio\Users\Api\UserDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ModerationPresenter {

	/**
	 * Vue case pour modérateur/admin (file interne).
	 *
	 * @param array<string,mixed>              $case
	 * @param array<int,array<string,mixed>>   $events
	 * @return array<string,mixed>
	 */
	public static function case_view( array $case, array $events = array() ): array {
		return array(
			'uuid'          => (string) $case['public_uuid'],
			'resource_type' => (string) $case['resource_type'],
			'resource_uuid' => (string) $case['resource_uuid'],
			'status'        => (string) $case['status'],
			'priority'      => (string) $case['priority'],
			'risk_level'    => (string) $case['risk_level'],
			'origin'        => (string) $case['origin'],
			'assigned_to'   => ! empty( $case['assigned_to'] ) ? UserDirectory::display_name( (int) $case['assigned_to'] ) : null,
			'reports_count' => (int) $case['reports_count'],
			'opened_at'     => self::iso( (string) $case['opened_at'] ),
			'resolved_at'   => self::iso( (string) ( $case['resolved_at'] ?? '' ) ),
			'events'        => array_map( array( self::class, 'event_view' ), $events ),
		);
	}

	/** @param array<string,mixed> $e @return array<string,mixed> */
	public static function event_view( array $e ): array {
		return array(
			'event'        => (string) $e['event'],
			'actor_role'   => $e['actor_role'] ?? null,
			'decision'     => $e['decision'] ?? null,
			'action'       => $e['action'] ?? null,
			'reason_codes' => is_array( $e['reason_codes'] ?? null ) ? $e['reason_codes'] : array(),
			'from'         => $e['from_state'] ?? null,
			'to'           => $e['to_state'] ?? null,
			'note'         => $e['note'] ?? null, // interne — visible file admin uniquement
			'at'           => self::iso( (string) $e['created_at'] ),
		);
	}

	/**
	 * Vue report pour l'utilisateur (ses propres signalements).
	 *
	 * @param array<string,mixed>      $report
	 * @param array<string,mixed>|null $case
	 * @return array<string,mixed>
	 */
	public static function report_user_view( array $report, ?array $case ): array {
		return array(
			'uuid'          => (string) $report['public_uuid'],
			'resource_type' => (string) $report['resource_type'],
			'reason_code'   => (string) $report['reason_code'],
			'status'        => ReportRepository::public_status( $report, $case ),
			'created_at'    => self::iso( (string) $report['created_at'] ),
		);
	}

	private static function iso( string $mysql_utc ): ?string {
		if ( '' === $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return null;
		}
		return str_replace( ' ', 'T', $mysql_utc ) . 'Z';
	}
}
