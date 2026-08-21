<?php
/**
 * Machine à états CANONIQUE de la vérification d'entreprise.
 *
 * États (6) : unverified, pending, manual_review, verified, rejected, suspended.
 * `conflict` N'EST PAS un état : c'est un motif (`duplicate_siren`) de `manual_review`.
 *
 * Transitions autorisées (from → to) :
 *   unverified     → pending, manual_review
 *   pending        → verified, rejected, manual_review
 *   manual_review  → verified, rejected, pending
 *   rejected       → pending
 *   verified       → suspended, manual_review        (manual_review = réouverture / re-vérification)
 *   suspended      → verified, rejected
 *
 * Acteurs : le recruteur ne provoque que `… → pending` (demande) ; le provider,
 * pendant une demande, applique `pending → verified|rejected|manual_review` ;
 * l'admin applique les décisions `→ verified|rejected|manual_review|suspended`.
 * Aucun état n'est réellement TERMINAL : tout est réversible par l'admin
 * (rejected re-soumissible, verified suspendable/réouvrable, suspended réactivable).
 *
 * Classe pure → testable en isolation.
 *
 * @package Postelio\Companies\Verification
 */

namespace Postelio\Companies\Verification;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class VerificationStateMachine {

	public const UNVERIFIED    = 'unverified';
	public const PENDING       = 'pending';
	public const MANUAL_REVIEW = 'manual_review';
	public const VERIFIED      = 'verified';
	public const REJECTED      = 'rejected';
	public const SUSPENDED     = 'suspended';

	/** @var array<string, string[]> from => [to,…] */
	private const TRANSITIONS = array(
		self::UNVERIFIED    => array( self::PENDING, self::MANUAL_REVIEW ),
		self::PENDING       => array( self::VERIFIED, self::REJECTED, self::MANUAL_REVIEW ),
		self::MANUAL_REVIEW => array( self::VERIFIED, self::REJECTED, self::PENDING ),
		self::REJECTED      => array( self::PENDING ),
		self::VERIFIED      => array( self::SUSPENDED, self::MANUAL_REVIEW ),
		self::SUSPENDED     => array( self::VERIFIED, self::REJECTED ),
	);

	/** @return string[] */
	public static function statuses(): array {
		return array( self::UNVERIFIED, self::PENDING, self::MANUAL_REVIEW, self::VERIFIED, self::REJECTED, self::SUSPENDED );
	}

	public static function is_status( string $status ): bool {
		return in_array( $status, self::statuses(), true );
	}

	public static function can_transition( string $from, string $to ): bool {
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}

	/** @return string[] Cibles autorisées depuis $from. */
	public static function allowed_from( string $from ): array {
		return self::TRANSITIONS[ $from ] ?? array();
	}

	/**
	 * Décisions administratives valides (sous-ensemble des cibles).
	 *
	 * @return string[]
	 */
	public static function admin_decisions(): array {
		return array( self::VERIFIED, self::REJECTED, self::MANUAL_REVIEW, self::SUSPENDED );
	}

	/**
	 * Une entreprise dans cet état peut-elle PUBLIER publiquement une offre (D1) ?
	 * V1 : uniquement si `verified`.
	 */
	public static function allows_publishing( string $status ): bool {
		return self::VERIFIED === $status;
	}
}
