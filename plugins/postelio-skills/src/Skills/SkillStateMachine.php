<?php
/**
 * Machine à états MÉTIER d'un savoir-faire (V1). Logique PURE. Statuts fixés côté serveur.
 * AUCUN état `pending`/`review` (aligné Jobs). Le masquage (`hidden`) n'est PAS un statut :
 * c'est une SUPPRESSION DE VISIBILITÉ à cause traçée (modération vs suspension), gérée par
 * des drapeaux distincts (voir SkillRepository) pour que la levée d'une suspension ne
 * réexpose jamais un contenu masqué par la modération.
 *
 * @package Postelio\Skills\Skills
 */

namespace Postelio\Skills\Skills;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_SKILLS_TESTING' ) ) {
		exit;
	}
}

final class SkillStateMachine {

	public const DRAFT     = 'draft';
	public const PUBLISHED = 'published';
	public const ARCHIVED  = 'archived';

	/** @var array<string, string[]> */
	private const TRANSITIONS = array(
		self::DRAFT     => array( self::PUBLISHED, self::ARCHIVED ),
		self::PUBLISHED => array( self::DRAFT, self::ARCHIVED ), // draft : rétrogradation si édition bloquée
		self::ARCHIVED  => array( self::DRAFT ),
	);

	/** @return string[] */
	public static function all(): array {
		return array_keys( self::TRANSITIONS );
	}

	public static function is_status( string $s ): bool {
		return isset( self::TRANSITIONS[ $s ] );
	}

	public static function can_transition( string $from, string $to ): bool {
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}
}
