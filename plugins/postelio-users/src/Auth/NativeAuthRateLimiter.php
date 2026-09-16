<?php
/**
 * Rate limiting du login WordPress NATIF — wp-login.php, XML-RPC, mots de passe
 * d'application (M2, complément).
 *
 * Le limiteur REST (`AuthController` + `AuthRateLimiter`) ne protège que `POST /auth`.
 * Or un compte Postelio (candidat/recruteur possède `read`) peut aussi s'authentifier
 * via `wp-login.php` et `xmlrpc.php`, sans aucune limitation côté WordPress cœur — donc
 * un contournement trivial du rate limit REST par force brute.
 *
 * Ce garde réutilise `AuthRateLimiter` (aucun second limiteur) en se branchant sur le
 * mécanisme d'authentification WordPress :
 *  - filtre `authenticate` (priorité 25) : APRÈS la vérification du couple identifiant/mot
 *    de passe (wp_authenticate_username_password à 20) et AVANT `AccountStatusGuard` (30).
 *    Ordre = mot de passe → rate limit → statut. Si le seuil est atteint, renvoie un
 *    WP_Error (blocage), même pour un mot de passe correct ; comme il agit avant le contrôle
 *    de statut, il ne révèle jamais qu'un compte est suspendu.
 *  - action `wp_login_failed` : incrémente le compteur d'échecs (déclenchée par
 *    `wp_authenticate()` sur tout échec, quel que soit le canal natif).
 *
 * Clés DISTINCTES de REST (`login_native_*`) : chaque canal a son propre budget (seuils
 * égaux), sans double-comptage ni interférence de test. Le login REST reste géré par
 * `AuthController` (429 + Retry-After) ; ce garde ne renvoie qu'un WP_Error natif.
 *
 * Les administrateurs ne sont PAS bloqués arbitrairement : la limite est déclenchée par
 * des ÉCHECS répétés (un login correct ne consomme rien) et scopée par IP+identifiant.
 *
 * @package Postelio\Users\Auth
 */

namespace Postelio\Users\Auth;

use Postelio\Core\ApiError;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NativeAuthRateLimiter {

	public function register(): void {
		add_filter( 'authenticate', array( $this, 'maybe_block' ), 25, 3 );
		add_action( 'wp_login_failed', array( $this, 'record_failure' ), 10, 1 );
	}

	/** @return array<int, array{0:string,1:string}> */
	private function limits( string $username ): array {
		$ip = AuthRateLimiter::client_ip();
		return array(
			array( 'login_native_ip', $ip ),
			array( 'login_native_id', $ip . '|' . strtolower( $username ) ),
		);
	}

	/**
	 * Bloque le login natif si le seuil d'échecs est déjà atteint.
	 *
	 * @param \WP_User|\WP_Error|null $user
	 * @param string                  $username
	 * @param string                  $password
	 * @return \WP_User|\WP_Error|null
	 */
	public function maybe_block( $user, $username = '', $password = '' ) {
		if ( '' === (string) $username ) {
			return $user;
		}
		try {
			AuthRateLimiter::check( $this->limits( (string) $username ) );
		} catch ( ApiError $e ) {
			$details = $e->details();
			$retry   = (int) ( $details['retry_after'] ?? 60 );
			return new \WP_Error(
				'too_many_attempts',
				sprintf(
					/* translators: %d: seconds before retry. */
					__( 'Trop de tentatives de connexion. Réessayez dans %d secondes.', 'postelio-users' ),
					max( 1, $retry )
				)
			);
		}
		return $user;
	}

	/**
	 * Enregistre un échec de login natif.
	 *
	 * @param string|mixed $username Identifiant soumis.
	 */
	public function record_failure( $username ): void {
		$username = (string) $username;
		if ( '' === $username ) {
			return;
		}
		AuthRateLimiter::hit( $this->limits( $username ) );
	}
}
