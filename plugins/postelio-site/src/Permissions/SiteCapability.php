<?php
/**
 * Capacité `pst_manage_site` : gérer la configuration du SITE public. Réservée à l'administration.
 *
 * Plutôt que de muter les rôles en base, on l'accorde dynamiquement (filtre `user_has_cap`) à tout
 * utilisateur disposant déjà de `pst_manage_platform` (admin Postelio) ou `manage_options` (admin
 * WordPress). Les modérateurs et le support n'y ont donc PAS accès. Réversible, sans effet de bord
 * sur les rôles stockés.
 *
 * @package Postelio\Site\Permissions
 */

namespace Postelio\Site\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteCapability {

	public const CAP = 'pst_manage_site';

	public function register(): void {
		add_filter( 'user_has_cap', array( $this, 'grant' ), 10, 3 );
	}

	/**
	 * @param array<string,bool> $allcaps
	 * @param string[]           $caps
	 * @param array<int,mixed>   $args
	 * @return array<string,bool>
	 */
	public function grant( array $allcaps, array $caps, array $args ): array {
		if ( ! empty( $allcaps[ self::CAP ] ) ) {
			return $allcaps;
		}
		if ( ! empty( $allcaps['pst_manage_platform'] ) || ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::CAP ] = true;
		}
		return $allcaps;
	}
}
