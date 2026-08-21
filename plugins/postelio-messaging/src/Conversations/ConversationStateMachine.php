<?php
/**
 * États d'une conversation (V1) : `active`, `closed`, `archived`.
 * Fermer/archiver ne détruit JAMAIS l'historique ; seul `active` autorise l'envoi.
 *
 * @package Postelio\Messaging\Conversations
 */

namespace Postelio\Messaging\Conversations;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class ConversationStateMachine {

	public const ACTIVE   = 'active';
	public const CLOSED   = 'closed';
	public const ARCHIVED = 'archived';

	/** @var array<string, string[]> */
	private const TRANSITIONS = array(
		self::ACTIVE   => array( self::CLOSED, self::ARCHIVED ),
		self::CLOSED   => array( self::ACTIVE, self::ARCHIVED ),
		self::ARCHIVED => array( self::ACTIVE ),
	);

	/** @return string[] */
	public static function statuses(): array {
		return array_keys( self::TRANSITIONS );
	}

	public static function is_status( string $s ): bool {
		return isset( self::TRANSITIONS[ $s ] );
	}

	public static function can_send( string $status ): bool {
		return self::ACTIVE === $status;
	}

	public static function can_transition( string $from, string $to ): bool {
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}
}
