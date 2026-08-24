<?php
/**
 * Contrat public de modération des savoir-faire (consommé par postelio-moderation). Masquage
 * local réversible via le drapeau `pst_mod_hidden` — DISTINCT du masquage de suspension, pour
 * qu'une réactivation user/entreprise ne réexpose jamais un contenu masqué par la modération.
 * Moderation ne touche jamais directement le CPT/meta.
 *
 * @package Postelio\Skills\Api
 */

namespace Postelio\Skills\Api;

use Postelio\Core\Plugin as Core;
use Postelio\Skills\Skills\SkillRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SkillModeration {

	public static function hide( string $uuid ): bool {
		return self::set_visibility( $uuid, 'hidden' );
	}

	public static function unhide( string $uuid ): bool {
		return self::set_visibility( $uuid, 'visible' );
	}

	public static function set_visibility( string $uuid, string $visibility ): bool {
		$repo  = new SkillRepository();
		$skill = $repo->get_by_uuid( $uuid );
		if ( null === $skill ) {
			return false;
		}
		$hidden = 'hidden' === $visibility;
		$repo->set_mod_hidden( (int) $skill['id'], $hidden );
		self::emit( $hidden ? 'skill.hidden' : 'skill.restored', $skill );
		return true;
	}

	public static function is_visible( string $uuid ): bool {
		$repo  = new SkillRepository();
		$skill = $repo->get_by_uuid( $uuid );
		return null !== $skill && $repo->is_public_visible( $skill );
	}

	/** @param array<string,mixed> $skill */
	private static function emit( string $event, array $skill ): void {
		Core::instance()->events()->emit( $event, array(
			'skill_id'      => (int) $skill['id'],
			'skill_uuid'    => (string) $skill['uuid'],
			'author_id'     => (int) $skill['author_id'],
			'company_id'    => (int) $skill['company_id'],
			'resource_type' => 'skill',
			'resource_id'   => (string) $skill['id'],
			'audit'         => array( 'skill_uuid' => (string) $skill['uuid'], 'by' => 'moderation' ),
		) );
	}
}
