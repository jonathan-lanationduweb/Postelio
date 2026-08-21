<?php
/**
 * Cycle de vie des comptes : inscription, connexion, statut, vérification e-mail
 * (optionnelle), anonymisation/suppression (RGPD, préparée), export (RGPD).
 *
 * Utilise l'infrastructure du core (Events, ApiError, Capabilities). Ne gère QUE
 * le domaine users (aucune entreprise/offre/candidature).
 *
 * @package Postelio\Users\Users
 */

namespace Postelio\Users\Users;

use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Capabilities;
use Postelio\Core\Plugin as Core;
use Postelio\Users\Auth\TokenService;
use Postelio\Users\Profiles\CandidateProfileRepository;
use Postelio\Users\Profiles\RecruiterProfileRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccountService {

	public const META_STATUS         = 'postelio_status';
	public const META_EMAIL_VERIFIED = 'postelio_email_verified_at';
	public const META_EMAIL_VERIFY   = 'postelio_email_verify';
	public const META_CREATED_AT     = 'postelio_created_at';
	public const META_LAST_LOGIN     = 'postelio_last_login_at';

	public const STATUS_ACTIVE    = 'active';
	public const STATUS_SUSPENDED = 'suspended';
	public const STATUS_DELETED   = 'deleted';

	private CandidateProfileRepository $candidates;
	private RecruiterProfileRepository $recruiters;

	public function __construct( CandidateProfileRepository $candidates, RecruiterProfileRepository $recruiters ) {
		$this->candidates = $candidates;
		$this->recruiters = $recruiters;
	}

	/** La vérification e-mail est-elle exigée ? (À VALIDER — désactivée par défaut.) */
	public static function verification_required(): bool {
		return (bool) apply_filters( 'postelio/require_email_verification', false );
	}

	public static function role_slug( \WP_User $user ): string {
		if ( in_array( Capabilities::ROLE_RECRUITER, (array) $user->roles, true ) ) {
			return 'recruiter';
		}
		if ( in_array( Capabilities::ROLE_CANDIDATE, (array) $user->roles, true ) ) {
			return 'candidate';
		}
		return (string) ( $user->roles[0] ?? '' );
	}

	public static function status( int $user_id ): string {
		$s = (string) get_user_meta( $user_id, self::META_STATUS, true );
		return '' !== $s ? $s : self::STATUS_ACTIVE;
	}

	/**
	 * Inscription. Retourne l'ID du nouvel utilisateur.
	 *
	 * @param array<string, mixed> $input email, password, role, display_name?
	 * @throws ApiError validation_error | conflict
	 */
	public function register( array $input ): int {
		$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$pass  = (string) ( $input['password'] ?? '' );
		$role  = (string) ( $input['role'] ?? 'candidate' );

		$errors = array();
		if ( ! is_email( $email ) ) {
			$errors['email'] = 'E-mail invalide.';
		}
		if ( strlen( $pass ) < 8 ) {
			$errors['password'] = 'Mot de passe trop court (8 caractères minimum).';
		}
		if ( ! in_array( $role, array( 'candidate', 'recruiter' ), true ) ) {
			$errors['role'] = 'Rôle invalide (candidate ou recruiter).';
		}
		if ( ! empty( $errors ) ) {
			throw ApiError::validation( $errors );
		}
		if ( email_exists( $email ) ) {
			throw new ApiError( 'conflict', 'Un compte existe déjà pour cet e-mail.' );
		}

		$wp_role = 'recruiter' === $role ? Capabilities::ROLE_RECRUITER : Capabilities::ROLE_CANDIDATE;
		$login   = $this->unique_login( $email );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $pass,
				'display_name' => sanitize_text_field( (string) ( $input['display_name'] ?? $login ) ),
				'role'         => $wp_role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new ApiError( 'server_error', 'Création du compte impossible.', array( 'wp' => $user_id->get_error_message() ) );
		}
		$user_id = (int) $user_id;

		update_user_meta( $user_id, self::META_STATUS, self::STATUS_ACTIVE );
		update_user_meta( $user_id, self::META_CREATED_AT, current_time( 'mysql', true ) );

		if ( 'recruiter' === $role ) {
			$this->recruiters->create_for( $user_id );
		} else {
			$this->candidates->create_for( $user_id );
		}

		if ( self::verification_required() ) {
			$this->issue_email_verification( $user_id );
		} else {
			update_user_meta( $user_id, self::META_EMAIL_VERIFIED, current_time( 'mysql', true ) );
		}

		Core::instance()->events()->emit(
			'user.created',
			array(
				'id'            => $user_id,
				'resource_type' => 'user',
				'resource_id'   => (string) $user_id,
				'audit'         => array( 'role' => $role ),
			)
		);

		return $user_id;
	}

	/**
	 * Authentifie par e-mail/identifiant + mot de passe.
	 *
	 * @throws ApiError unauthenticated | forbidden
	 */
	public function authenticate( string $login, string $password ): \WP_User {
		$user = wp_authenticate( $login, $password );
		if ( is_wp_error( $user ) ) {
			throw ApiError::unauthenticated( 'Identifiants invalides.' );
		}
		$status = self::status( (int) $user->ID );
		if ( self::STATUS_ACTIVE !== $status ) {
			throw ApiError::forbidden( 'Compte indisponible.' );
		}
		update_user_meta( (int) $user->ID, self::META_LAST_LOGIN, current_time( 'mysql', true ) );
		return $user;
	}

	/**
	 * Génère et enregistre un jeton de vérification e-mail ; tente l'envoi (best-effort).
	 */
	public function issue_email_verification( int $user_id ): string {
		$token = wp_generate_password( 32, false );
		update_user_meta(
			$user_id,
			self::META_EMAIL_VERIFY,
			array(
				'hash'    => hash( 'sha256', $token ),
				'expires' => time() + DAY_IN_SECONDS,
			)
		);

		$user = get_userdata( $user_id );
		if ( $user ) {
			$link = add_query_arg(
				array(
					'rest_route' => '/postelio/v1/auth/verify-email',
					'uid'        => $user_id,
					'token'      => $token,
				),
				home_url( '/' )
			);
			wp_mail(
				$user->user_email,
				__( 'Vérifiez votre adresse e-mail — Postelio', 'postelio-users' ),
				sprintf( "Confirmez votre e-mail : %s\n", $link )
			);
		}
		return $token;
	}

	/**
	 * Vérifie l'e-mail via le jeton. Retourne true si validé.
	 */
	public function verify_email( int $user_id, string $token ): bool {
		$entry = get_user_meta( $user_id, self::META_EMAIL_VERIFY, true );
		if ( ! is_array( $entry ) || empty( $entry['hash'] ) ) {
			return false;
		}
		if ( (int) ( $entry['expires'] ?? 0 ) < time() ) {
			return false;
		}
		if ( ! hash_equals( (string) $entry['hash'], hash( 'sha256', $token ) ) ) {
			return false;
		}
		update_user_meta( $user_id, self::META_EMAIL_VERIFIED, current_time( 'mysql', true ) );
		delete_user_meta( $user_id, self::META_EMAIL_VERIFY );
		return true;
	}

	public function is_email_verified( int $user_id ): bool {
		return '' !== (string) get_user_meta( $user_id, self::META_EMAIL_VERIFIED, true );
	}

	/**
	 * Suppression RGPD (préparée) : anonymisation + marquage `deleted`. Conserve la
	 * ligne utilisateur (référencée ailleurs) mais purge les données personnelles du
	 * domaine users et révoque les jetons.
	 */
	public function anonymize( int $user_id ): void {
		$this->candidates->delete_for( $user_id );
		$this->recruiters->delete_for( $user_id );

		wp_update_user(
			array(
				'ID'           => $user_id,
				'user_email'   => sprintf( 'deleted+%d@postelio.invalid', $user_id ),
				'display_name' => __( 'Compte supprimé', 'postelio-users' ),
				'first_name'   => '',
				'last_name'    => '',
			)
		);
		update_user_meta( $user_id, self::META_STATUS, self::STATUS_DELETED );
		delete_user_meta( $user_id, self::META_EMAIL_VERIFY );

		( new TokenService() )->revoke_all( $user_id );

		Core::instance()->events()->emit(
			'user.deleted',
			array(
				'id'            => $user_id,
				'resource_type' => 'user',
				'resource_id'   => (string) $user_id,
			)
		);
	}

	/**
	 * Export RGPD des données du domaine users.
	 *
	 * @return array<string, mixed>
	 */
	public function export( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			throw ApiError::not_found();
		}
		$role = self::role_slug( $user );

		return array(
			'account' => array(
				'id'                => $user_id,
				'email'             => $user->user_email,
				'display_name'      => $user->display_name,
				'role'              => $role,
				'status'            => self::status( $user_id ),
				'email_verified_at' => (string) get_user_meta( $user_id, self::META_EMAIL_VERIFIED, true ),
				'created_at'        => (string) get_user_meta( $user_id, self::META_CREATED_AT, true ),
				'last_login_at'     => (string) get_user_meta( $user_id, self::META_LAST_LOGIN, true ),
			),
			'profile' => 'recruiter' === $role
				? $this->recruiters->get_by_user( $user_id )
				: $this->candidates->get_by_user( $user_id ),
		);
	}

	private function unique_login( string $email ): string {
		$base  = sanitize_user( current( explode( '@', $email ) ), true );
		$base  = '' !== $base ? $base : 'user';
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . $i;
			++$i;
		}
		return $login;
	}
}
