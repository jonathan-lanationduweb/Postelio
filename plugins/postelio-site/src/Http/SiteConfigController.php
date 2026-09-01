<?php
/**
 * REST PUBLIC de la configuration du site (namespace `postelio/v1`). Lecture seule, sans
 * authentification : le front public consommera cette configuration de présentation.
 *
 *   GET /site/config          → { version, pages }
 *   GET /site/config/{page}   → valeurs d'une page
 *
 * N'expose AUCUNE donnée admin interne (uniquement de la présentation).
 *
 * @package Postelio\Site\Http
 */

namespace Postelio\Site\Http;

use Postelio\Core\Rest\Controller;
use Postelio\Site\Api\SiteConfigDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteConfigController extends Controller {

	private const PAGE = '(?P<page>[a-z0-9_]+)';

	public function register_routes(): void {
		$ns   = $this->namespace();
		$open = '__return_true';

		register_rest_route( $ns, '/site/config', array(
			'methods'             => 'GET',
			'permission_callback' => $open,
			'callback'            => $this->guarded( array( $this, 'all' ) ),
		) );
		register_rest_route( $ns, '/site/config/' . self::PAGE, array(
			'methods'             => 'GET',
			'permission_callback' => $open,
			'callback'            => $this->guarded( array( $this, 'one' ) ),
		) );
	}

	public function all(): \WP_REST_Response {
		return $this->ok( SiteConfigDirectory::public_config() );
	}

	public function one( \WP_REST_Request $r ): \WP_REST_Response {
		$page = (string) $r->get_param( 'page' );
		if ( ! SiteConfigDirectory::has_page( $page ) ) {
			return new \WP_REST_Response( array( 'error' => array( 'code' => 'not_found', 'message' => 'Page inconnue.' ) ), 404 );
		}
		return $this->ok( array( 'page' => $page, 'values' => SiteConfigDirectory::config( $page ) ) );
	}
}
