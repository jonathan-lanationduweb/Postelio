<?php
/**
 * Machine à états CANONIQUE d'une offre (docs/backend/workflows.md#offre-job).
 *
 * États : draft, pending, published, expiring, expired, renewed, filled, archived,
 * suspended. `pending` n'est atteint que si une modération d'offre est activée
 * (hors Lot 04). Le renouvellement (`expired → renewed → published`) passe par un
 * paiement (postelio-billing) — hors Lot 04.
 *
 * Classe pure → testable en isolation.
 *
 * @package Postelio\Jobs\Jobs
 */

namespace Postelio\Jobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class JobStateMachine {

	public const DRAFT     = 'draft';
	public const PENDING   = 'pending';
	public const PUBLISHED = 'published';
	public const EXPIRING  = 'expiring';
	public const EXPIRED   = 'expired';
	public const RENEWED   = 'renewed';
	public const FILLED    = 'filled';
	public const ARCHIVED  = 'archived';
	public const SUSPENDED = 'suspended';

	/** @var array<string, string[]> */
	private const TRANSITIONS = array(
		self::DRAFT     => array( self::PENDING, self::PUBLISHED, self::ARCHIVED ),
		self::PENDING   => array( self::PUBLISHED, self::DRAFT, self::ARCHIVED ),
		self::PUBLISHED => array( self::EXPIRING, self::EXPIRED, self::FILLED, self::ARCHIVED, self::SUSPENDED ),
		self::EXPIRING  => array( self::EXPIRED, self::FILLED, self::ARCHIVED, self::SUSPENDED ),
		self::EXPIRED   => array( self::RENEWED, self::ARCHIVED ),
		self::RENEWED   => array( self::PUBLISHED ),
		self::FILLED    => array( self::ARCHIVED ),
		self::ARCHIVED  => array(), // terminal (recréer via duplication)
		self::SUSPENDED => array( self::PUBLISHED, self::ARCHIVED ),
	);

	/** États visibles PUBLIQUEMENT (listing / fiche publique). */
	private const PUBLIC_STATES = array( self::PUBLISHED, self::EXPIRING );

	/** @return string[] */
	public static function statuses(): array {
		return array_keys( self::TRANSITIONS );
	}

	public static function is_status( string $s ): bool {
		return isset( self::TRANSITIONS[ $s ] );
	}

	public static function can_transition( string $from, string $to ): bool {
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}

	/** @return string[] */
	public static function allowed_from( string $from ): array {
		return self::TRANSITIONS[ $from ] ?? array();
	}

	public static function is_public( string $status ): bool {
		return in_array( $status, self::PUBLIC_STATES, true );
	}
}
