<?php
/**
 * Limitation de débit des endpoints d'authentification (M2 — anti force-brute /
 * credential stuffing / spam d'inscription / bombardement e-mail / brute-force de jeton).
 *
 * Le core ne fournit pas de rate limiter dédié ; on réutilise le mécanisme éprouvé du
 * projet (compteurs par transient), mais avec une clé dérivée par HMAC (jamais d'IP ni
 * d'e-mail en clair — vie privée) et des fenêtres glissantes par « bucket » temporel, ce
 * qui rend la logique testable via une horloge injectable (`self::$clock`) sans `sleep()`.
 *
 * Principe (décision produit) :
 *  - JAMAIS de verrou par e-mail seul (DoS de la victime) : les compteurs par identifiant
 *    sont TOUJOURS scopés par IP ; un compteur global par IP couvre le brute-force ciblé.
 *  - L'IP provient de `REMOTE_ADDR` uniquement (jamais X-Forwarded-For / X-Real-IP,
 *    spoofables) ; un filtre `postelio/auth/client_ip` prépare un futur proxy de confiance.
 *
 * Limites par endpoint (secondes) — ajustables par filtre `postelio/auth/rate_limit/{clé}` :
 *  | endpoint        | clé            | fenêtre | max | note                                   |
 *  |-----------------|----------------|---------|-----|----------------------------------------|
 *  | login (échecs)  | login_ip       | 900     | 30  | compte les ÉCHECS ; succès n'en consomme pas |
 *  | login (échecs)  | login_id       | 900     | 10  | IP+e-mail : brute-force ciblé          |
 *  | register        | register_ip    | 3600    | 10  | création massive de comptes            |
 *  | lost-password   | lost_id        | 900     | 5   | IP+e-mail : bombardement               |
 *  | lost-password   | lost_ip        | 3600    | 20  | garde-fou global IP                    |
 *  | resend verify   | resend_cooldown| 60      | 1   | cooldown court (par utilisateur)       |
 *  | resend verify   | resend_user    | 3600    | 5   | plafond horaire                        |
 *  | reset-password  | reset_ip       | 900     | 10  | par IP (jamais par jeton : un tiers ne doit pas invalider le reset d'autrui) |
 *  | refresh         | —              | —       | —   | non limité (jeton = secret 32o hash_equals, non énumérable) |
 *
 * @package Postelio\Users\Auth
 */

namespace Postelio\Users\Auth;

