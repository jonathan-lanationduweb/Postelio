<?php
/**
 * Endpoints entreprises (namespace `postelio/v1`). Identification publique par UUID.
 *
 *  GET  /companies                 (public, liste)
 *  GET  /companies/{uuid}          (public, détail ; masque les suspendues)
 *  POST /companies                 (recruteur + e-mail vérifié : crée SON entreprise)
 *  GET  /companies/me              (recruteur : son entreprise, vue propriétaire)
 *  PUT  /companies/me              (recruteur + e-mail vérifié : mise à jour)
 *
 * @package Postelio\Companies\Companies
 */

namespace Postelio\Companies\Companies;

use Postelio\Companies\Members\MembershipService;
use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Core\Support\Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CompanyController extends Controller {

	private CompanyRepository $companies;
	private CompanyService $service;
	private MembershipService $memberships;

	public function __construct( CompanyRepository $companies, CompanyService $service, MembershipService $memberships ) {
		$this->companies   = $companies;
		$this->service     = $service;
		$this->memberships = $memberships;
	}

	public function register_routes(): void {
		$ns = $this->namespace();

		register_rest_route( $ns, '/companies', array(
			array(
				'methods'             => 'GET',
				'permission_callback' => Guard::public_access(),
				'callback'            => $this->guarded( array( $this, 'list_public' ) ),
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => Guard::require_all( 'pst_manage_own_company', 'pst_email_verified' ),
				'callback'            => $this->guarded( array( $this, 'create' ) ),
			),
		) );

		register_rest_route( $ns, '/companies/me', array(
			array(
				'methods'             => 'GET',
				'permission_callback' => Guard::require_cap( 'pst_manage_own_company' ),
				'callback'            => $this->guarded( array( $this, 'get_me' ) ),
			),
			array(
				'methods'             => 'PUT',
				'permission_callback' => Guard::require_all( 'pst_manage_own_company', 'pst_email_verified' ),
				'callback'            => $this->guarded( array( $this, 'put_me' ) ),
			),
		) );

		register_rest_route( $ns, '/companies/(?P<uuid>[0-9a-fA-F-]{36})', array(
			'methods'             => 'GET',
			'permission_callback' => Guard::public_access(),
			'callback'            => $this->guarded( array( $this, 'get_public' ) ),
			'args'                => array(
				'uuid' => array( 'validate_callback' => static fn( $v ) => (bool) preg_match( '/^[0-9a-fA-F-]{36}$/', (string) $v ) ),
			),
		) );
	}

	public function list_public( \WP_REST_Request $request ): \WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = Response::clamp_per_page( (int) $request->get_param( 'per_page' ) );
		$res      = $this->companies->list_public( $page, $per_page );

		$items = array();
		foreach ( $res['items'] as $c ) {
			if ( 'suspended' === ( $c['verification']['status'] ?? '' ) ) {
				continue; // ne pas exposer les entreprises suspendues
			}
			$items[] = CompanyPresenter::public_view( $c );
		}
		return $this->raw( Response::paginated( $items, $page, $per_page, (int) $res['total'] ) );
	}

	public function get_public( \WP_REST_Request $request ): \WP_REST_Response {
		$c = $this->companies->get_by_uuid( (string) $request->get_param( 'uuid' ) );
		if ( null === $c || 'suspended' === ( $c['verification']['status'] ?? '' ) ) {
			throw ApiError::not_found();
		}
		return $this->ok( CompanyPresenter::public_view( $c ) );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		$id = $this->service->create( get_current_user_id(), (array) $request->get_json_params() );
		return $this->ok( CompanyPresenter::owner_view( $this->companies->get( $id ) ), array(), 201 );
	}

	public function get_me(): \WP_REST_Response {
		$id = $this->memberships->company_of_user( get_current_user_id() );
		if ( 0 === $id ) {
			throw ApiError::not_found( 'Aucune entreprise rattachée.' );
		}
		return $this->ok( CompanyPresenter::owner_view( $this->companies->get( $id ) ) );
	}

	public function put_me( \WP_REST_Request $request ): \WP_REST_Response {
		$uid = get_current_user_id();
		$id  = $this->memberships->company_of_user( $uid );
		if ( 0 === $id ) {
			throw ApiError::not_found( 'Aucune entreprise rattachée.' );
		}
		$updated = $this->service->update( $uid, $id, (array) $request->get_json_params() );
		return $this->ok( CompanyPresenter::owner_view( $updated ) );
	}
}
