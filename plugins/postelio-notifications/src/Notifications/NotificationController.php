<?php
/**
 * Endpoints notifications in-app (namespace `postelio/v1`), UUID uniquement.
 *
 *  GET  /me/notifications                 (paginé ; filtres ?unread=1&type=…)
 *  GET  /me/notifications/unread-count
 *  POST /me/notifications/{uuid}/read
 *  POST /me/notifications/read-all
 *
 * Un utilisateur ne voit/altère QUE ses propres notifications (ownership via user_id).
 *
 * @package Postelio\Notifications\Notifications
 */

namespace Postelio\Notifications\Notifications;

use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Core\Support\Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NotificationController extends Controller {

	private const UUID = '(?P<uuid>[0-9a-fA-F-]{36})';

	private NotificationService $svc;

	public function __construct( NotificationService $svc ) {
		$this->svc = $svc;
	}

	public function register_routes(): void {
		$ns   = $this->namespace();
		$auth = Guard::require_cap( 'read' ); // tout utilisateur Postelio authentifié

		register_rest_route( $ns, '/me/notifications', array(
			'methods' => 'GET', 'permission_callback' => $auth, 'callback' => $this->guarded( array( $this, 'list' ) ),
		) );
		register_rest_route( $ns, '/me/notifications/unread-count', array(
			'methods' => 'GET', 'permission_callback' => $auth, 'callback' => $this->guarded( array( $this, 'unread_count' ) ),
		) );
		register_rest_route( $ns, '/me/notifications/read-all', array(
			'methods' => 'POST', 'permission_callback' => $auth, 'callback' => $this->guarded( array( $this, 'read_all' ) ),
		) );
		register_rest_route( $ns, '/me/notifications/' . self::UUID . '/read', array(
			'methods' => 'POST', 'permission_callback' => $auth, 'callback' => $this->guarded( array( $this, 'read' ) ),
		) );
	}

	public function list( \WP_REST_Request $r ): \WP_REST_Response {
		$uid      = get_current_user_id();
		$page     = max( 1, (int) ( $r->get_param( 'page' ) ?: 1 ) );
		$per_page = Response::clamp_per_page( (int) ( $r->get_param( 'per_page' ) ?: 20 ) );
		$filters  = array(
			'unread' => (bool) $r->get_param( 'unread' ),
			'type'   => (string) ( $r->get_param( 'type' ) ?? '' ),
		);
		$res   = $this->svc->list( $uid, $filters, $page, $per_page );
		$items = array_map( array( NotificationPresenter::class, 'view' ), $res['items'] );
		return $this->raw( Response::paginated( $items, $page, $per_page, $res['total'] ) );
	}

	public function unread_count(): \WP_REST_Response {
		return $this->ok( array( 'count' => $this->svc->unread_count( get_current_user_id() ) ) );
	}

	public function read( \WP_REST_Request $r ): \WP_REST_Response {
		$uuid = (string) ( $r->get_url_params()['uuid'] ?? '' );
		$this->svc->mark_read( $uuid, get_current_user_id() );
		return $this->ok( array( 'unread_count' => $this->svc->unread_count( get_current_user_id() ) ) );
	}

	public function read_all(): \WP_REST_Response {
		$this->svc->mark_all_read( get_current_user_id() );
		return $this->ok( array( 'unread_count' => 0 ) );
	}
}
