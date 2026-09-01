<?php
/**
 * REST ADMIN de la configuration du site (namespace `postelio/v1`). Réservé à `pst_manage_site`.
 * Utilisé par l'éditeur visuel du back-office.
 *
 *   GET  /site/admin/{page}   → { schema, values, version }
 *   POST /site/admin/{page}   → enregistre les valeurs (body: { values }) → { values }
 *
 * L'authentification REST WordPress (cookie + nonce X-WP-Nonce) s'applique via permission_callback.
 *
 * @package Postelio\Site\Http
 */

namespace Postelio\Site\Http;

use Postelio\Core\Rest\Controller;
use Postelio\Site\Api\SiteConfigDirectory;
use Postelio\Site\Config\SiteConfigService;
use Postelio\Site\Permissions\SiteCapability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteAdminController extends Controller {

	private const PAGE = '(?P<page>[a-z0-9_]+)';

	private SiteConfigService $svc;

	public function __construct( ?SiteConfigService $svc = null ) {
		$this->svc = $svc ?? new SiteConfigService();
	}

	public function register_routes(): void {
		$ns   = $this->namespace();
		$auth = static function () {
			return current_user_can( SiteCapability::CAP );
		};

		register_rest_route( $ns, '/site/admin/' . self::PAGE, array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => $this->guarded( array( $this, 'get' ) ),
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'callback'            => $this->guarded( array( $this, 'save' ) ),
			),
		) );
	}

	public function get( \WP_REST_Request $r ): \WP_REST_Response {
		$page = (string) $r->get_param( 'page' );
		if ( ! SiteConfigDirectory::has_page( $page ) ) {
			return new \WP_REST_Response( array( 'error' => array( 'code' => 'not_found', 'message' => 'Page inconnue.' ) ), 404 );
		}
		return $this->ok( array(
			'page'    => $page,
			'version' => SiteConfigDirectory::version(),
			'schema'  => SiteConfigDirectory::schema( $page ),
			'values'  => SiteConfigDirectory::config( $page ),
		) );
	}

	public function save( \WP_REST_Request $r ): \WP_REST_Response {
		$page = (string) $r->get_param( 'page' );
		if ( ! SiteConfigDirectory::has_page( $page ) ) {
			return new \WP_REST_Response( array( 'error' => array( 'code' => 'not_found', 'message' => 'Page inconnue.' ) ), 404 );
		}
		$body   = $r->get_json_params();
		$values = ( is_array( $body ) && isset( $body['values'] ) && is_array( $body['values'] ) ) ? $body['values'] : array();
		$res    = $this->svc->save( $page, $values );
		if ( empty( $res['ok'] ) ) {
			return new \WP_REST_Response( array( 'error' => array( 'code' => 'invalid', 'message' => 'Enregistrement impossible.' ) ), 400 );
		}
		return $this->ok( array( 'page' => $page, 'values' => $res['values'] ), array( 'saved' => true ) );
	}
}
