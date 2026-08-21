<?php
/**
 * Création / mise à jour / retrait des rôles Postelio et de leurs capabilities.
 *
 * Appelé à l'activation du plugin. Le retrait n'est effectué qu'à la
 * désinstallation explicite (jamais à la désactivation).
 *
 * @package Postelio\Core\Permissions
 */

namespace Postelio\Core\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Roles {

	private const DISPLAY_NAMES = array(
		Capabilities::ROLE_CANDIDATE => 'Postelio — Candidat',
		Capabilities::ROLE_RECRUITER => 'Postelio — Recruteur',
		Capabilities::ROLE_ADMIN     => 'Postelio — Administrateur',
		Capabilities::ROLE_MODERATOR => 'Postelio — Modérateur',
		Capabilities::ROLE_SUPPORT   => 'Postelio — Support',
	);

	/**
	 * Crée (ou resynchronise) les rôles et leurs capabilities. Idempotent.
	 */
	public static function install(): void {
		foreach ( Capabilities::roles() as $role ) {
			$caps = self::caps_map( Capabilities::for_role( $role ) );
			// Lecture WP de base pour accéder au tableau de bord si nécessaire.
			$caps['read'] = true;

			$existing = get_role( $role );
			if ( null === $existing ) {
				add_role( $role, self::DISPLAY_NAMES[ $role ] ?? $role, $caps );
			} else {
				// Resynchronise : ajoute les manquantes, retire les obsolètes Postelio.
				foreach ( array_keys( $caps ) as $cap ) {
					$existing->add_cap( $cap );
				}
				foreach ( Capabilities::all() as $cap ) {
					if ( ! isset( $caps[ $cap ] ) ) {
						$existing->remove_cap( $cap );
					}
				}
			}
		}

		// L'administrateur WordPress natif reçoit toutes les capabilities Postelio.
		$wp_admin = get_role( 'administrator' );
		if ( null !== $wp_admin ) {
			foreach ( Capabilities::all() as $cap ) {
				$wp_admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Retire les rôles et capabilities Postelio. Réservé à la désinstallation.
	 */
	public static function uninstall(): void {
		foreach ( Capabilities::roles() as $role ) {
			if ( null !== get_role( $role ) ) {
				remove_role( $role );
			}
		}
		$wp_admin = get_role( 'administrator' );
		if ( null !== $wp_admin ) {
			foreach ( Capabilities::all() as $cap ) {
				$wp_admin->remove_cap( $cap );
			}
		}
	}

	/**
	 * @param string[] $caps
	 * @return array<string, bool>
	 */
	private static function caps_map( array $caps ): array {
		$map = array();
		foreach ( $caps as $cap ) {
			$map[ $cap ] = true;
		}
		return $map;
	}
}
