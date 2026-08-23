<?php
/**
 * Endpoints file de modération (modérateur/admin) :
 *   GET  /moderation/cases                (pst_view_moderation_queue)
 *   GET  /moderation/cases/{uuid}
 *   POST /moderation/cases/{uuid}/assign   (pst_decide_report)
 *   POST /moderation/cases/{uuid}/decision (pst_moderate_content ; actions admin → caps admin)
 *   POST /moderation/cases/{uuid}/note
 *   GET  /moderation/health
 *
 * UUID via params d'URL uniquement. Non-divulgation : non accessible aux non-modérateurs.
 *
 * @package Postelio\Moderation\Http
 */

namespace Postelio\Moderation\Http;

use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Core\Support\Response;
use Postelio\Moderation\Actions\ModerationActions;
use Postelio\Moderation\Api\ModerationDirectory;
use Postelio\Moderation\Cases\CaseService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CaseController extends Controller {

	private const UUID = '(?P<uuid>[0-9a-fA-F-]{36})';

	private CaseService $cases;

	public function __construct( CaseService $cases ) {
		$this->cases = $cases;
	}

	public function register_routes(): void {
		$ns    = $this->namespace();
		$queue = Guard::require_cap( 'pst_view_moderation_queue' );
		$decide = Guard::require_cap( 'pst_decide_report' );
		$act    = Guard::require_cap( 'pst_moderate_content' );

		register_rest_route( $ns, '/moderation/cases', array( 'methods' => 'GET', 'permission_callback' => $queue, 'callback' => $this->guarded( array( $this, 'list' ) ) ) );
		register_rest_route( $ns, '/moderation/cases/' . self::UUID, array( 'methods' => 'GET', 'permission_callback' => $queue, 'callback' => $this->guarded( array( $this, 'detail' ) ) ) );
		register_rest_route( $ns, '/moderation/cases/' . self::UUID . '/assign', array( 'methods' => 'POST', 'permission_callback' => $decide, 'callback' => $this->guarded( array( $this, 'assign' ) ) ) );
		register_rest_route( $ns, '/moderation/cases/' . self::UUID . '/decision', array( 'methods' => 'POST', 'permission_callback' => $act, 'callback' => $this->guarded( array( $this, 'decision' ) ) ) );
		register_rest_route( $ns, '/moderation/cases/' . self::UUID . '/note', array( 'methods' => 'POST', 'permission_callback' => $act, 'callback' => $this->guarded( array( $this, 'note' ) ) ) );
		register_rest_route( $ns, '/moderation/health', array( 'methods' => 'GET', 'permission_callback' => $queue, 'callback' => $this->guarded( array( $this, 'health' ) ) ) );
	}

	public function list( \WP_REST_Request $r ): \WP_REST_Response {
		$page     = max( 1, (int) ( $r->get_param( 'page' ) ?: 1 ) );
		$per_page = Response::clamp_per_page( (int) ( $r->get_param( 'per_page' ) ?: 20 ) );
		$filters  = array(
			'status'        => (string) ( $r->get_param( 'status' ) ?? '' ),
			'priority'      => (string) ( $r->get_param( 'priority' ) ?? '' ),
			'resource_type' => (string) ( $r->get_param( 'resource_type' ) ?? '' ),
		);
		$res   = $this->cases->cases()->list( $filters, $page, $per_page );
		$items = array_map( static fn( $c ) => ModerationPresenter::case_view( $c ), $res['items'] );
		return $this->raw( Response::paginated( $items, $page, $per_page, (int) $res['total'] ) );
	}

	public function detail( \WP_REST_Request $r ): \WP_REST_Response {
		$case = $this->cases->cases()->get_by_uuid( self::uuid( $r ) );
		if ( null === $case ) {
			throw \Postelio\Core\ApiError::not_found();
		}
		$events = $this->cases->events()->list_for_case( (int) $case['id'] );
		return $this->ok( ModerationPresenter::case_view( $case, $events ) );
	}

	public function assign( \WP_REST_Request $r ): \WP_REST_Response {
		$case = $this->cases->assign( self::uuid( $r ), get_current_user_id() );
		return $this->ok( ModerationPresenter::case_view( $case ) );
	}

	public function decision( \WP_REST_Request $r ): \WP_REST_Response {
		$b      = (array) $r->get_json_params();
		$action = (string) ( $b['action'] ?? '' );
		if ( ! ModerationActions::is_valid( $action ) ) {
			throw \Postelio\Core\ApiError::validation( array( 'action' => 'Action inconnue.' ) );
		}
		$target = ( isset( $b['target'] ) && is_array( $b['target'] ) ) ? $b['target'] : null;
		$case   = $this->cases->decide(
			self::uuid( $r ),
			get_current_user_id(),
			$action,
			array_values( array_filter( array_map( 'strval', (array) ( $b['reason_codes'] ?? array() ) ) ) ),
			(string) ( $b['note'] ?? '' ),
			isset( $b['resolve'] ) ? (bool) $b['resolve'] : true,
			$target
		);
		return $this->ok( ModerationPresenter::case_view( $case ) );
	}

	public function note( \WP_REST_Request $r ): \WP_REST_Response {
		$note = (string) ( ( (array) $r->get_json_params() )['note'] ?? '' );
		$case = $this->cases->note( self::uuid( $r ), get_current_user_id(), $note );
		return $this->ok( ModerationPresenter::case_view( $case ) );
	}

	public function health(): \WP_REST_Response {
		global $wpdb;
		$cases = \Postelio\Moderation\Cases\CaseRepository::table();
		$reports = \Postelio\Moderation\Reports\ReportRepository::table();
		return $this->ok( array(
			'open_cases'      => ModerationDirectory::open_cases_count(),
			'reports_total'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$reports}" ),
			'cases_total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cases}" ),
			'provider'        => ( apply_filters( 'postelio/moderation/provider', null ) ) ? 'external' : 'local_only',
		) );
	}

	private static function uuid( \WP_REST_Request $r ): string {
		return (string) ( $r->get_url_params()['uuid'] ?? '' );
	}
}
