<?php
/**
 * GET /postelio/v1/config — configuration publique non sensible pour les clients
 * (web + Tauri). Ne renvoie AUCUN secret. Filtrable par les plugins métier.
 *
 * @package Postelio\Core\Rest
 */

namespace Postelio\Core\Rest;

use Postelio\Core\Permissions\Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ConfigController extends Controller {

	public function register_routes(): void {
		register_rest_route(
			$this->namespace(),
			'/config',
			array(
				'methods'             => 'GET',
				'permission_callback' => Guard::public_access(),
				'callback'            => $this->guarded(
					function (): \WP_REST_Response {
						$config = array(
							'api_namespace' => POSTELIO_REST_NAMESPACE,
							'locale'        => get_locale(),
							'site_name'     => get_bloginfo( 'name' ),
						);

						/**
						 * Permet aux plugins métier d'ajouter des clés publiques
						 * NON sensibles (ex. listes de secteurs, options d'UI).
						 *
						 * @param array<string, mixed> $config
						 */
						$config = apply_filters( 'postelio/public_config', $config );

						return $this->ok( $config );
					}
				),
			)
		);
	}
}
