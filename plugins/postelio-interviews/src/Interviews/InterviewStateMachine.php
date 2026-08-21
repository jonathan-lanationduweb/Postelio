<?php
/**
 * Machine à états canonique d'un entretien (V1). Logique PURE (aucune dépendance
 * WordPress) → testable directement.
 *
 * Décision V1 : `proposed` représente déjà « en attente du candidat » ; on n'ajoute
 * pas d'état `pending_candidate` redondant. Les transitions sont contrôlées côté serveur.
 *
 * @package Postelio\Interviews\Interviews
 */

namespace Postelio\Interviews\Interviews;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_INTERVIEWS_TESTING' ) ) {
		exit;
	}
}

final class InterviewStateMachine {

	public const PROPOSED             = 'proposed';
	public const CONFIRMED            = 'confirmed';
	public const RESCHEDULE_REQUESTED = 'reschedule_requested';
	public const DECLINED             = 'declined';
	public const CANCELLED            = 'cancelled';
	public const COMPLETED            = 'completed';

	/** États « actifs » (non terminaux) : un entretien y est encore en vie. */
	public const ACTIVE = array( self::PROPOSED, self::CONFIRMED, self::RESCHEDULE_REQUESTED );

	/**
	 * Transitions autorisées. `confirmed → proposed` = modification substantielle par le
	 * recruteur après confirmation (nouvelle confirmation candidat requise).
	 * `reschedule_requested → confirmed` = le recruteur accepte le créneau proposé par le
	 * candidat ; `→ proposed` = le recruteur contre-propose un autre créneau.
	 *
	 * @var array<string, string[]>
	 */
	private const TRANSITIONS = array(
		self::PROPOSED             => array( self::CONFIRMED, self::DECLINED, self::RESCHEDULE_REQUESTED, self::CANCELLED, self::PROPOSED ),
		self::CONFIRMED            => array( self::RESCHEDULE_REQUESTED, self::CANCELLED, self::COMPLETED, self::PROPOSED ),
		self::RESCHEDULE_REQUESTED => array( self::CONFIRMED, self::PROPOSED, self::CANCELLED ),
		self::DECLINED             => array(),
		self::CANCELLED            => array(),
		self::COMPLETED            => array(),
	);

	/** @return string[] */
	public static function all(): array {
		return array_keys( self::TRANSITIONS );
	}

	public static function is_status( string $s ): bool {
		return isset( self::TRANSITIONS[ $s ] );
	}

	public static function is_terminal( string $s ): bool {
		return self::is_status( $s ) && array() === self::TRANSITIONS[ $s ];
	}

	public static function is_active( string $s ): bool {
		return in_array( $s, self::ACTIVE, true );
	}

	public static function can_transition( string $from, string $to ): bool {
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}

	/** Le candidat peut-il confirmer/refuser (action ponctuelle sur une proposition) ? */
	public static function candidate_can_answer( string $s ): bool {
		return self::PROPOSED === $s;
	}

	/** Le candidat peut-il demander un autre créneau ? (proposition OU entretien confirmé) */
	public static function candidate_can_reschedule( string $s ): bool {
		return self::PROPOSED === $s || self::CONFIRMED === $s;
	}

	/**
	 * Le candidat peut-il annuler ? Décision V1 : oui pour un entretien déjà confirmé ou
	 * en attente de re-créneau (à l'état `proposed`, il utilise plutôt `decline`).
	 */
	public static function candidate_can_cancel( string $s ): bool {
		return self::CONFIRMED === $s || self::RESCHEDULE_REQUESTED === $s;
	}
}
