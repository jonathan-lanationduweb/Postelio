<?php
/**
 * Machine à états CANONIQUE d'une candidature (V1) — docs/backend/workflows.md#candidature.
 *
 * États : new, review, shortlisted, interview, selected, rejected, withdrawn.
 * `interview` existe comme ÉTAPE de pipeline ; le détail des rendez-vous relève de
 * `postelio-interviews` (plus tard).
 *
 * Acteurs (appliqués par le service, pas par la machine) :
 *  - recruteur (membre de l'entreprise) : transitions vers review/shortlisted/interview/
 *    selected/rejected ;
 *  - candidat (propriétaire) : `→ withdrawn` depuis tout état actif.
 *
 * Retours arrière : autorisés ENTRE états actifs (new/review/shortlisted/interview) pour
 * coller au Kanban ; `new` n'est jamais une CIBLE (état de création uniquement).
 * `selected`, `rejected`, `withdrawn` sont TERMINAUX.
 *
 * Classe pure → testable en isolation.
 *
 * @package Postelio\Applications\Applications
 */

namespace Postelio\Applications\Applications;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class ApplicationStateMachine {

	public const NEW         = 'new';
	public const REVIEW      = 'review';
	public const SHORTLISTED = 'shortlisted';
	public const INTERVIEW   = 'interview';
	public const SELECTED    = 'selected';
	public const REJECTED    = 'rejected';
	public const WITHDRAWN   = 'withdrawn';

	/** États actifs (candidature en cours de traitement). */
	public const ACTIVE = array( self::NEW, self::REVIEW, self::SHORTLISTED, self::INTERVIEW );

	/** @var array<string, string[]> */
	private const TRANSITIONS = array(
		self::NEW         => array( self::REVIEW, self::SHORTLISTED, self::INTERVIEW, self::SELECTED, self::REJECTED, self::WITHDRAWN ),
		self::REVIEW      => array( self::SHORTLISTED, self::INTERVIEW, self::SELECTED, self::REJECTED, self::WITHDRAWN ),
		self::SHORTLISTED => array( self::REVIEW, self::INTERVIEW, self::SELECTED, self::REJECTED, self::WITHDRAWN ),
		self::INTERVIEW   => array( self::REVIEW, self::SHORTLISTED, self::SELECTED, self::REJECTED, self::WITHDRAWN ),
		self::SELECTED    => array(),
		self::REJECTED    => array(),
		self::WITHDRAWN   => array(),
	);

	/** Transitions déclenchables par un RECRUTEUR. */
	public const RECRUITER_TARGETS = array( self::REVIEW, self::SHORTLISTED, self::INTERVIEW, self::SELECTED, self::REJECTED );

	/** @return string[] */
	public static function statuses(): array {
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

	/** @return string[] */
	public static function allowed_from( string $from ): array {
		return self::TRANSITIONS[ $from ] ?? array();
	}
}
