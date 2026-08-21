<?php
/**
 * GET /postelio/v1/me — identité transversale de l'utilisateur courant.
 *
 * Ne renvoie que l'identité de base + rôles + capabilities Postelio. L'enrichissement
 * métier (profil candidat/recruteur) est ajouté PLUS TARD par postelio-users via le
 * filtre `postelio/me`. Aucune donnée métier ici.
 *
 * @package Postelio\Core\Rest
 */

namespace Postelio\Core\Rest;

use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Capabilities;
use Postelio\Core\Permissions\Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MeController extends Controller {

	public function register_routes(): void {
		register_rest_route(
			$this->namespace(),
			'/me',
			array(
				'methods'             => 'GET',
				'permission_callback' => Guard::require_cap( 'read' ),
				'callback'            => $this->guarded(
					function (): \WP_REST_Response {
						$user = wp_get_current_user();
						if ( ! $user || 0 === (int) $user->ID ) {
							throw ApiError::unauthenticated();
						}

						$pst_caps = array();
						foreach ( Capabilities::all() as $cap ) {
							if ( user_can( $user, $cap ) ) {
								$pst_caps[] = $cap;
							}
						}

						$identity = array(
							'id'           => (int) $user->ID,
							'display_name' => $user->display_name,
							'roles'        => array_values( $user->roles ),
							'capabilities' => $pst_caps,
						);

						/**
						 * postelio-users enrichira cette identité (profil, visibilité…).
						 *
						 * @param array<string, mixed> $identity
						 * @param \WP_User              $user
						 */
						$identity = apply_filters( 'postelio/me', $identity, $user );

						return $this->ok( $identity );
					}
				),
			)
		);
	}
}
