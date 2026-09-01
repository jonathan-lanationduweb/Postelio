<?php
/**
 * Endpoints favoris (candidat) :
 *   GET    /me/favorites/jobs                    (liste paginée)
 *   POST   /me/favorites/jobs/{job_reference}    (ajout idempotent)
 *   DELETE /me/favorites/jobs/{job_reference}    (retrait idempotent)
 *
 * Ownership implicite : le candidat est TOUJOURS celui de la session. Compte suspendu => aucune
 * mutation (403). Offre inconnue => 404. Aucun id SQL exposé (référence = UUID public d'offre).
 *
 * @package Postelio\Alerts\Http
 */

namespace Postelio\Alerts\Http;

use Postelio\Alerts\Favorites\FavoriteService;
use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Core\Support\Response;
use Postelio\Users\Api\UserDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FavoritesController extends Controller {

	private const REF = '(?P<ref>[A-Za-z0-9_-]{1,64})';

	private FavoriteService $service;

	public function __construct( FavoriteService $service ) {
		$this->service = $service;
	}

	public function register_routes(): void {
		$ns  = $this->namespace();
		$cap = Guard::require_cap( 'pst_manage_own_favorites' );

		register_rest_route( $ns, '/me/favorites/jobs', array(
			array( 'methods' => 'GET', 'permission_callback' => $cap, 'callback' => $this->guarded( array( $this, 'list' ) ) ),
		) );
		register_rest_route( $ns, '/me/favorites/jobs/' . self::REF, array(
			array( 'methods' => 'POST', 'permission_callback' => $cap, 'callback' => $this->guarded( array( $this, 'add' ) ) ),
			array( 'methods' => 'DELETE', 'permission_callback' => $cap, 'callback' => $this->guarded( array( $this, 'remove' ) ) ),
		) );
	}

	public function list( \WP_REST_Request $r ): \WP_REST_Response {
		$uid      = get_current_user_id();
		$page     = max( 1, (int) $r->get_param( 'page' ) );
		$per_page = Response::clamp_per_page( (int) $r->get_param( 'per_page' ) );
		$res      = $this->service->list( $uid, $page, $per_page );
		return $this->raw( Response::paginated( $res['items'], $page, $per_page, (int) $res['total'] ) );
	}

	public function add( \WP_REST_Request $r ): \WP_REST_Response {
		$uid = $this->active_candidate();
		$view = $this->service->add( $uid, self::ref( $r ) );
		return $this->ok( $view, array(), $view['created'] ? 201 : 200 );
	}

	public function remove( \WP_REST_Request $r ): \WP_REST_Response {
		$uid = $this->active_candidate();
		$this->service->remove( $uid, self::ref( $r ) );
		return $this->ok( array( 'removed' => true ) );
	}

	/** Exige un compte actif pour toute mutation (§17). */
	private function active_candidate(): int {
		$uid = get_current_user_id();
		if ( ! UserDirectory::is_active( $uid ) ) {
			throw ApiError::forbidden( 'Compte suspendu : action indisponible.' );
		}
		return $uid;
	}

	private static function ref( \WP_REST_Request $r ): string {
		return (string) ( $r->get_url_params()['ref'] ?? '' );
	}
}
