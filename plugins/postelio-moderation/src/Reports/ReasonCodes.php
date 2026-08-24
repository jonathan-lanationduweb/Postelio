<?php
/**
 * Catalogue stable des reason codes + politique par type de ressource (reasons autorisés,
 * priorité par défaut). Logique PURE. Le reason_code machine n'est JAMAIS le motif public.
 *
 * @package Postelio\Moderation\Reports
 */

namespace Postelio\Moderation\Reports;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_MODERATION_TESTING' ) ) {
		exit;
	}
}

final class ReasonCodes {

	public const ALL = array(
		'spam', 'harassment', 'hate', 'sexual_content', 'discrimination', 'personal_data',
		'contact_bypass', 'fraud', 'scam', 'unsafe_link', 'malware_link', 'impersonation',
		'illegal_content', 'violence_threat', 'off_platform_payment', 'job_policy_violation',
		'expired_offer', 'broken_link', 'other',
	);

	public const PRIORITY_LOW    = 'low';
	public const PRIORITY_MEDIUM = 'medium';
	public const PRIORITY_HIGH   = 'high';
	public const PRIORITY_CRITICAL = 'critical';

	/** Reasons autorisés par type de ressource signalable. @return array<string,string[]> */
	public static function by_resource(): array {
		return array(
			'message'      => array( 'harassment', 'hate', 'sexual_content', 'discrimination', 'spam', 'contact_bypass', 'fraud', 'unsafe_link', 'other' ),
			'job'          => array( 'fraud', 'scam', 'discrimination', 'off_platform_payment', 'unsafe_link', 'impersonation', 'expired_offer', 'job_policy_violation', 'other' ),
			'external_job' => array( 'expired_offer', 'broken_link', 'fraud', 'scam', 'other' ),
			'company'      => array( 'impersonation', 'fraud', 'scam', 'illegal_content', 'other' ),
			'skill'        => array( 'hate', 'harassment', 'sexual_content', 'spam', 'personal_data', 'illegal_content', 'other' ),
			'skill_comment' => array( 'hate', 'harassment', 'sexual_content', 'spam', 'personal_data', 'illegal_content', 'other' ),
			'profile'      => array( 'hate', 'harassment', 'sexual_content', 'spam', 'personal_data', 'illegal_content', 'other' ),
		);
	}

	public static function is_resource_type( string $type ): bool {
		return isset( self::by_resource()[ $type ] );
	}

	public static function is_valid_for( string $type, string $reason ): bool {
		$map = self::by_resource();
		return isset( $map[ $type ] ) && in_array( $reason, $map[ $type ], true );
	}

	/** Priorité par défaut d'un reason (max des reports = priorité de la case). */
	public static function priority_for( string $reason ): string {
		$high     = array( 'fraud', 'scam', 'impersonation', 'violence_threat', 'malware_link', 'off_platform_payment', 'illegal_content' );
		$low      = array( 'expired_offer', 'broken_link' );
		if ( in_array( $reason, $high, true ) ) {
			return self::PRIORITY_HIGH;
		}
		if ( in_array( $reason, $low, true ) ) {
			return self::PRIORITY_LOW;
		}
		return self::PRIORITY_MEDIUM;
	}

	/** Ordre de comparaison des priorités (pour retenir le max sur une case). */
	public static function rank( string $priority ): int {
		return array( self::PRIORITY_LOW => 1, self::PRIORITY_MEDIUM => 2, self::PRIORITY_HIGH => 3, self::PRIORITY_CRITICAL => 4 )[ $priority ] ?? 2;
	}
}
