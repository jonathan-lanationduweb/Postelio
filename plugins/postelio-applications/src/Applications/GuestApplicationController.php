<?php
/**
 * Endpoints PUBLICS du parcours candidature guest (sans compte).
 *
 *  POST /jobs/{job_uuid}/guest-applications  — soumettre (double opt-in). Réponse générique.
 *  POST /applications/guest/confirm          — confirmer via {uuid, token} signé.
 *
 * Aucun accès à une candidature guest par UUID seul : la confirmation exige le jeton.
 *
 * @package Postelio\Applications\Applications
 */

namespace Postelio\Applications\Applications;

use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GuestApplicationController extends Controller {

	private GuestApplicationService $service;

	public function __construct( GuestApplicationService $service ) {
		$this->service = $service;
	}

	public function register_routes(): void {
		$ns     = $this->namespace();
		$public = Guard::public_access();

		register_rest_route( $ns, '/jobs/(?P<job_uuid>[0-9a-fA-F-]{36})/guest-applications', array(
			'methods'             => 'POST',
			'permission_callback' => $public,
			'callback'            => $this->guarded( array( $this, 'submit' ) ),
		) );

		register_rest_route( $ns, '/applications/guest/confirm', array(
			'methods'             => 'POST',
			'permission_callback' => $public,
			'callback'            => $this->guarded( array( $this, 'confirm' ) ),
		) );
	}

	public function submit( \WP_REST_Request $r ): \WP_REST_Response {
		$job_uuid = (string) ( $r->get_url_params()['job_uuid'] ?? '' );
		$res      = $this->service->submit( $job_uuid, (array) $r->get_json_params() );
		// Réponse générique (anti-énumération) : 202 Accepted.
		return $this->ok( $res, array(), 202 );
	}

	public function confirm( \WP_REST_Request $r ): \WP_REST_Response {
		$b     = (array) $r->get_json_params();
		$uuid  = (string) ( $b['uuid'] ?? '' );
		$token = (string) ( $b['token'] ?? '' );
		if ( ! preg_match( '/^[0-9a-fA-F-]{36}$/', $uuid ) ) {
			throw ApiError::validation( array( 'uuid' => 'Identifiant invalide.' ) );
		}
		return $this->ok( $this->service->confirm( $uuid, $token ) );
	}
}
