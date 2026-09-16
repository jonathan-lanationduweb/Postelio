<?php
/**
 * Contrat public d'onboarding guest, destiné à postelio-applications.
 *
 * Permet de résoudre un candidat par e-mail, de créer un compte candidat INVITÉ après
 * confirmation d'e-mail, et de fournir un lien de « réclamation » (définition du mot de
 * passe) — sans que applications manipule les comptes WordPress ni les meta users.
 *
 * @package Postelio\Users\Api
 */

namespace Postelio\Users\Api;

use Postelio\Core\Permissions\Capabilities;
use Postelio\Users\Profiles\CandidateProfileRepository;
use Postelio\Users\Profiles\RecruiterProfileRepository;
use Postelio\Users\Users\AccountService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GuestOnboarding {

	private static function accounts(): AccountService {
		return new AccountService( new CandidateProfileRepository(), new RecruiterProfileRepository() );
	}

	/** ID du compte CANDIDAT associé à cet e-mail, ou 0 (jamais un autre rôle). */
	public static function find_candidate( string $email ): int {
		$u = get_user_by( 'email', sanitize_email( $email ) );
		if ( ! $u || ! in_array( Capabilities::ROLE_CANDIDATE, (array) $u->roles, true ) ) {
			return 0;
		}
		return (int) $u->ID;
	}

	/**
	 * Crée (ou réutilise) un compte candidat invité. À n'appeler qu'après confirmation
	 * d'e-mail. Retourne l'ID candidat.
	 *
	 * @throws \Postelio\Core\ApiError conflict si l'e-mail est un compte non candidat.
	 */
	public static function invite_or_get_candidate( string $email, string $first, string $last ): int {
		return self::accounts()->create_invited_candidate( $email, $first, $last );
	}

	/** Le compte est-il un compte invité non encore réclamé (mot de passe non défini) ? */
	public static function is_invited( int $user_id ): bool {
		return '' !== (string) get_user_meta( $user_id, AccountService::META_INVITED, true );
	}

	/**
	 * Lien de réclamation du compte (définition du mot de passe) via le flux natif de
	 * réinitialisation. À usage unique, expirant selon la politique WordPress.
	 */
	public static function claim_url( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return '';
		}
		return add_query_arg(
			array( 'login' => rawurlencode( $user->user_login ), 'key' => $key ),
			home_url( '/reinitialiser-mot-de-passe' )
		);
	}
}
