<?php
/**
 * Nettoyage strict du HTML des descriptions externes (JAMAIS de confiance). Liste blanche
 * de balises de mise en forme minimales, sans attribut. Supprime script/iframe/style,
 * gestionnaires d'événements et URLs `javascript:`. Fonctionne aussi hors WordPress
 * (tests) via un repli sans wp_kses.
 *
 * @package Postelio\JobSources\Sources
 */

namespace Postelio\JobSources\Sources;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_JOBSOURCES_TESTING' ) ) {
		exit;
	}
}

final class HtmlSanitizer {

	/** @var array<string, array<string,bool>> Balises autorisées (aucun attribut). */
	private const ALLOWED = array(
		'p' => array(), 'br' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(),
		'strong' => array(), 'em' => array(), 'b' => array(), 'i' => array(),
	);

	public static function clean( ?string $html ): ?string {
		if ( null === $html || '' === trim( $html ) ) {
			return null;
		}
		// Retire d'abord les blocs dangereux entièrement (contenu inclus).
		$html = (string) preg_replace( '#<(script|style|iframe|object|embed|svg|math)\b.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '#<(script|style|iframe|object|embed|svg|math)\b[^>]*/?>#is', '', $html );

		if ( function_exists( 'wp_kses' ) ) {
			$clean = wp_kses( $html, self::ALLOWED );
		} else {
			// Repli hors WP : ne garde que les balises autorisées, sans attribut.
			$allowed_str = '<' . implode( '><', array_keys( self::ALLOWED ) ) . '>';
			$clean       = strip_tags( $html, $allowed_str );
			$clean       = (string) preg_replace( '/<([a-z0-9]+)\s[^>]*>/i', '<$1>', $clean ); // retire attributs
		}
		// Ceinture + bretelles : neutralise tout gestionnaire d'événement / javascript: résiduel.
		$clean = (string) preg_replace( '/on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean );
		$clean = (string) preg_replace( '/javascript:/i', '', $clean );
		$clean = trim( $clean );
		return '' !== $clean ? $clean : null;
	}
}
