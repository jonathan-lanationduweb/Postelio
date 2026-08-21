<?php
/**
 * Authentifie les requêtes portant un en-tête `Authorization: Bearer <token>`.
 *
 * Se branche sur `determine_current_user`. Les requêtes web (cookies + nonce) ne
 * sont pas affectées : sans en-tête Bearer, la valeur entrante est renvoyée telle
 * quelle. Complète l'auth cookie WordPress pour la future app Tauri.
 *
 * @package Postelio\Users\Auth
 */

namespace Postelio\Users\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TokenAuthenticator {

	private TokenService $tokens;
	private bool $running = false;

	public function __construct( TokenService $tokens ) {
		$this->tokens = $tokens;
	}

	public function register(): void {
		add_filter( 'determine_current_user', array( $this, 'authenticate' ), 20 );
	}

	/**
	 * @param int|false $user_id Valeur déterminée en amont (cookie, etc.).
	 * @return int|false
	 */
	public function authenticate( $user_id ) {
		// Ne pas écraser une identité déjà établie (ex. cookie WordPress).
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}
		if ( $this->running ) {
			return $user_id;
		}

		$token = self::bearer_token();
		if ( null === $token ) {
			return $user_id;
		}

		$this->running = true;
		$resolved      = $this->tokens->validate( $token );
		$this->running = false;

		return $resolved > 0 ? $resolved : $user_id;
	}

	/**
	 * Extrait le jeton Bearer de l'en-tête Authorization (avec repli REDIRECT_*).
	 */
	public static function bearer_token(): ?string {
		$header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		} elseif ( function_exists( 'getallheaders' ) ) {
			foreach ( getallheaders() as $name => $value ) {
				if ( 0 === strcasecmp( $name, 'Authorization' ) ) {
					$header = (string) $value;
					break;
				}
			}
		}

		$header = trim( $header );
		if ( '' === $header || 0 !== stripos( $header, 'bearer ' ) ) {
			return null;
		}
		$token = trim( substr( $header, 7 ) );
		return '' !== $token ? $token : null;
	}
}
