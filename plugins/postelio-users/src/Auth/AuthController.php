<?php
/**
 * Endpoints d'authentification et de compte (namespace `postelio/v1`).
 *
 * Réutilise le contrôleur de base, les erreurs, les réponses et le Guard du core.
 * Web : cookies WordPress + nonce (posés à la connexion). App/Tauri : jeton Bearer.
 *
 * @package Postelio\Users\Auth
 */

namespace Postelio\Users\Auth;

use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Plugin as Core;
use Postelio\Core\Rest\Controller;
use Postelio\Users\Users\AccountService;
use Postelio\Users\Users\UserPresenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AuthController extends Controller {

	private AccountService $accounts;
	private UserPresenter $presenter;
	private TokenService $tokens;

	public function __construct( AccountService $accounts, UserPresenter $presenter, TokenService $tokens ) {
		$this->accounts  = $accounts;
		$this->presenter = $presenter;
		$this->tokens    = $tokens;
	}

	public function register_routes(): void {
		$ns     = $this->namespace();
		$public = Guard::public_access();
		$auth   = Guard::require_cap( 'read' );

		register_rest_route( $ns, '/auth/register', array(
			'methods'             => 'POST',
			'permission_callback' => $public,
			'callback'            => $this->guarded( array( $this, 'do_register' ) ),
		) );
		register_rest_route( $ns, '/auth', array(
			'methods'             => 'POST',
			'permission_callback' => $public,
			'callback'            => $this->guarded( array( $this, 'do_login' ) ),
		) );
		register_rest_route( $ns, '/auth/refresh', array(
			'methods'             => 'POST',
			'permission_callback' => $public, // le jeton porté fait foi
			'callback'            => $this->guarded( array( $this, 'do_refresh' ) ),
		) );
		register_rest_route( $ns, '/auth/logout', array(
			'methods'             => 'POST',
			'permission_callback' => $public,
			'callback'            => $this->guarded( array( $this, 'do_logout' ) ),
		) );
		register_rest_route( $ns, '/auth/lost-password', array(
			'methods'             => 'POST',
			'permission_callback' => $public,
			'callback'            => $this->guarded( array( $this, 'do_lost_password' ) ),
		) );
		register_rest_route( $ns, '/auth/reset-password', array(
			'methods'             => 'POST',
			'permission_callback' => $public,
			'callback'            => $this->guarded( array( $this, 'do_reset_password' ) ),
		) );
		register_rest_route( $ns, '/auth/verify-email', array(
			'methods'             => array( 'GET', 'POST' ),
			'permission_callback' => $public,
			'callback'            => $this->guarded( array( $this, 'do_verify_email' ) ),
		) );
		register_rest_route( $ns, '/auth/verify-email/resend', array(
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => $this->guarded( array( $this, 'do_resend_verification' ) ),
		) );
	}

	public function do_register( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = $this->accounts->register( (array) $request->get_json_params() );
		$user    = get_userdata( $user_id );

		$this->establish_web_session( $user );
		$token = $this->tokens->issue( $user_id );

		return $this->ok(
			array(
				'user'       => $this->presenter->present( $user ),
				'token'      => $token['token'],
				'expires_at' => $token['expires_at'],
			),
			array(),
			201
		);
	}

	public function do_login( \WP_REST_Request $request ): \WP_REST_Response {
		$params = (array) $request->get_json_params();
		$login  = (string) ( $params['email'] ?? $params['login'] ?? '' );
		$pass   = (string) ( $params['password'] ?? '' );
		if ( '' === $login || '' === $pass ) {
			throw ApiError::validation( array( 'email' => 'Requis', 'password' => 'Requis' ) );
		}

		$user = $this->accounts->authenticate( $login, $pass );
		$this->establish_web_session( $user );
		$token = $this->tokens->issue( (int) $user->ID );

		return $this->ok(
			array(
				'user'       => $this->presenter->present( $user ),
				'token'      => $token['token'],
				'expires_at' => $token['expires_at'],
			)
		);
	}

	public function do_refresh( \WP_REST_Request $request ): \WP_REST_Response {
		$bearer = TokenAuthenticator::bearer_token();
		if ( null === $bearer ) {
			throw ApiError::unauthenticated( 'Jeton requis.' );
		}
		$new = $this->tokens->refresh( $bearer );
		if ( null === $new ) {
			throw ApiError::unauthenticated( 'Jeton invalide ou expiré.' );
		}
		return $this->ok( array( 'token' => $new['token'], 'expires_at' => $new['expires_at'] ) );
	}

	public function do_logout( \WP_REST_Request $request ): \WP_REST_Response {
		$bearer = TokenAuthenticator::bearer_token();
		if ( null !== $bearer ) {
			$this->tokens->revoke( $bearer );
		}
		if ( is_user_logged_in() ) {
			wp_logout();
		}
		return $this->ok( array( 'logged_out' => true ) );
	}

	public function do_lost_password( \WP_REST_Request $request ): \WP_REST_Response {
		$params = (array) $request->get_json_params();
		$email  = sanitize_email( (string) ( $params['email'] ?? '' ) );

		// Anti-énumération : toujours 200, quel que soit le résultat.
		if ( is_email( $email ) ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$key = get_password_reset_key( $user );
				if ( ! is_wp_error( $key ) ) {
					$link = add_query_arg(
						array( 'login' => rawurlencode( $user->user_login ), 'key' => $key ),
						home_url( '/reinitialiser-mot-de-passe' )
					);
					wp_mail(
						$user->user_email,
						__( 'Réinitialisation de votre mot de passe — Postelio', 'postelio-users' ),
						sprintf( "Réinitialisez votre mot de passe : %s\n", $link )
					);
				}
			}
		}
		return $this->ok( array( 'sent' => true ) );
	}

	public function do_reset_password( \WP_REST_Request $request ): \WP_REST_Response {
		$params   = (array) $request->get_json_params();
		$login    = (string) ( $params['login'] ?? '' );
		$key      = (string) ( $params['key'] ?? '' );
		$password = (string) ( $params['password'] ?? '' );

		if ( '' === $login || '' === $key ) {
			throw ApiError::validation( array( 'key' => 'Lien invalide.' ) );
		}
		if ( strlen( $password ) < 8 ) {
			throw ApiError::validation( array( 'password' => 'Mot de passe trop court (8 caractères minimum).' ) );
		}

		$user = check_password_reset_key( $key, $login );
		if ( is_wp_error( $user ) ) {
			throw new ApiError( 'invalid_transition', 'Lien de réinitialisation invalide ou expiré.' );
		}
		reset_password( $user, $password );
		( new TokenService() )->revoke_all( (int) $user->ID );

		return $this->ok( array( 'reset' => true ) );
	}

	public function do_verify_email( \WP_REST_Request $request ): \WP_REST_Response {
		$uid   = (int) ( $request->get_param( 'uid' ) ?? 0 );
		$token = (string) ( $request->get_param( 'token' ) ?? '' );
		if ( $uid <= 0 || '' === $token ) {
			throw ApiError::validation( array( 'token' => 'Jeton requis.' ) );
		}
		$ok = $this->accounts->verify_email( $uid, $token );
		if ( ! $ok ) {
			throw new ApiError( 'invalid_transition', 'Jeton de vérification invalide ou expiré.' );
		}
		return $this->ok( array( 'email_verified' => true ) );
	}

	public function do_resend_verification( \WP_REST_Request $request ): \WP_REST_Response {
		$uid = get_current_user_id();
		if ( $this->accounts->is_email_verified( $uid ) ) {
			return $this->ok( array( 'email_verified' => true ) );
		}
		$this->accounts->issue_email_verification( $uid );
		return $this->ok( array( 'sent' => true ) );
	}

	/**
	 * Pose une session web (cookie) pour les clients navigateur. Sans effet néfaste
	 * pour les clients applicatifs qui utiliseront le jeton Bearer.
	 */
	private function establish_web_session( \WP_User $user ): void {
		wp_set_current_user( (int) $user->ID );
		wp_set_auth_cookie( (int) $user->ID, true );
	}
}
