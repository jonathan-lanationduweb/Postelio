<?php
/**
 * Nettoyage du contenu savoir-faire. Titre/résumé = texte simple ; contenu = HTML restreint
 * (liste blanche `wp_kses`) : paragraphes, listes, gras/italique, titres simples, liens https.
 * Interdits : script, iframe, style, attributs « on… », et schémas javascript:/data:/file:.
 * Normalise aussi les blocs structurés OPTIONNELS (`pst_details`) — jamais requis.
 *
 * @package Postelio\Skills\Skills
 */

namespace Postelio\Skills\Skills;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_SKILLS_TESTING' ) ) {
		exit;
	}
}

final class SkillSanitizer {

	public const TITLE_MAX   = 160;
	public const SUMMARY_MAX = 500;

	/** @return array<string, array<string, bool>> Liste blanche HTML du contenu. */
	public static function allowed_html(): array {
		return array(
			'p'      => array(),
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'u'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'h2'     => array(),
			'h3'     => array(),
			'blockquote' => array(),
			'a'      => array( 'href' => true, 'title' => true, 'rel' => true, 'target' => true ),
		);
	}

	public static function title( string $raw ): string {
		return mb_substr( sanitize_text_field( $raw ), 0, self::TITLE_MAX );
	}

	public static function summary( string $raw ): string {
		return mb_substr( sanitize_textarea_field( $raw ), 0, self::SUMMARY_MAX );
	}

	/** Contenu HTML restreint + neutralisation des schémas d'URL dangereux dans les liens. */
	public static function content( string $raw ): string {
		$clean = wp_kses( $raw, self::allowed_html() );
		// Défense en profondeur : retire tout href à schéma dangereux résiduel.
		$clean = (string) preg_replace_callback(
			'/href\s*=\s*("|\')(.*?)\1/i',
			static function ( $m ) {
				$url = trim( $m[2] );
				if ( preg_match( '/^\s*(javascript|data|file|vbscript)\s*:/i', $url ) ) {
					return 'href="#"';
				}
				return 'href="' . esc_url( $url, array( 'http', 'https', 'mailto' ) ) . '"';
			},
			$clean
		);
		return $clean;
	}

	/** @return string[] Tags nettoyés, dédupliqués, bornés. */
	public static function tags( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( $raw as $t ) {
			$t = sanitize_text_field( (string) $t );
			if ( '' !== $t ) {
				$out[ mb_strtolower( $t ) ] = $t;
			}
		}
		return array_values( array_slice( $out, 0, 15 ) );
	}

	/**
	 * Normalise les blocs structurés optionnels (compat affichage riche du front).
	 *
	 * @param mixed $raw
	 * @return array<string, mixed>
	 */
	public static function details( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( array( 'metier', 'difficulte', 'duree', 'resultat', 'intro' ) as $k ) {
			if ( isset( $raw[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( (string) $raw[ $k ] );
			}
		}
		foreach ( array( 'materiel', 'conseils', 'erreurs' ) as $list ) {
			if ( isset( $raw[ $list ] ) && is_array( $raw[ $list ] ) ) {
				$out[ $list ] = array_values( array_filter( array_map( static fn( $v ) => sanitize_text_field( (string) $v ), $raw[ $list ] ) ) );
			}
		}
		if ( isset( $raw['etapes'] ) && is_array( $raw['etapes'] ) ) {
			$steps = array();
			foreach ( array_slice( $raw['etapes'], 0, 50 ) as $s ) {
				$s = is_array( $s ) ? $s : array( 'texte' => (string) $s );
				$title = sanitize_text_field( (string) ( $s['titre'] ?? '' ) );
				$text  = self::summary( (string) ( $s['texte'] ?? $s['text'] ?? '' ) );
				if ( '' !== $title || '' !== $text ) {
					$steps[] = array( 'titre' => $title, 'texte' => $text, 'conseil' => sanitize_text_field( (string) ( $s['conseil'] ?? '' ) ) );
				}
			}
			$out['etapes'] = $steps;
		}
		if ( isset( $raw['galerie'] ) && is_array( $raw['galerie'] ) ) {
			$out['galerie'] = array_values( array_filter( array_map( 'absint', $raw['galerie'] ) ) ); // IDs d'attachements WP
		}
		return $out;
	}
}
