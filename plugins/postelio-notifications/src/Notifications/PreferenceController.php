<?php
/**
 * Endpoints préférences de notification (namespace `postelio/v1`).
 *
 *  GET /me/notification-preferences
 *  PUT /me/notification-preferences
 *
 * Le serveur reste autoritaire (catalogue/défauts/rôle). Les catégories hors rôle et les
 * canaux inconnus sont ignorés silencieusement.
 *
 * @package Postelio\Notifications\Notifications
 */

namespace Postelio\Notifications\Notifications;

use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PreferenceController extends Controller {

	private PreferenceService $prefs;

	public function __construct( PreferenceService $prefs ) {
		$this->prefs = $prefs;
	}

	public function register_routes(): void {
		$ns   = $this->namespace();
		$auth = Guard::require_cap( 'read' );

		register_rest_route( $ns, '/me/notification-preferences', array(
			array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => $this->guarded( array( $this, 'show' ) ) ),
			array( 'methods' => 'PUT', 'permission_callback' => $auth, 'callback' => $this->guarded( array( $this, 'update' ) ) ),
		) );
	}

	public function show(): \WP_REST_Response {
		return $this->ok( $this->prefs->resolved( get_current_user_id() ) );
	}

	public function update( \WP_REST_Request $r ): \WP_REST_Response {
		$body = (array) $r->get_json_params();
		return $this->ok( $this->prefs->update( get_current_user_id(), $body ) );
	}
}
