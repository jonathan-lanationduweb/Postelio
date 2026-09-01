<?php
/**
 * Vue API d'une recherche sauvegardée. N'expose JAMAIS l'id SQL, le candidate_user_id, le
 * filters_hash ni le curseur interne : uniquement l'UUID public et les champs utiles au candidat.
 *
 * @package Postelio\Alerts\Searches
 */

namespace Postelio\Alerts\Searches;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SavedSearchPresenter {

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function view( array $row ): array {
		$filters = json_decode( (string) ( $row['filters'] ?? '' ), true );
		return array(
			'uuid'            => (string) ( $row['public_uuid'] ?? '' ),
			'name'            => (string) ( $row['name'] ?? '' ),
			'filters'         => is_array( $filters ) ? $filters : array(),
			'alert_frequency' => (string) ( $row['alert_frequency'] ?? 'disabled' ),
			'alert_active'    => 'disabled' !== (string) ( $row['alert_frequency'] ?? 'disabled' ),
			'timezone'        => (string) ( $row['timezone'] ?? 'Europe/Paris' ),
			'last_run_at'     => self::dt( $row['last_run_at'] ?? null ),
			'next_run_at'     => self::dt( $row['next_run_at'] ?? null ),
			'created_at'      => self::dt( $row['created_at'] ?? null ),
			'updated_at'      => self::dt( $row['updated_at'] ?? null ),
		);
	}

	/** @param mixed $v */
	private static function dt( $v ): ?string {
		$v = (string) $v;
		return ( '' === $v || '0000-00-00 00:00:00' === $v ) ? null : $v;
	}
}
