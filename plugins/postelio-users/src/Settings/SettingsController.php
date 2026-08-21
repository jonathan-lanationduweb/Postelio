<?php
/**
 * Préférences de compte + export/suppression RGPD (préparés).
 *
 * @package Postelio\Users\Settings
 */

namespace Postelio\Users\Settings;

use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Users\Users\AccountService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsController extends Controller {

	private SettingsService $settings;
	private AccountService $accounts;

	public function __construct( SettingsService $settings, AccountService $accounts ) {
		$this->settings = $settings;
		$this->accounts = $accounts;
	}

	public function register_routes(): void {
		$ns   = $this->namespace();
		$auth = Guard::require_cap( 'read' );

		register_rest_route( $ns, '/me/settings', array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => $this->guarded( array( $this, 'get_settings' ) ),
			),
			array(
				'methods'             => 'PUT',
				'permission_callback' => $auth,
				'callback'            => $this->guarded( array( $this, 'put_settings' ) ),
			),
		) );

		register_rest_route( $ns, '/me/export', array(
			'methods'             => 'GET',
			'permission_callback' => Guard::require_cap( 'pst_export_own_data' ),
			'callback'            => $this->guarded( array( $this, 'export' ) ),
		) );

		register_rest_route( $ns, '/me', array(
			'methods'             => 'DELETE',
			'permission_callback' => Guard::require_cap( 'pst_delete_own_account' ),
			'callback'            => $this->guarded( array( $this, 'delete_me' ) ),
		) );
	}

	public function get_settings(): \WP_REST_Response {
		return $this->ok( $this->settings->get( get_current_user_id() ) );
	}

	public function put_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$updated = $this->settings->update( get_current_user_id(), (array) $request->get_json_params() );
		return $this->ok( $updated );
	}

	public function export(): \WP_REST_Response {
		return $this->ok( $this->accounts->export( get_current_user_id() ) );
	}

	public function delete_me(): \WP_REST_Response {
		$uid = get_current_user_id();
		$this->accounts->anonymize( $uid );
		wp_logout();
		return $this->ok( array( 'deleted' => true ) );
	}
}
