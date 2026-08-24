<?php
/**
 * Présentation API des savoir-faire. Vue publique (contenu + auteur public + entreprise
 * publique + SEO) SANS ID SQL ni donnée privée. Vue auteur (statut/révision inclus) pour le
 * dashboard. SEO exposé en contrat (le front ne le rend pas encore).
 *
 * @package Postelio\Skills\Skills
 */

namespace Postelio\Skills\Skills;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SkillPresenter {

	/** @param array<string,mixed> $s @return array<string,mixed> Vue publique. */
	public static function public_view( array $s ): array {
		return array(
			'uuid'         => (string) $s['uuid'],
			'slug'         => (string) $s['slug'],
			'title'        => (string) $s['title'],
			'summary'      => (string) $s['summary'],
			'content'      => (string) $s['content'],
			'details'      => is_array( $s['details'] ) ? $s['details'] : array(),
			'category'     => (string) $s['category'],
			'tags'         => array_values( (array) $s['tags'] ),
			'image_url'    => $s['image_url'] ?? null,
			'gallery'      => array_values( (array) ( $s['gallery'] ?? array() ) ),
			'author'       => self::author( $s ),
			'published_at' => self::iso( (string) $s['created_at'] ),
			'modified_at'  => self::iso( (string) $s['modified_at'] ),
			'seo'          => self::seo( $s ),
		);
	}

	/** @param array<string,mixed> $s @return array<string,mixed> Vue auteur (dashboard). */
	public static function author_view( array $s ): array {
		$v = self::public_view( $s );
		$v['status']            = (string) $s['status'];
		$v['revision']          = (int) $s['revision'];
		$v['author_type']       = (string) $s['author_type'];
		$v['public']            = SkillStateMachine::PUBLISHED === $s['status'] && empty( $s['mod_hidden'] ) && empty( $s['susp_hidden'] );
		$v['moderation_hidden'] = ! empty( $s['mod_hidden'] );
		return $v;
	}

	/** @param array<string,mixed> $s @return array<string,mixed> */
	private static function author( array $s ): array {
		if ( SkillRepository::AUTHOR_COMPANY === $s['author_type'] && class_exists( '\\Postelio\\Companies\\Api\\CompanyDirectory' ) ) {
			$summary = \Postelio\Companies\Api\CompanyDirectory::public_summary( (int) $s['company_id'] );
			return array(
				'type'    => 'company',
				'name'    => (string) ( $summary['nom'] ?? $summary['name'] ?? '' ),
				'company' => array(
					'uuid'     => (string) ( $summary['uuid'] ?? $s['company_uuid'] ),
					'logo_url' => $summary['logo_url'] ?? ( $summary['editorial']['logo_url'] ?? null ),
				),
			);
		}
		$author = class_exists( '\\Postelio\\Users\\Api\\UserDirectory' )
			? \Postelio\Users\Api\UserDirectory::public_author( (int) $s['author_id'] )
			: array( 'display_name' => '', 'metier' => null, 'avatar_url' => null, 'profile_uuid' => null );
		return array(
			'type'         => 'candidate',
			'name'         => (string) ( $author['display_name'] ?? '' ),
			'metier'       => $author['metier'] ?? null,
			'avatar_url'   => $author['avatar_url'] ?? null,
			'profile_uuid' => $author['profile_uuid'] ?? null,
		);
	}

	/** @param array<string,mixed> $s @return array<string,mixed> Contrat SEO (non rendu par le front V1). */
	private static function seo( array $s ): array {
		$visible = SkillStateMachine::PUBLISHED === $s['status'] && empty( $s['mod_hidden'] ) && empty( $s['susp_hidden'] );
		return array(
			'slug'             => (string) $s['slug'],
			'title'            => (string) $s['title'],
			'meta_description' => mb_substr( wp_strip_all_tags( (string) ( '' !== $s['summary'] ? $s['summary'] : $s['content'] ) ), 0, 160 ),
			'canonical'        => null, // construit par le front à partir du slug (non rendu V1)
			'author'           => self::author( $s )['name'],
			'date_published'   => self::iso( (string) $s['created_at'] ),
			'date_modified'    => self::iso( (string) $s['modified_at'] ),
			'noindex'          => ! $visible,
			'in_sitemap'       => $visible,
		);
	}

	private static function iso( string $mysql_utc ): ?string {
		if ( '' === $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return null;
		}
		return str_replace( ' ', 'T', $mysql_utc ) . 'Z';
	}
}
