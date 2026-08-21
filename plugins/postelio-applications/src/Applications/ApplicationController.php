<?php
/**
 * Endpoints candidatures (namespace `postelio/v1`). UUID public uniquement.
 *
 *  POST /jobs/{job_uuid}/applications            (candidat + e-mail vérifié)
 *  GET  /me/applications                         (candidat : ses candidatures)
 *  GET  /me/applications/{uuid}                  (candidat : détail + timeline)
 *  POST /me/applications/{uuid}/withdraw         (candidat : retrait)
 *  GET  /companies/me/applications               (recruteur : pipeline, filtres job/statut)
 *  GET  /companies/me/applications/{uuid}        (recruteur : détail + notes + timeline)
 *  POST /companies/me/applications/{uuid}/status (recruteur + e-mail vérifié)
 *  GET/POST /companies/me/applications/{uuid}/notes (recruteur : notes privées)
 *
 * @package Postelio\Applications\Applications
 */

namespace Postelio\Applications\Applications;

use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Core\Support\Response;
use Postelio\Jobs\Api\JobDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApplicationController extends Controller {

	private const UUID = '(?P<uuid>[0-9a-fA-F-]{36})';

	private ApplicationService $service;
	private ApplicationRepository $apps;

	public function __construct( ApplicationService $service, ApplicationRepository $apps ) {
		$this->service = $service;
		$this->apps    = $apps;
	}

	public function register_routes(): void {
		$ns = $this->namespace();

		register_rest_route( $ns, '/jobs/(?P<job_uuid>[0-9a-fA-F-]{36})/applications', array(
			'methods'             => 'POST',
			'permission_callback' => Guard::require_all( 'pst_apply_job', 'pst_email_verified' ),
			'callback'            => $this->guarded( array( $this, 'apply' ) ),
		) );

		register_rest_route( $ns, '/me/applications', array(
			'methods'             => 'GET',
			'permission_callback' => Guard::require_cap( 'pst_view_own_applications' ),
			'callback'            => $this->guarded( array( $this, 'list_mine' ) ),
		) );
		register_rest_route( $ns, '/me/applications/' . self::UUID, array(
			'methods'             => 'GET',
			'permission_callback' => Guard::require_cap( 'pst_view_own_applications' ),
			'callback'            => $this->guarded( array( $this, 'get_mine' ) ),
		) );
		register_rest_route( $ns, '/me/applications/' . self::UUID . '/withdraw', array(
			'methods'             => 'POST',
			'permission_callback' => Guard::require_cap( 'pst_withdraw_own_application' ),
			'callback'            => $this->guarded( array( $this, 'withdraw' ) ),
		) );

		register_rest_route( $ns, '/companies/me/applications', array(
			'methods'             => 'GET',
			'permission_callback' => Guard::require_cap( 'pst_view_company_applications' ),
			'callback'            => $this->guarded( array( $this, 'list_company' ) ),
		) );
		register_rest_route( $ns, '/companies/me/applications/' . self::UUID, array(
			'methods'             => 'GET',
			'permission_callback' => Guard::require_cap( 'pst_view_company_applications' ),
			'callback'            => $this->guarded( array( $this, 'get_company' ) ),
		) );
		register_rest_route( $ns, '/companies/me/applications/' . self::UUID . '/status', array(
			'methods'             => 'POST',
			'permission_callback' => Guard::require_all( 'pst_change_application_status', 'pst_email_verified' ),
			'callback'            => $this->guarded( array( $this, 'change_status' ) ),
		) );
		register_rest_route( $ns, '/companies/me/applications/' . self::UUID . '/notes', array(
			array( 'methods' => 'GET', 'permission_callback' => Guard::require_cap( 'pst_manage_recruiter_notes' ), 'callback' => $this->guarded( array( $this, 'notes_get' ) ) ),
			array( 'methods' => 'POST', 'permission_callback' => Guard::require_cap( 'pst_manage_recruiter_notes' ), 'callback' => $this->guarded( array( $this, 'notes_post' ) ) ),
		) );
	}

	// --- Candidat ----------------------------------------------------------

	public function apply( \WP_REST_Request $r ): \WP_REST_Response {
		$job_uuid = (string) ( $r->get_url_params()['job_uuid'] ?? '' );
		$app      = $this->service->apply( get_current_user_id(), $job_uuid, (array) $r->get_json_params() );
		$view     = ApplicationPresenter::candidate_view( $app, $this->service->history()->timeline( (int) $app['id'] ) );
		return $this->ok( $view, array(), 201 );
	}

	public function list_mine( \WP_REST_Request $r ): \WP_REST_Response {
		$page     = max( 1, (int) $r->get_param( 'page' ) );
		$per_page = Response::clamp_per_page( (int) $r->get_param( 'per_page' ) );
		$filters  = array();
		if ( $r->get_param( 'status' ) ) {
			$filters['status'] = sanitize_text_field( (string) $r->get_param( 'status' ) );
		}
		$res   = $this->apps->list_for_candidate( get_current_user_id(), $filters, $page, $per_page );
		$items = array_map( array( ApplicationPresenter::class, 'candidate_row' ), $res['items'] );
		return $this->raw( Response::paginated( $items, $page, $per_page, (int) $res['total'] ) );
	}

	public function get_mine( \WP_REST_Request $r ): \WP_REST_Response {
		$app = $this->service->candidate_scope_or_fail( get_current_user_id(), self::uuid( $r ) );
		return $this->ok( ApplicationPresenter::candidate_view( $app, $this->service->history()->timeline( (int) $app['id'] ) ) );
	}

	public function withdraw( \WP_REST_Request $r ): \WP_REST_Response {
		$app = $this->service->withdraw( get_current_user_id(), self::uuid( $r ) );
		return $this->ok( ApplicationPresenter::candidate_view( $app, $this->service->history()->timeline( (int) $app['id'] ) ) );
	}

	// --- Recruteur ---------------------------------------------------------

	public function list_company( \WP_REST_Request $r ): \WP_REST_Response {
		$company = CompanyDirectory::company_of_user( get_current_user_id() );
		if ( 0 === $company ) {
			throw ApiError::not_found( 'Aucune entreprise rattachée.' );
		}
		$page     = max( 1, (int) $r->get_param( 'page' ) );
		$per_page = Response::clamp_per_page( (int) $r->get_param( 'per_page' ) );
		$filters  = array();
		if ( $r->get_param( 'status' ) ) {
			$filters['status'] = sanitize_text_field( (string) $r->get_param( 'status' ) );
		}
		if ( $r->get_param( 'job' ) ) {
			$jid = JobDirectory::id_from_uuid( (string) $r->get_param( 'job' ) );
			$filters['job_id'] = $jid ?: -1; // uuid inconnu -> aucun résultat
		}
		$res   = $this->apps->list_for_company( $company, $filters, $page, $per_page );
		$items = array_map( array( ApplicationPresenter::class, 'recruiter_row' ), $res['items'] );
		return $this->raw( Response::paginated( $items, $page, $per_page, (int) $res['total'] ) );
	}

	public function get_company( \WP_REST_Request $r ): \WP_REST_Response {
		$uid = get_current_user_id();
		$app = $this->service->recruiter_scope_or_fail( $uid, self::uuid( $r ) );
		$view = ApplicationPresenter::recruiter_view(
			$app,
			$this->service->history()->timeline( (int) $app['id'] ),
			$this->service->notes()->list_for_application( (int) $app['id'] )
		);
		return $this->ok( $view );
	}

	public function change_status( \WP_REST_Request $r ): \WP_REST_Response {
		$app = $this->service->change_status( get_current_user_id(), self::uuid( $r ), (array) $r->get_json_params() );
		return $this->ok( ApplicationPresenter::recruiter_view( $app, $this->service->history()->timeline( (int) $app['id'] ), $this->service->notes()->list_for_application( (int) $app['id'] ) ) );
	}

	public function notes_get( \WP_REST_Request $r ): \WP_REST_Response {
		$app = $this->service->recruiter_scope_or_fail( get_current_user_id(), self::uuid( $r ) );
		return $this->ok( $this->service->notes()->list_for_application( (int) $app['id'] ) );
	}

	public function notes_post( \WP_REST_Request $r ): \WP_REST_Response {
		$body  = (string) ( ( (array) $r->get_json_params() )['body'] ?? '' );
		$notes = $this->service->add_note( get_current_user_id(), self::uuid( $r ), $body );
		return $this->ok( $notes, array(), 201 );
	}

	/** UUID depuis les params d'URL uniquement (le body ne peut pas détourner la ressource). */
	private static function uuid( \WP_REST_Request $r ): string {
		return (string) ( $r->get_url_params()['uuid'] ?? '' );
	}
}
