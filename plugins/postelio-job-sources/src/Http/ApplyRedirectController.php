<?php
/**
 * Endpoint de redirection candidature externe :
 *   GET /jobs/{uuid}/apply-redirect
 *
 * Résout l'offre externe, vérifie mode/état, revalide l'URL, émet un événement analytics
 * (jamais une candidature), puis répond en **302** vers l'URL officielle/partenaire. Une
 * offre removed/hidden → **410 Gone**. Jamais de row Applications créée.
 *
 * @package Postelio\JobSources\Http
 */

namespace Postelio\JobSources\Http;

use Postelio\Core\Plugin as Core;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\JobSources\Jobs\ExternalJobRepository;
use Postelio\JobSources\Sources\UrlGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApplyRedirectController extends Controller {

	private const UUID = '(?P<uuid>[0-9a-fA-F-]{36})';

	private ExternalJobRepository $repo;

	public function __construct( ExternalJobRepository $repo ) {
		$this->repo = $repo;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace(),
			'/jobs/' . self::UUID . '/apply-redirect',
			array( 'methods' => 'GET', 'permission_callback' => Guard::public_access(), 'callback' => $this->guarded( array( $this, 'redirect' ) ) )
		);
	}

	public function redirect( \WP_REST_Request $r ): \WP_REST_Response {
		$uuid = (string) ( $r->get_url_params()['uuid'] ?? '' );
		$row  = $this->repo->get_by_uuid( $uuid );

		if ( null === $row ) {
			return new \WP_REST_Response( array( 'error' => array( 'code' => 'not_found', 'message' => 'Offre introuvable.' ) ), 404 );
		}
		if ( 'removed' === $row['sync_status'] || 'hidden' === $row['local_visibility'] ) {
			return new \WP_REST_Response( array( 'error' => array( 'code' => 'gone', 'message' => 'Cette offre n\'est plus disponible.' ) ), 410 );
		}
		if ( 'external_redirect' !== $row['application_mode'] ) {
			return new \WP_REST_Response( array( 'error' => array( 'code' => 'invalid_mode', 'message' => 'Redirection non applicable.' ) ), 400 );
		}
		$url = (string) ( $row['external_apply_url'] ?: $row['external_url'] );
		if ( ! UrlGuard::safe_redirect_url( $url ) ) {
			return new \WP_REST_Response( array( 'error' => array( 'code' => 'invalid_url', 'message' => 'Lien de candidature indisponible.' ) ), 404 );
		}

		Core::instance()->events()->emit( 'external_job.apply_redirected', array(
			'job_uuid'      => (string) $row['public_uuid'],
			'source_key'    => (string) $row['source_key'],
			'resource_type' => 'job_source',
		) );

		$resp = new \WP_REST_Response( null, 302 );
		$resp->header( 'Location', $url );
		return $resp;
	}
}
