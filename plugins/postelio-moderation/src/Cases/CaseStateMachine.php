<?php
/**
 * Machine à états canonique d'une case de modération (V1). Logique PURE (testable hors WP).
 *
 * @package Postelio\Moderation\Cases
 */

namespace Postelio\Moderation\Cases;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_MODERATION_TESTING' ) ) {
		exit;
	}
}

final class CaseStateMachine {

	public const OPEN      = 'open';
	public const IN_REVIEW = 'in_review';
	public const RESOLVED  = 'resolved';
	public const DISMISSED = 'dismissed';
	public const ESCALATED = 'escalated';

	/** Une case « active » (une seule par ressource). */
	public const ACTIVE = array( self::OPEN, self::IN_REVIEW, self::ESCALATED );

	/** @var array<string, string[]> */
	private const TRANSITIONS = array(
		self::OPEN      => array( self::IN_REVIEW, self::ESCALATED ),
		self::IN_REVIEW => array( self::RESOLVED, self::DISMISSED, self::ESCALATED ),
		self::ESCALATED => array( self::IN_REVIEW ),
		self::RESOLVED  => array(),
		self::DISMISSED => array(),
	);

	/** @return string[] */
	public static function all(): array {
		return array_keys( self::TRANSITIONS );
	}

	public static function is_status( string $s ): bool {
		return isset( self::TRANSITIONS[ $s ] );
	}

	public static function is_active( string $s ): bool {
		return in_array( $s, self::ACTIVE, true );
	}

	public static function is_terminal( string $s ): bool {
		return self::is_status( $s ) && array() === self::TRANSITIONS[ $s ];
	}

	public static function can_transition( string $from, string $to ): bool {
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}
}
