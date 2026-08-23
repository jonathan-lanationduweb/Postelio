<?php
/**
 * Présentation publique d'une offre EXTERNE, alignée sur `JobPresenter::public_view`
 * (mêmes clés que le natif) + blocs `source`, `application`, `attribution`. UUID public
 * uniquement (jamais `external_id`). Company = données d'affichage (jamais un
 * `postelio_company` vérifié) ; nom absent → « Entreprise confidentielle ».
 *
 * @package Postelio\JobSources\Jobs
 */

namespace Postelio\JobSources\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ExternalJobPresenter {

	/**
	 * @param array<string, mixed> $r Ligne externe décodée.
	 * @return array<string, mixed>
	 */
	public static function public_view( array $r ): array {
		$attr = is_array( $r['attribution_data'] ?? null ) ? $r['attribution_data'] : array();
		$meta = is_array( $r['source_metadata'] ?? null ) ? $r['source_metadata'] : array();

		return array(
			'uuid'             => (string) $r['public_uuid'],
			'titre'            => (string) $r['title'],
			'description'      => $r['description'] ?? null,
			'resume'           => null,
			'ville'            => $r['ville'] ?? null,
			'departement'      => null,
			'contrat'          => $r['contract_normalized'] ?? null,
			'teletravail'      => null,
			'categorie'        => $r['rome_code'] ?? null,
			'categorie_label'  => $r['rome_label'] ?? null,
			'niveau_etude'     => null,
			'experience'       => $r['experience_label'] ?? null,
			'salaire'          => $r['salary_text'] ?? null,
			'salaire_annuel'   => null,
			'temps_travail'    => $r['working_time'] ?? null,
			'alternance'       => ! empty( $r['alternance'] ),
			'date_publication' => self::iso( (string) ( $r['source_published_at'] ?? '' ) ),
			'date_expiration'  => null,
			'competences'      => self::competences( $meta ),
			'company'          => array(
				'uuid'     => null,
				'nom'      => ( isset( $r['company_name'] ) && '' !== (string) $r['company_name'] ) ? (string) $r['company_name'] : 'Entreprise confidentielle',
				'ville'    => $r['ville'] ?? null,
				'logo_url' => $r['company_logo_url'] ?? null,
				'verified' => false, // jamais une entreprise vérifiée Postelio
			),
			'source'           => array(
				'type'        => 'external',
				'key'         => (string) $r['source_key'],
				'label'       => (string) ( $attr['source_label'] ?? $r['source_key'] ),
				'external'    => true,
				'attribution' => array(
					'source_label'    => (string) ( $attr['source_label'] ?? $r['source_key'] ),
					'licence_url'     => $attr['licence_url'] ?? null,
					'logo_url'        => $r['company_logo_url'] ?? null,
					'source_updated_at' => self::iso( (string) ( $r['source_updated_at'] ?? '' ) ),
					'notice'          => 'Offre proposée par ' . (string) ( $attr['source_label'] ?? $r['source_key'] ),
				),
			),
			'application'      => array(
				'mode'     => (string) ( $r['application_mode'] ?? 'external_redirect' ),
				'redirect' => '/jobs/' . (string) $r['public_uuid'] . '/apply-redirect',
			),
			// SEO : offre externe non indexable ; canonical vers la source si fiable.
			'seo'              => array(
				'noindex'   => true,
				'canonical' => self::canonical( $r ),
				'in_sitemap' => false,
			),
		);
	}

	/** @param array<string,mixed> $meta @return array<int,string> */
	private static function competences( array $meta ): array {
		$out = array();
		foreach ( (array) ( $meta['competences'] ?? array() ) as $c ) {
			if ( is_array( $c ) && ! empty( $c['libelle'] ) ) {
				$out[] = (string) $c['libelle'];
			}
		}
		return $out;
	}

	/** @param array<string,mixed> $r */
	private static function canonical( array $r ): ?string {
		$url = (string) ( $r['external_url'] ?? '' );
		return '' !== $url ? $url : null;
	}

	private static function iso( string $mysql_utc ): ?string {
		if ( '' === $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return null;
		}
		return str_replace( ' ', 'T', $mysql_utc ) . 'Z';
	}
}
