<?php
/**
 * Endpoints des offres (namespace `postelio/v1`). Identification publique par UUID.
 *
 *  GET  /jobs                     (public, liste + filtres)
 *  GET  /jobs/{uuid}              (public ; seulement published/expiring)
 *  GET  /jobs/me                  (recruteur : offres de son entreprise, tous statuts)
 *  POST /jobs                     (recruteur + e-mail vérifié : crée un BROUILLON)
 *  PUT  /jobs/{uuid}              (recruteur + e-mail vérifié : édite)
 *  POST /jobs/{uuid}/publish      (recruteur + e-mail vérifié ; entreprise VERIFIED — D1)
 *  POST /jobs/{uuid}/fill         (recruteur : pourvue)
 *  POST /jobs/{uuid}/archive      (recruteur : archive)
 *  POST /jobs/{uuid}/duplicate    (recruteur + e-mail vérifié : nouveau brouillon)
 *  POST /jobs/{uuid}/status       (admin : suspend | published)
 *
 * @package Postelio\Jobs\Jobs
 */

namespace Postelio\Jobs\Jobs;

use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Core\Support\Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobController extends Controller {

	private JobRepository $jobs;
	private JobService $service;

	public function __construct( JobRepository $jobs, JobService $service ) {
		$this->jobs    = $jobs;
		$this->service = $service;
	}

	private const UUID = '(?P<uuid>[0-9a-fA-F-]{36})';

	public function register_routes(): void {
		$ns = $this->namespace();

		register_rest_route( $ns, '/jobs', array(
			array( 'methods' => 'GET', 'permission_callback' => Guard::public_access(), 'callback' => $this->guarded( array( $this, 'list_public' ) ) ),
			array( 'methods' => 'POST', 'permission_callback' => Guard::require_all( 'pst_edit_own_company_jobs', 'pst_email_verified' ), 'callback' => $this->guarded( array( $this, 'create' ) ) ),
		) );

		register_rest_route( $ns, '/jobs/me', array(
			'methods' => 'GET', 'permission_callback' => Guard::require_cap( 'pst_edit_own_company_jobs' ), 'callback' => $this->guarded( array( $this, 'list_mine' ) ),
		) );

		register_rest_route( $ns, '/jobs/' . self::UUID, array(
			array( 'methods' => 'GET', 'permission_callback' => Guard::public_access(), 'callback' => $this->guarded( array( $this, 'get_public' ) ) ),
			array( 'methods' => 'PUT', 'permission_callback' => Guard::require_all( 'pst_edit_own_company_jobs', 'pst_email_verified' ), 'callback' => $this->guarded( array( $this, 'update' ) ) ),
		) );

		// Actions de cycle de vie.
		register_rest_route( $ns, '/jobs/' . self::UUID . '/publish', array( 'methods' => 'POST', 'permission_callback' => Guard::require_all( 'pst_publish_job', 'pst_email_verified' ), 'callback' => $this->guarded( array( $this, 'publish' ) ) ) );
		register_rest_route( $ns, '/jobs/' . self::UUID . '/fill', array( 'methods' => 'POST', 'permission_callback' => Guard::require_cap( 'pst_edit_own_company_jobs' ), 'callback' => $this->guarded( array( $this, 'fill' ) ) ) );
		register_rest_route( $ns, '/jobs/' . self::UUID . '/archive', array( 'methods' => 'POST', 'permission_callback' => Guard::require_cap( 'pst_edit_own_company_jobs' ), 'callback' => $this->guarded( array( $this, 'archive' ) ) ) );
		register_rest_route( $ns, '/jobs/' . self::UUID . '/duplicate', array( 'methods' => 'POST', 'permission_callback' => Guard::require_all( 'pst_duplicate_job', 'pst_email_verified' ), 'callback' => $this->guarded( array( $this, 'duplicate' ) ) ) );
		register_rest_route( $ns, '/jobs/' . self::UUID . '/status', array( 'methods' => 'POST', 'permission_callback' => Guard::require_cap( 'pst_manage_all_jobs' ), 'callback' => $this->guarded( array( $this, 'admin_status' ) ) ) );
	}

	public function list_public( \WP_REST_Request $r ): \WP_REST_Response {
		$page     = max( 1, (int) $r->get_param( 'page' ) );
		$per_page = Response::clamp_per_page( (int) $r->get_param( 'per_page' ) );
		$filters  = array();
		foreach ( array( 'q', 'ville', 'contrat', 'categorie', 'teletravail', 'niveau_etude', 'experience', 'salaire_min', 'alternance', 'stage', 'debutant' ) as $k ) {
			$v = $r->get_param( $k );
			if ( null !== $v && '' !== $v ) {
				$filters[ $k ] = $v;
			}
		}
		$res   = $this->jobs->list_public( $filters, $page, $per_page );
		$items = array_map( array( JobPresenter::class, 'public_view' ), $res['items'] );
		return $this->raw( Response::paginated( $items, $page, $per_page, (int) $res['total'] ) );
	}

	public function get_public( \WP_REST_Request $r ): \WP_REST_Response {
		$j = $this->jobs->get_by_uuid( (string) $r->get_param( 'uuid' ) );
		if ( null === $j || ! JobStateMachine::is_public( $j['status'] ) ) {
			throw ApiError::not_found();
		}
		return $this->ok( JobPresenter::public_view( $j ) );
	}

	public function list_mine(): \WP_REST_Response {
		$company = \Postelio\Companies\Api\CompanyDirectory::company_of_user( get_current_user_id() );
		if ( 0 === $company ) {
			throw ApiError::not_found( 'Aucune entreprise rattachée.' );
		}
		$items = array_map( array( JobPresenter::class, 'owner_view' ), $this->jobs->list_by_company( $company ) );
		return $this->ok( $items );
	}

	public function create( \WP_REST_Request $r ): \WP_REST_Response {
		$id = $this->service->create( get_current_user_id(), (array) $r->get_json_params() );
		return $this->ok( JobPresenter::owner_view( $this->jobs->get( $id ) ), array(), 201 );
	}

	public function update( \WP_REST_Request $r ): \WP_REST_Response {
		$id = $this->resolve( $r );
		return $this->ok( JobPresenter::owner_view( $this->service->update( get_current_user_id(), $id, (array) $r->get_json_params() ) ) );
	}

	public function publish( \WP_REST_Request $r ): \WP_REST_Response {
		return $this->ok( JobPresenter::owner_view( $this->service->publish( get_current_user_id(), $this->resolve( $r ) ) ) );
	}

	public function fill( \WP_REST_Request $r ): \WP_REST_Response {
		return $this->ok( JobPresenter::owner_view( $this->service->fill( get_current_user_id(), $this->resolve( $r ) ) ) );
	}

	public function archive( \WP_REST_Request $r ): \WP_REST_Response {
		return $this->ok( JobPresenter::owner_view( $this->service->archive( get_current_user_id(), $this->resolve( $r ) ) ) );
	}

	public function duplicate( \WP_REST_Request $r ): \WP_REST_Response {
		return $this->ok( JobPresenter::owner_view( $this->service->duplicate( get_current_user_id(), $this->resolve( $r ) ) ), array(), 201 );
	}

	public function admin_status( \WP_REST_Request $r ): \WP_REST_Response {
		$id       = $this->resolve( $r );
		$decision = (string) ( ( (array) $r->get_json_params() )['decision'] ?? '' );
		return $this->ok( JobPresenter::admin_view( $this->service->admin_transition( get_current_user_id(), $id, $decision ) ) );
	}

	private function resolve( \WP_REST_Request $r ): int {
		$j = $this->jobs->get_by_uuid( (string) $r->get_param( 'uuid' ) );
		if ( null === $j ) {
			throw ApiError::not_found();
		}
		return (int) $j['id'];
	}
}
