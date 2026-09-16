<?php
/**
 * Invalidation des accès après changement/réinitialisation de mot de passe (M3).
 *
 * Auparavant, seul l'endpoint Postelio `/auth/reset-password` révoquait les jetons. Un
 * reset via `wp-login.php?action=rp` ou un changement de mot de passe depuis le profil
 * wp-admin ne révoquait PAS les jetons Bearer applicatifs (indépendants du mot de passe
 * WordPress), qui restaient valides jusqu'à 14 jours.
 *
 * Ce listener centralise l'invalidation sur TOUS les chemins, via les hooks WordPress
 * (jamais dupliqué dans les contrôleurs) :
 *  - `after_password_reset` : réinitialisations (l'API Postelio et wp-login passent par
 *    `reset_password()`). L'utilisateur n'est pas connecté → on révoque les jetons Bearer
 *    ET on détruit les sessions WordPress (aucune session courante à préserver).
 *  - `profile_update` : changement de mot de passe depuis le profil/admin. On révoque les
 *    jetons Bearer (le trou réel) ; les sessions cookie sont déjà invalidées par le
 *    changement de hash (fragment de mot de passe dans le cookie) et la préservation
 *    volontaire de la session courante est laissée au cœur de WordPress.
 *
 * Note : le cookie d'authentification WordPress inclut un fragment du hash du mot de passe ;
 * un changement de mot de passe invalide donc déjà tous les cookies existants. Le trou
 * spécifique corrigé ici est celui des jetons Bearer.
 *
 * @package Postelio\Users\Auth
 */

namespace Postelio\Users\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PasswordChangeListener {

	public function register(): void {
		add_action( 'after_password_reset', array( $this, 'on_password_reset' ), 10, 1 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
	}

	/**
	 * Réinitialisation réussie (reset_password) : révocation complète.
	 *
	 * @param \WP_User|mixed $user
	 */
	public function on_password_reset( $user ): void {
		if ( $user instanceof \WP_User ) {
			$this->invalidate( (int) $user->ID, true );
		}
	}

	/**
	 * Mise à jour de profil : n'agit que si le hash du mot de passe a changé.
	 *
	 * @param int|mixed            $user_id
	 * @param \WP_User|mixed       $old_user_data
	 */
	public function on_profile_update( $user_id, $old_user_data = null ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! ( $old_user_data instanceof \WP_User ) ) {
			return;
		}
		$current = get_userdata( $user_id );
		if ( ! $current ) {
			return;
		}
		if ( ! hash_equals( (string) $old_user_data->user_pass, (string) $current->user_pass ) ) {
			// Mot de passe modifié : révoquer les jetons Bearer (sessions cookie gérées par WP).
			$this->invalidate( $user_id, false );
		}
	}

	private function invalidate( int $user_id, bool $destroy_sessions ): void {
		( new TokenService() )->revoke_all( $user_id );
		if ( $destroy_sessions && class_exists( '\\WP_Session_Tokens' ) ) {
			\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}
	}
}
