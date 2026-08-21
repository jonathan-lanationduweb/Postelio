<?php
/**
 * Contrat public STABLE d'identité utilisateur, destiné aux autres plugins
 * (postelio-applications…). Évite qu'ils lisent directement `wp_users`/usermeta
 * ou la table des profils.
 *
 * @package Postelio\Users\Api
 */

namespace Postelio\Users\Api;

use Postelio\Users\Profiles\CandidateProfileRepository;
use Postelio\Users\Users\AccountService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UserDirectory {

	public static function exists( int $user_id ): bool {
		return (bool) get_userdata( $user_id );
	}

	/** Compte actif (ni suspendu ni supprimé). */
	public static function is_active( int $user_id ): bool {
		return self::exists( $user_id ) && AccountService::STATUS_ACTIVE === AccountService::status( $user_id );
	}

	public static function role( int $user_id ): string {
		$u = get_userdata( $user_id );
		return $u ? AccountService::role_slug( $u ) : '';
	}

	public static function is_candidate( int $user_id ): bool {
		return 'candidate' === self::role( $user_id );
	}

	public static function display_name( int $user_id ): string {
		$u = get_userdata( $user_id );
		return $u ? (string) $u->display_name : '';
	}

	/** UUID public du profil candidat (pour la vue recruteur), ou null. */
	public static function candidate_profile_uuid( int $user_id ): ?string {
		$p = ( new CandidateProfileRepository() )->get_by_user( $user_id );
		return $p ? (string) ( $p['public_uuid'] ?? '' ) : null;
	}

	public static function email_verified( int $user_id ): bool {
		return '' !== (string) get_user_meta( $user_id, AccountService::META_EMAIL_VERIFIED, true );
	}
}
