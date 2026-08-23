<?php
/**
 * Contrat public de SUSPENSION utilisateur (consommé par postelio-moderation). Respecte le
 * statut/soft-delete existant (`AccountService::META_STATUS`). À la suspension : révocation
 * des jetons applicatifs + destruction des sessions WP → nouvelle authentification refusée
 * tant que `suspended` (AccountService::authenticate refuse déjà les comptes non actifs).
 * Réversible. Ne détruit jamais l'utilisateur ni ses données.
 *
 * @package Postelio\Users\Api
 */

namespace Postelio\Users\Api;

use Postelio\Core\Plugin as Core;
use Postelio\Users\Auth\TokenService;
use Postelio\Users\Users\AccountService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UserModeration {

	/** Suspend un utilisateur (par UUID public). Retourne true si appliqué. */
	public static function suspend( string $user_uuid, int $actor_id = 0 ): bool {
		$user_id = UserDirectory::id_from_public_uuid( $user_uuid );
		if ( $user_id <= 0 || AccountService::STATUS_DELETED === AccountService::status( $user_id ) ) {
			return false; // inconnu ou supprimé : rien
		}
		update_user_meta( $user_id, AccountService::META_STATUS, AccountService::STATUS_SUSPENDED );
		( new TokenService() )->revoke_all( $user_id );
		if ( class_exists( '\\WP_Session_Tokens' ) ) {
			\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}
		Core::instance()->events()->emit( 'user.suspended', array( 'id' => $user_id, 'by' => $actor_id, 'resource_type' => 'user', 'resource_id' => (string) $user_id ) );
		return true;
	}

	/** Réactive un utilisateur suspendu (par UUID public). */
	public static function unsuspend( string $user_uuid, int $actor_id = 0 ): bool {
		$user_id = UserDirectory::id_from_public_uuid( $user_uuid );
		if ( $user_id <= 0 || AccountService::STATUS_SUSPENDED !== AccountService::status( $user_id ) ) {
			return false;
		}
		update_user_meta( $user_id, AccountService::META_STATUS, AccountService::STATUS_ACTIVE );
		Core::instance()->events()->emit( 'user.unsuspended', array( 'id' => $user_id, 'by' => $actor_id, 'resource_type' => 'user', 'resource_id' => (string) $user_id ) );
		return true;
	}

	public static function is_suspended( int $user_id ): bool {
		return AccountService::STATUS_SUSPENDED === AccountService::status( $user_id );
	}
}
