<?php
/**
 * Endpoints recherches sauvegardées + alertes (candidat) :
 *   GET    /me/saved-searches
 *   POST   /me/saved-searches
 *   GET    /me/saved-searches/{uuid}
 *   PUT    /me/saved-searches/{uuid}
 *   DELETE /me/saved-searches/{uuid}
 *   POST   /me/saved-searches/{uuid}/preview
 *   POST   /me/saved-searches/{uuid}/run-now
 *
 * Cap unique `pst_manage_own_alerts` (recherches sauvegardées = véhicule des alertes ; §16 :
 * ne pas multiplier les capabilities). Ownership strict (non-propriétaire => 404). Mutations,
 * preview et run-now sont rate-limités (§29). run-now ne peut pas spammer : curseur + deliveries.
 *
 * @package Postelio\Alerts\Http
 */

namespace Postelio\Alerts\Http;

use Postelio\Alerts\Searches\SavedSearchPresenter;
use Postelio\Alerts\Searches\SavedSearchService;
use Postelio\Alerts\Support\RateLimit;
use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Users\Api\UserDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SavedSearchController extends Controller {

	private const UUID = '(?P<uuid>[0-9a-fA-F-]{36})';

	private SavedSearchService $service;

	public function __construct( SavedSearchService $service ) {
		$this->service = $service;
	}

	public function register_routes(): void {
		$ns  = $this->namespace();
		$cap = Guard::require_cap( 'pst_manage_own_alerts' );

		register_rest_route( $ns, '/me/saved-searches', array(
			array( 'methods' => 'GET', 'permission_callback' => $cap, 'callback' => $this->guarded( array( $this, 'list' ) ) ),
			array( 'methods' => 'POST', 'permission_callback' => $cap, 'callback' => $this->guarded( array( $this, 'create' ) ) ),
		) );
		register_rest_route( $ns, '/me/saved-searches/' . self::UUID, array(
			array( 'methods' => 'GET', 'permission_callback' => $cap, 'callback' => $this->guarded( array( $this, 'get' ) ) ),
			array( 'methods' => 'PUT', 'permission_callback' => $cap, 'callback' => $this->guarded( array( $this, 'update' ) ) ),
			array( 'methods' => 'DELETE', 'permission_callback' => $cap, 'callback' => $this->guarded( array( $this, 'delete' ) ) ),
		) );
		register_rest_route( $ns, '/me/saved-searches/' . self::UUID . '/preview', array(
			array( 'methods' => 'POST', 'permission_callback' => $cap, 'callback' => $this->guarded( array( $this, 'preview' ) ) ),
		) );
		register_rest_route( $ns, '/me/saved-searches/' . self::UUID . '/run-now', array(
			array( 'methods' => 'POST', 'permission_callback' => $cap, 'callback' => $this->guarded( array( $this, 'run_now' ) ) ),
		) );
	}

	public function list(): \WP_REST_Response {
		$items = array_map( array( SavedSearchPresenter::class, 'view' ), $this->service->list( get_current_user_id() ) );
		return $this->ok( $items );
	}

	public function get( \WP_REST_Request $r ): \WP_REST_Response {
		$row = $this->service->get( get_current_user_id(), self::uuid( $r ) );
		return $this->ok( SavedSearchPresenter::view( $row ) );
	}

	public function create( \WP_REST_Request $r ): \WP_REST_Response {
		$uid = $this->active_candidate();
		RateLimit::hit( 'ss_write:' . $uid, (int) apply_filters( 'postelio/alerts/rate_write_per_hour', 30 ), HOUR_IN_SECONDS );
		$row = $this->service->create( $uid, (array) $r->get_json_params() );
		return $this->ok( SavedSearchPresenter::view( $row ), array(), 201 );
	}

	public function update( \WP_REST_Request $r ): \WP_REST_Response {
		$uid = $this->active_candidate();
		RateLimit::hit( 'ss_write:' . $uid, (int) apply_filters( 'postelio/alerts/rate_write_per_hour', 30 ), HOUR_IN_SECONDS );
		$row = $this->service->update( $uid, self::uuid( $r ), (array) $r->get_json_params() );
		return $this->ok( SavedSearchPresenter::view( $row ) );
	}

	public function delete( \WP_REST_Request $r ): \WP_REST_Response {
		$uid = $this->active_candidate();
		$this->service->delete( $uid, self::uuid( $r ) );
		return $this->ok( array( 'deleted' => true ) );
	}

	public function preview( \WP_REST_Request $r ): \WP_REST_Response {
		$uid = get_current_user_id();
		RateLimit::hit( 'ss_preview:' . $uid, (int) apply_filters( 'postelio/alerts/rate_preview_per_min', 20 ), MINUTE_IN_SECONDS );
		return $this->ok( $this->service->preview( $uid, self::uuid( $r ) ) );
	}

	public function run_now( \WP_REST_Request $r ): \WP_REST_Response {
		$uid = $this->active_candidate();
		RateLimit::hit( 'ss_run:' . $uid, (int) apply_filters( 'postelio/alerts/rate_run_per_hour', 6 ), HOUR_IN_SECONDS, 'Trop d\'exécutions manuelles ; réessayez plus tard.' );
		return $this->ok( $this->service->run_now( $uid, self::uuid( $r ) ) );
	}

	private function active_candidate(): int {
		$uid = get_current_user_id();
		if ( ! UserDirectory::is_active( $uid ) ) {
			throw ApiError::forbidden( 'Compte suspendu : action indisponible.' );
		}
		return $uid;
	}

	private static function uuid( \WP_REST_Request $r ): string {
		return (string) ( $r->get_url_params()['uuid'] ?? '' );
	}
}
