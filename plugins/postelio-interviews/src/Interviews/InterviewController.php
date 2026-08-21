<?php
/**
 * Endpoints entretiens (namespace `postelio/v1`), UUID uniquement.
 *
 * Candidat :
 *   GET  /me/interviews                       (liste + filtres status/date)
 *   GET  /me/interviews/{uuid}
 *   POST /me/interviews/{uuid}/confirm
 *   POST /me/interviews/{uuid}/decline
 *   POST /me/interviews/{uuid}/reschedule
 * Recruteur :
 *   GET  /companies/me/interviews
 *   GET  /companies/me/interviews/{uuid}
 *   POST /companies/me/applications/{application_uuid}/interviews   (proposer)
 *   PUT  /companies/me/interviews/{uuid}                            (modifier)
 *   POST /companies/me/interviews/{uuid}/accept-reschedule
 *   POST /companies/me/interviews/{uuid}/cancel
 *   POST /companies/me/interviews/{uuid}/complete
 *
 * @package Postelio\Interviews\Interviews
 */

namespace Postelio\Interviews\Interviews;

use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Core\Support\Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class InterviewController extends Controller {

	private const UUID     = '(?P<uuid>[0-9a-fA-F-]{36})';
	private const APP_UUID = '(?P<application_uuid>[0-9a-fA-F-]{36})';

	private InterviewService $svc;

	public function __construct( InterviewService $svc ) {
		$this->svc = $svc;
	}

	public function register_routes(): void {
		$ns = $this->namespace();

		// --- Candidat ---
		$cand_read    = Guard::require_cap( 'pst_view_own_interviews' );
		$cand_confirm = Guard::require_all( 'pst_confirm_interview', 'pst_email_verified' );
		$cand_decline = Guard::require_cap( 'pst_reject_interview' );
		$cand_resched = Guard::require_all( 'pst_reschedule_interview', 'pst_email_verified' );

		register_rest_route( $ns, '/me/interviews', array(
			'methods' => 'GET', 'permission_callback' => $cand_read, 'callback' => $this->guarded( array( $this, 'list_mine' ) ),
		) );
		register_rest_route( $ns, '/me/interviews/' . self::UUID, array(
			'methods' => 'GET', 'permission_callback' => $cand_read, 'callback' => $this->guarded( array( $this, 'detail' ) ),
		) );
		register_rest_route( $ns, '/me/interviews/' . self::UUID . '/confirm', array(
			'methods' => 'POST', 'permission_callback' => $cand_confirm, 'callback' => $this->guarded( array( $this, 'confirm' ) ),
		) );
		register_rest_route( $ns, '/me/interviews/' . self::UUID . '/decline', array(
			'methods' => 'POST', 'permission_callback' => $cand_decline, 'callback' => $this->guarded( array( $this, 'decline' ) ),
		) );
		register_rest_route( $ns, '/me/interviews/' . self::UUID . '/reschedule', array(
			'methods' => 'POST', 'permission_callback' => $cand_resched, 'callback' => $this->guarded( array( $this, 'reschedule' ) ),
		) );

		// --- Recruteur ---
		$rec_read    = Guard::require_cap( 'pst_manage_company_interviews' );
		$rec_manage  = Guard::require_all( 'pst_manage_company_interviews', 'pst_email_verified' );
		$rec_propose = Guard::require_all( 'pst_propose_interview', 'pst_email_verified' );
		$rec_cancel  = Guard::require_all( 'pst_cancel_interview', 'pst_email_verified' );

		register_rest_route( $ns, '/companies/me/interviews', array(
			'methods' => 'GET', 'permission_callback' => $rec_read, 'callback' => $this->guarded( array( $this, 'list_company' ) ),
		) );
		register_rest_route( $ns, '/companies/me/interviews/' . self::UUID, array(
			array( 'methods' => 'GET', 'permission_callback' => $rec_read, 'callback' => $this->guarded( array( $this, 'detail' ) ) ),
			array( 'methods' => 'PUT', 'permission_callback' => $rec_manage, 'callback' => $this->guarded( array( $this, 'modify' ) ) ),
		) );
		register_rest_route( $ns, '/companies/me/applications/' . self::APP_UUID . '/interviews', array(
			'methods' => 'POST', 'permission_callback' => $rec_propose, 'callback' => $this->guarded( array( $this, 'propose' ) ),
		) );
		register_rest_route( $ns, '/companies/me/interviews/' . self::UUID . '/accept-reschedule', array(
			'methods' => 'POST', 'permission_callback' => $rec_manage, 'callback' => $this->guarded( array( $this, 'accept_reschedule' ) ),
		) );
		register_rest_route( $ns, '/companies/me/interviews/' . self::UUID . '/cancel', array(
			'methods' => 'POST', 'permission_callback' => $rec_cancel, 'callback' => $this->guarded( array( $this, 'cancel' ) ),
		) );
		register_rest_route( $ns, '/companies/me/interviews/' . self::UUID . '/complete', array(
			'methods' => 'POST', 'permission_callback' => $rec_manage, 'callback' => $this->guarded( array( $this, 'complete' ) ),
		) );
	}

	// --- Candidat ---

	public function list_mine( \WP_REST_Request $r ): \WP_REST_Response {
		list( $page, $per_page, $filters ) = $this->query( $r );
		$res = $this->svc->list_for_candidate( get_current_user_id(), $filters, $page, $per_page );
		$items = InterviewPresenter::collection( $res['items'], InterviewService::ROLE_CANDIDATE );
		return $this->raw( Response::paginated( $items, $page, $per_page, $res['total'] ) );
	}

	public function confirm( \WP_REST_Request $r ): \WP_REST_Response {
		$iv = $this->svc->confirm( get_current_user_id(), self::uuid( $r ) );
		return $this->ok( InterviewPresenter::view( $iv, InterviewService::ROLE_CANDIDATE ) );
	}

	public function decline( \WP_REST_Request $r ): \WP_REST_Response {
		$msg = (string) ( ( (array) $r->get_json_params() )['message'] ?? '' );
		$iv  = $this->svc->decline( get_current_user_id(), self::uuid( $r ), $msg );
		return $this->ok( InterviewPresenter::view( $iv, InterviewService::ROLE_CANDIDATE ) );
	}

	public function reschedule( \WP_REST_Request $r ): \WP_REST_Response {
		$iv = $this->svc->request_reschedule( get_current_user_id(), self::uuid( $r ), (array) $r->get_json_params() );
		return $this->ok( InterviewPresenter::view( $iv, InterviewService::ROLE_CANDIDATE ) );
	}

	// --- Recruteur ---

	public function list_company( \WP_REST_Request $r ): \WP_REST_Response {
		$company = CompanyDirectory::company_of_user( get_current_user_id() );
		list( $page, $per_page, $filters ) = $this->query( $r );
		if ( $company <= 0 ) {
			return $this->raw( Response::paginated( array(), $page, $per_page, 0 ) );
		}
		$res   = $this->svc->list_for_company( $company, $filters, $page, $per_page );
		$items = InterviewPresenter::collection( $res['items'], InterviewService::ROLE_RECRUITER );
		return $this->raw( Response::paginated( $items, $page, $per_page, $res['total'] ) );
	}

	public function propose( \WP_REST_Request $r ): \WP_REST_Response {
		$app_uuid = (string) ( $r->get_url_params()['application_uuid'] ?? '' );
		$iv       = $this->svc->propose( get_current_user_id(), $app_uuid, (array) $r->get_json_params() );
		return $this->ok( InterviewPresenter::view( $iv, InterviewService::ROLE_RECRUITER ), array(), 201 );
	}

	public function modify( \WP_REST_Request $r ): \WP_REST_Response {
		$iv = $this->svc->modify( get_current_user_id(), self::uuid( $r ), (array) $r->get_json_params() );
		return $this->ok( InterviewPresenter::view( $iv, InterviewService::ROLE_RECRUITER ) );
	}

	public function accept_reschedule( \WP_REST_Request $r ): \WP_REST_Response {
		$iv = $this->svc->accept_reschedule( get_current_user_id(), self::uuid( $r ) );
		return $this->ok( InterviewPresenter::view( $iv, InterviewService::ROLE_RECRUITER ) );
	}

	public function cancel( \WP_REST_Request $r ): \WP_REST_Response {
		$reason = (string) ( ( (array) $r->get_json_params() )['reason'] ?? '' );
		$iv     = $this->svc->cancel( get_current_user_id(), self::uuid( $r ), $reason );
		return $this->ok( InterviewPresenter::view( $iv, InterviewService::ROLE_RECRUITER ) );
	}

	public function complete( \WP_REST_Request $r ): \WP_REST_Response {
		$iv = $this->svc->complete( get_current_user_id(), self::uuid( $r ) );
		return $this->ok( InterviewPresenter::view( $iv, InterviewService::ROLE_RECRUITER ) );
	}

	// --- Commun ---

	public function detail( \WP_REST_Request $r ): \WP_REST_Response {
		$acc = $this->svc->accessible_or_fail( get_current_user_id(), self::uuid( $r ) );
		$view = InterviewPresenter::view( $acc['interview'], $acc['role'] );
		$view['history'] = InterviewPresenter::history( $this->svc->history()->list_for_interview( (int) $acc['interview']['id'] ) );
		return $this->ok( $view );
	}

	/**
	 * @return array{0:int,1:int,2:array<string,mixed>}
	 */
	private function query( \WP_REST_Request $r ): array {
		$page     = max( 1, (int) ( $r->get_param( 'page' ) ?: 1 ) );
		$per_page = Response::clamp_per_page( (int) ( $r->get_param( 'per_page' ) ?: 20 ) );
		$filters  = array(
			'status'           => (string) ( $r->get_param( 'status' ) ?? '' ),
			'from'             => $this->to_utc_filter( (string) ( $r->get_param( 'from' ) ?? '' ) ),
			'to'               => $this->to_utc_filter( (string) ( $r->get_param( 'to' ) ?? '' ) ),
			'application_uuid' => (string) ( $r->get_param( 'application_uuid' ) ?? '' ),
		);
		return array( $page, $per_page, $filters );
	}

	private function to_utc_filter( string $iso ): string {
		if ( '' === $iso ) {
			return '';
		}
		$utc = InterviewValidator::to_utc( $iso, 'UTC' );
		return null !== $utc ? $utc : '';
	}

	private static function uuid( \WP_REST_Request $r ): string {
		return (string) ( $r->get_url_params()['uuid'] ?? '' );
	}
}
