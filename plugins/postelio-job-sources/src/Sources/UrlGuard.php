<?php
/**
 * Garde-fous URL. Deux usages DISTINCTS :
 *  - `api_host_allowed()` : hôtes que le SERVEUR Postelio a le droit d'appeler (anti-SSRF).
 *    Strictement limité aux hôtes officiels France Travail.
 *  - `safe_redirect_url()` : URL de redirection CANDIDAT fournie par la source (peut pointer
 *    vers un partenaire légitime). Validée (https, pas de javascript:/data:/file:, pas d'IP
 *    privée/localhost) mais SANS restriction de domaine et SANS requête serveur dessus.
 *
 * @package Postelio\JobSources\Sources
 */

namespace Postelio\JobSources\Sources;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_JOBSOURCES_TESTING' ) ) {
		exit;
	}
}

final class UrlGuard {

	/** Hôtes officiels France Travail que le serveur peut contacter. */
	private const API_HOSTS = array( 'api.francetravail.io', 'entreprise.francetravail.fr' );

	/** Le serveur a-t-il le droit d'appeler cette URL ? (anti-SSRF, allow-list stricte) */
	public static function api_host_allowed( string $url ): bool {
		$parts = \parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		return in_array( $host, self::API_HOSTS, true );
	}

	/**
	 * URL de redirection candidat sûre ? https + hôte public réel, pas de schéma dangereux,
	 * pas de localhost / IP privée. Ne restreint PAS le domaine (partenaires FT légitimes).
	 */
	public static function safe_redirect_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > 768 ) {
			return false;
		}
		$parts = \parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		if ( 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return false; // https uniquement (exclut http/javascript/data/file)
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host || false !== strpos( $host, ' ' ) ) {
			return false;
		}
		if ( 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false; // IP privée/réservée
		}
		return true;
	}
}
