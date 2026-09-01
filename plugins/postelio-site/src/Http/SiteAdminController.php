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
use Postelio\Site\Config\ContentReferences;
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

		register_rest_route( $ns, '/site/admin/search', array(
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => $this->guarded( array( $this, 'search' ) ),
		) );
		register_rest_route( $ns, '/site/admin/resolve', array(
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => $this->guarded( array( $this, 'resolve' ) ),
		) );
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

	/** Recherche de contenu métier pour les sélecteurs (via façades propriétaires, lecture seule). */
	public function search( \WP_REST_Request $r ): \WP_REST_Response {
		$type = (string) $r->get_param( 'type' );
		$q    = (string) $r->get_param( 'q' );
		return $this->ok( array( 'items' => ContentReferences::search( $type, $q, 20 ) ) );
	}

	/** Résout des références stockées → libellé + état (missing:true si le contenu n'existe plus). */
	public function resolve( \WP_REST_Request $r ): \WP_REST_Response {
		$type = (string) $r->get_param( 'type' );
		$ids  = array_filter( array_map( 'trim', explode( ',', (string) $r->get_param( 'ids' ) ) ) );
		return $this->ok( array( 'items' => ContentReferences::resolve( $type, $ids ) ) );
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
