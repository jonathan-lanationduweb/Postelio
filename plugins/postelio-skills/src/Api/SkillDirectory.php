<?php
/**
 * Contrat public STABLE des savoir-faire pour les autres domaines (profils candidat/entreprise,
 * futur postelio-search, moderation, notifications). N'expose jamais les tables/meta internes.
 *
 * @package Postelio\Skills\Api
 */

namespace Postelio\Skills\Api;

use Postelio\Skills\Skills\SkillPresenter;
use Postelio\Skills\Skills\SkillRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SkillDirectory {

	private static function repo(): SkillRepository {
		return new SkillRepository();
	}

	/** @return array<string,mixed>|null Contexte minimal (interne domaine). */
	public static function get_context( string $uuid ): ?array {
		$s = self::repo()->get_by_uuid( $uuid );
		if ( null === $s ) {
			return null;
		}
		return array(
			'uuid'        => (string) $s['uuid'],
			'status'      => (string) $s['status'],
			'author_type' => (string) $s['author_type'],
			'public'      => self::repo()->is_public_visible( $s ),
		);
	}

	public static function belongs_to_user( string $uuid, int $user_id ): bool {
		$s = self::repo()->get_by_uuid( $uuid );
		return null !== $s && (int) $s['author_id'] === $user_id;
	}

	public static function belongs_to_company( string $uuid, int $company_id ): bool {
		$s = self::repo()->get_by_uuid( $uuid );
		return null !== $s && SkillRepository::AUTHOR_COMPANY === $s['author_type'] && (int) $s['company_id'] === $company_id;
	}

	/** @return array<string,mixed>|null Vue publique, ou null si non public. */
	public static function public_view( string $uuid ): ?array {
		$s = self::repo()->get_by_uuid( $uuid );
		if ( null === $s || ! self::repo()->is_public_visible( $s ) ) {
			return null;
		}
		return SkillPresenter::public_view( $s );
	}

	/** @return array<int,array<string,mixed>> Savoir-faire PUBLICS d'un utilisateur (profil candidat). */
	public static function published_for_user( int $user_id ): array {
		$out = array();
		foreach ( self::repo()->list_for_user( $user_id ) as $s ) {
			if ( self::repo()->is_public_visible( $s ) ) {
				$out[] = SkillPresenter::public_view( $s );
			}
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> Savoir-faire PUBLICS d'une entreprise (fiche entreprise). */
	public static function published_for_company( int $company_id ): array {
		$repo = self::repo();
		$out  = array();
		foreach ( $repo->ids_of_company( $company_id ) as $id ) {
			$s = $repo->get( $id );
			if ( null !== $s && $repo->is_public_visible( $s ) ) {
				$out[] = SkillPresenter::public_view( $s );
			}
		}
		return $out;
	}
}
