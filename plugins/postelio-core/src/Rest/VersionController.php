<?php
/**
 * GET /postelio/v1/version — versions plateforme / API / runtime (public).
 *
 * @package Postelio\Core\Rest
 */

namespace Postelio\Core\Rest;

use Postelio\Core\Permissions\Guard;
use Postelio\Core\Plugin;
use Postelio\Core\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VersionController extends Controller {

	private Registry $registry;

	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace(),
			'/version',
			array(
				'methods'             => 'GET',
				'permission_callback' => Guard::public_access(),
				'callback'            => $this->guarded(
					function (): \WP_REST_Response {
						$modules = array();
						foreach ( $this->registry->all() as $name => $meta ) {
							$modules[ $name ] = $meta['version'];
						}
						return $this->ok(
							array(
								'platform' => (string) get_option( Plugin::PLATFORM_VERSION_OPTION, POSTELIO_CORE_VERSION ),
								'api'      => POSTELIO_REST_NAMESPACE,
								'core'     => POSTELIO_CORE_VERSION,
								'php'      => PHP_VERSION,
								'wordpress' => get_bloginfo( 'version' ),
								'modules'  => $modules,
							)
						);
					}
				),
			)
		);
	}
}
