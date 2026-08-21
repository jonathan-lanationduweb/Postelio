<?php
/**
 * GET /postelio/v1/health — état de santé interne (public, non sensible).
 *
 * @package Postelio\Core\Rest
 */

namespace Postelio\Core\Rest;

use Postelio\Core\Health\Status;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HealthController extends Controller {

	private Registry $registry;

	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace(),
			'/health',
			array(
				'methods'             => 'GET',
				'permission_callback' => Guard::public_access(),
				'callback'            => $this->guarded(
					function (): \WP_REST_Response {
						$snapshot = ( new Status( $this->registry ) )->snapshot();
						$status   = 'ok' === $snapshot['status'] ? 200 : 503;
						return $this->ok( $snapshot, array(), $status );
					}
				),
			)
		);
	}
}
