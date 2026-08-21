<?php
/**
 * Machine à états CANONIQUE d'une offre (V1) — docs/backend/workflows.md#offre-job.
 *
 * États V1 (7, aucun état fantôme) :
 *   draft, published, expiring, expired, filled, archived, suspended.
 *
 * - `pending` (modération d'offre) : **retiré de la V1**. Il sera réintroduit par le
 *   futur plugin `postelio-moderation` (aucun code actuel ne peut y entrer).
 * - `renewed` : **n'est PAS un état persistant**. Le renouvellement est la transition
 *   `expired → published` (nouvelle date d'expiration) accompagnée de l'événement
 *   métier `job.renewed`, déclenchée uniquement par le futur `postelio-billing`
 *   après paiement (voir Api\JobLifecycle). Aucun paiement n'est implémenté ici.
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
	public const PUBLISHED = 'published';
	public const EXPIRING  = 'expiring';
	public const EXPIRED   = 'expired';
	public const FILLED    = 'filled';
	public const ARCHIVED  = 'archived';
	public const SUSPENDED = 'suspended';

	/** @var array<string, string[]> */
	private const TRANSITIONS = array(
		self::DRAFT     => array( self::PUBLISHED, self::ARCHIVED ),
		self::PUBLISHED => array( self::EXPIRING, self::EXPIRED, self::FILLED, self::ARCHIVED, self::SUSPENDED ),
		self::EXPIRING  => array( self::EXPIRED, self::FILLED, self::ARCHIVED, self::SUSPENDED ),
		self::EXPIRED   => array( self::PUBLISHED, self::ARCHIVED ), // published = renouvellement (billing)
		self::FILLED    => array( self::ARCHIVED ),
		self::ARCHIVED  => array(),                                 // terminal (recréer via duplication)
		self::SUSPENDED => array( self::PUBLISHED, self::ARCHIVED ),
	);

	/** États visibles PUBLIQUEMENT. */
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