use Postelio\Core\ApiError;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AuthRateLimiter {

	/** Horloge injectable pour les tests (callable → int timestamp). Null = time(). */
	public static $clock = null;

	private const PREFIX = 'pst_arl_';

	/** @var array<string, array{0:int,1:int}> max, fenêtre (secondes) par clé. */
	private const DEFAULTS = array(
		'login_ip'        => array( 30, 900 ),
		'login_id'        => array( 10, 900 ),
		// Login WordPress natif (wp-login.php / XML-RPC) — mêmes seuils que REST, clés
		// distinctes pour un comptage indépendant (jamais de double-comptage avec /auth).
		'login_native_ip' => array( 30, 900 ),
		'login_native_id' => array( 10, 900 ),
		'register_ip'     => array( 10, 3600 ),
		'lost_id'         => array( 5, 900 ),
		'lost_ip'         => array( 20, 3600 ),
		'resend_cooldown' => array( 1, 60 ),
		'resend_user'     => array( 5, 3600 ),
		'reset_ip'        => array( 10, 900 ),
		// Parcours candidature guest (public) — anti-spam.
		'guest_apply_ip'  => array( 20, 3600 ),
		'guest_apply_id'  => array( 5, 3600 ),
		'guest_confirm_ip' => array( 30, 900 ),
		'guest_cv_ip'     => array( 15, 3600 ),
	);

	private static function now(): int {
		return null !== self::$clock ? (int) call_user_func( self::$clock ) : time();
	}

	/** IP client — REMOTE_ADDR uniquement (jamais d'en-tête spoofable). */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		/**
		 * Point d'extension pour un futur reverse proxy de CONFIANCE (Cloudflare…).
		 * Par défaut : REMOTE_ADDR brut. Ne jamais brancher X-Forwarded-For sans
		 * validation d'un proxy de confiance.
		 */
		$ip = (string) apply_filters( 'postelio/auth/client_ip', $ip );
		return '' !== $ip ? $ip : '0.0.0.0';
	}

	/** @return array{0:int,1:int} max, fenêtre pour une clé (filtrable). */
	private static function limit( string $key ): array {
		$def = self::DEFAULTS[ $key ] ?? array( 10, 900 );
		$val = apply_filters( 'postelio/auth/rate_limit/' . $key, $def );
		if ( ! is_array( $val ) || 2 !== count( $val ) ) {
			$val = $def;
		}
		return array( max( 1, (int) $val[0] ), max( 1, (int) $val[1] ) );
	}

	private static function bucket_key( string $key, string $identifier, int $window, int $now ): string {
		$slot = (int) floor( $now / $window );
		// HMAC (wp_hash) : ni IP ni e-mail stockés en clair. Bucket temporel dans la clé.
		return self::PREFIX . wp_hash( $key . '|' . strtolower( $identifier ) . '|' . $slot );
	}

	/**
	 * Vérifie (sans incrémenter) que chaque limite n'est pas déjà atteinte.
	 *
	 * @param array<int, array{0:string,1:string}> $limits liste de [clé, identifiant].
	 * @throws ApiError rate_limited (429 + retry_after) au dépassement.
	 */
	public static function check( array $limits ): void {
		$now = self::now();
		foreach ( $limits as $l ) {
			list( $max, $window ) = self::limit( $l[0] );
			$tkey  = self::bucket_key( $l[0], $l[1], $window, $now );
			$count = (int) get_transient( $tkey );
			if ( $count >= $max ) {
				$retry = $window - ( $now % $window );
				throw new ApiError(
					'rate_limited',
					__( 'Trop de tentatives. Réessayez dans un instant.', 'postelio-users' ),
					array( 'retry_after' => max( 1, $retry ) )
				);
			}
		}
	}

	/**
	 * Incrémente chaque compteur (fenêtre courante).
	 *
	 * @param array<int, array{0:string,1:string}> $limits
	 */
	public static function hit( array $limits ): void {
		$now = self::now();
		foreach ( $limits as $l ) {
			list( , $window ) = self::limit( $l[0] );
			$tkey  = self::bucket_key( $l[0], $l[1], $window, $now );
			$count = (int) get_transient( $tkey );
			set_transient( $tkey, $count + 1, $window + 1 );
		}
	}

	/** Vérifie PUIS incrémente (endpoints qui comptent chaque requête). */
	public static function enforce( array $limits ): void {
		self::check( $limits );
		self::hit( $limits );
	}

	// --- Gardes par endpoint (politique centralisée, appelées par AuthController) ---

	/** Login : vérifie seulement (les échecs sont comptés via note_login_failure). */
	public static function guard_login( string $email ): void {
		$ip = self::client_ip();
		self::check( array(
			array( 'login_ip', $ip ),
			array( 'login_id', $ip . '|' . $email ),
		) );
	}

	/** Login échoué : consomme le budget (le succès n'en consomme pas). */
	public static function note_login_failure( string $email ): void {
		$ip = self::client_ip();
		self::hit( array(
			array( 'login_ip', $ip ),
			array( 'login_id', $ip . '|' . $email ),
		) );
	}

	public static function guard_register(): void {
		self::enforce( array( array( 'register_ip', self::client_ip() ) ) );
	}

	public static function guard_lost_password( string $email ): void {
		$ip = self::client_ip();
		self::enforce( array(
			array( 'lost_id', $ip . '|' . $email ),
			array( 'lost_ip', $ip ),
		) );
	}

	public static function guard_resend( int $user_id ): void {
		self::enforce( array(
			array( 'resend_cooldown', 'u' . $user_id ),
			array( 'resend_user', 'u' . $user_id ),
		) );
	}

	public static function guard_reset(): void {
		self::enforce( array( array( 'reset_ip', self::client_ip() ) ) );
	}

	/** Soumission d'une candidature guest : anti-spam par IP et par IP+e-mail. */
	public static function guard_guest_apply( string $email ): void {
		$ip = self::client_ip();
		self::enforce( array(
			array( 'guest_apply_ip', $ip ),
			array( 'guest_apply_id', $ip . '|' . $email ),
		) );
	}

	/** Confirmation d'une candidature guest (tentatives de jeton) : par IP. */
	public static function guard_guest_confirm(): void {
		self::enforce( array( array( 'guest_confirm_ip', self::client_ip() ) ) );
	}

	/** Upload CV guest (public) : par IP. */
	public static function guard_guest_cv(): void {
		self::enforce( array( array( 'guest_cv_ip', self::client_ip() ) ) );
	}
}
