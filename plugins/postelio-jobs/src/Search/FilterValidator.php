<?php
/**
 * Validation/normalisation des filtres de recherche d'offres — SOURCE DE VÉRITÉ UNIQUE.
 *
 * Réutilisée par le contrôleur public (`GET /jobs`, mode permissif : clé inconnue ignorée) ET
 * par les recherches sauvegardées / alertes (postelio-alerts, mode strict : clé inconnue =>
 * 422 validation_error). Garantit qu'une recherche sauvegardée ne peut stocker QUE des filtres
 * réellement supportés par le moteur Jobs.
 *
 * `published_after` n'est PAS un filtre public : c'est un paramètre de recherche INTERNE (cron
 * d'alertes) — il est donc rejeté en mode strict et ignoré en mode permissif. Il n'est jamais
 * accepté depuis un corps utilisateur.
 *
 * @package Postelio\Jobs\Search
 */

namespace Postelio\Jobs\Search;

use Postelio\Core\ApiError;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class FilterValidator {

	/** Filtres texte (égalité stricte côté moteur). */
	private const TEXT_KEYS = array( 'q', 'ville', 'contrat', 'categorie', 'teletravail', 'niveau_etude', 'experience' );

	/** Drapeaux booléens. */
	private const FLAG_KEYS = array( 'alternance', 'stage', 'debutant' );

	/** Valeurs autorisées pour le filtre de provenance. */
	private const SOURCES = array( 'all', 'postelio', 'partners' );

	/** Ensemble des clés publiques acceptées. @return string[] */
	public static function public_keys(): array {
		return array_merge( self::TEXT_KEYS, self::FLAG_KEYS, array( 'salaire_min', 'source' ) );
	}

	/**
	 * Valide/normalise un tableau de filtres.
	 *
	 * @param array<string, mixed> $raw
	 * @param bool                 $strict  true : lève une ApiError 422 pour toute clé inconnue.
	 * @return array<string, mixed> Filtres normalisés (clés absentes = non filtrées).
	 */
	public static function validate( array $raw, bool $strict = false ): array {
		$allowed = self::public_keys();
		if ( $strict ) {
			$unknown = array_diff( array_keys( $raw ), $allowed );
			if ( ! empty( $unknown ) ) {
				$details = array();
				foreach ( $unknown as $key ) {
					$details[ (string) $key ] = 'unknown_filter';
				}
				throw ApiError::validation( $details, 'Filtre de recherche non reconnu.' );
			}
		}

		$out = array();
		foreach ( self::TEXT_KEYS as $k ) {
			if ( isset( $raw[ $k ] ) && '' !== $raw[ $k ] && ( is_string( $raw[ $k ] ) || is_numeric( $raw[ $k ] ) ) ) {
				$v = sanitize_text_field( (string) $raw[ $k ] );
				if ( '' !== $v ) {
					$out[ $k ] = $v;
				}
			}
		}
		if ( isset( $raw['salaire_min'] ) && '' !== $raw['salaire_min'] && is_numeric( $raw['salaire_min'] ) ) {
			$out['salaire_min'] = max( 0, (int) $raw['salaire_min'] );
		}
		foreach ( self::FLAG_KEYS as $flag ) {
			if ( isset( $raw[ $flag ] ) && in_array( $raw[ $flag ], array( '1', 1, true, 'true' ), true ) ) {
				$out[ $flag ] = true;
			}
		}
		if ( isset( $raw['source'] ) && in_array( $raw['source'], self::SOURCES, true ) ) {
			$out['source'] = (string) $raw['source'];
		}
		return $out;
	}

	/**
	 * Empreinte stable et déterministe d'un jeu de filtres normalisés (déduplication des
	 * recherches sauvegardées, §14). Insensible à l'ordre des clés et au bruit.
	 *
	 * @param array<string, mixed> $filters Déjà validés par self::validate().
	 */
	public static function fingerprint( array $filters ): string {
		ksort( $filters );
		$norm = array();
		foreach ( $filters as $k => $v ) {
			if ( is_bool( $v ) ) {
				$norm[ $k ] = $v ? '1' : '0';
			} else {
				$norm[ $k ] = (string) $v;
			}
		}
		return sha1( (string) wp_json_encode( $norm ) );
	}
}
