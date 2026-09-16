<?php
/**
 * Étanchéité centrale du statut de compte (H1).
 *
 * La suspension (et la suppression RGPD) d'un compte Postelio n'était appliquée que
 * dans l'endpoint applicatif `/auth`. Un compte suspendu pouvait donc encore obtenir
 * une session WordPress native (`wp-login.php`, XML-RPC, mots de passe d'application)
 * puis appeler l'API en cookie+nonce, ou réutiliser un jeton Bearer encore en cache.
 *
 * Ce garde rend le statut CENTRAL, sur DEUX couches (défense en profondeur) :
 *
 *  1. Authentification — filtre `authenticate` (prio 30, APRÈS la vérification du mot
 *     de passe) : aucune nouvelle session n'est délivrée à un compte non actif, quel
 *     que soit le canal de connexion natif.
 *  2. Autorisation — filtre `user_has_cap` : même avec une session/cookie déjà établi
 *     ou un Bearer encore valide, toutes les capabilities `pst_*` sont retirées à un
 *     compte non actif. Chaque `Guard::require_cap()` / `require_all()` échoue donc.
 *
 * Ne retire jamais le rôle WordPress (le rôle = type de compte ; le statut = état).
 * N'affecte pas les comptes sans statut Postelio explicite (admins WP, comptes tiers)
 * : `AccountService::status()` renvoie `active` par défaut. La réactivation
 * (`suspended → active`) restaure immédiatement l'accès normal, sans réparation.
 *
 * @package Postelio\Users\Users
 */

namespace Postelio\Users\Users;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccountStatusGuard {

	/** Code d'erreur d'authentification exposé au canal `/auth` pour un message dédié. */
	public const ERROR_CODE = 'postelio_account_inactive';

	public function register(): void {
		// Couche 1 : bloque la délivrance d'une session native après vérification du
		// mot de passe (prio 30 > wp_authenticate_username_password à 20).
		add_filter( 'authenticate', array( $this, 'block_inactive_login' ), 30, 3 );
		// Couche 2 : retire les capabilities métier à toute session déjà établie.
		add_filter( 'user_has_cap', array( $this, 'revoke_caps_when_inactive' ), 9, 4 );
	}

	/**
	 * Refuse la connexion native d'un compte Postelio non actif (suspendu/supprimé).
	 * N'agit que lorsque l'identité et le mot de passe sont déjà validés (WP_User) ;
	 * laisse tout autre résultat (WP_Error, null) intact.
	 *
	 * @param \WP_User|\WP_Error|null $user Résultat courant de la chaîne d'authentification.
	 * @param string                  $username Non utilisé (contrat du filtre).
	 * @param string                  $password Non utilisé (contrat du filtre).
	 * @return \WP_User|\WP_Error|null
	 */
	public function block_inactive_login( $user, $username = '', $password = '' ) {
		if ( ! ( $user instanceof \WP_User ) ) {
			return $user;
		}
		if ( AccountService::STATUS_ACTIVE !== AccountService::status( (int) $user->ID ) ) {
			return new \WP_Error( self::ERROR_CODE, __( 'Compte indisponible.', 'postelio-users' ) );
		}
		return $user;
	}

	/**
	 * Retire toutes les capabilities `pst_*` à un compte non actif. Les capabilities
	 * WordPress natives (read, etc.) sont conservées pour ne pas perturber un compte
	 * WordPress légitime ; seules les capacités métier Postelio sont neutralisées.
	 *
	 * @param array<string, bool> $allcaps Capabilities effectives.
	 * @param string[]            $caps    Capabilities demandées (non utilisé).
	 * @param array<int, mixed>   $args    Arguments de la vérification (non utilisé).
	 * @param \WP_User|null       $user    Utilisateur concerné.
	 * @return array<string, bool>
	 */
	public function revoke_caps_when_inactive( array $allcaps, array $caps, array $args, $user ): array {
		$user_id = ( $user instanceof \WP_User ) ? (int) $user->ID : 0;
		if ( $user_id <= 0 ) {
			return $allcaps;
		}
		if ( AccountService::STATUS_ACTIVE === AccountService::status( $user_id ) ) {
			return $allcaps;
		}
		foreach ( array_keys( $allcaps ) as $cap ) {
			if ( is_string( $cap ) && 0 === strpos( $cap, 'pst_' ) ) {
				unset( $allcaps[ $cap ] );
			}
		}
		return $allcaps;
	}
}
