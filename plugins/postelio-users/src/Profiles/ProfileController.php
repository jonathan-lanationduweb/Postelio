<?php
/**
 * Endpoints de profils (base) : candidat (self), recruteur (self), et vue recruteur
 * d'un candidat (respect de la visibilité).
 *
 * @package Postelio\Users\Profiles
 */

namespace Postelio\Users\Profiles;

use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Plugin as Core;
use Postelio\Core\Rest\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProfileController extends Controller {

	private CandidateProfileRepository $candidates;
	private RecruiterProfileRepository $recruiters;

	public function __construct( CandidateProfileRepository $candidates, RecruiterProfileRepository $recruiters ) {
		$this->candidates = $candidates;
		$this->recruiters = $recruiters;
	}

	public function register_routes(): void {
		$ns = $this->namespace();

		register_rest_route( $ns, '/candidates/me/profile', array(
			array(
				'methods'             => 'GET',
				'permission_callback' => Guard::require_cap( 'pst_edit_own_profile' ),
				'callback'            => $this->guarded( array( $this, 'get_candidate_self' ) ),
			),
			array(
				'methods'             => 'PUT',
				'permission_callback' => Guard::require_cap( 'pst_edit_own_profile' ),
				'callback'            => $this->guarded( array( $this, 'put_candidate_self' ) ),
			),
		) );

		register_rest_route( $ns, '/recruiters/me/profile', array(
			array(
				'methods'             => 'GET',
				'permission_callback' => Guard::require_cap( 'pst_manage_own_company' ),
				'callback'            => $this->guarded( array( $this, 'get_recruiter_self' ) ),
			),
			array(
				'methods'             => 'PUT',
				'permission_callback' => Guard::require_cap( 'pst_manage_own_company' ),
				'callback'            => $this->guarded( array( $this, 'put_recruiter_self' ) ),
			),
		) );

		register_rest_route( $ns, '/candidates/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'permission_callback' => Guard::require_cap( 'pst_view_company_applications' ),
			'callback'            => $this->guarded( array( $this, 'get_candidate_recruiter_view' ) ),
			'args'                => array(
				'id' => array( 'validate_callback' => static fn( $v ) => ctype_digit( (string) $v ) ),
			),
		) );
	}

	public function get_candidate_self(): \WP_REST_Response {
		$uid     = get_current_user_id();
		$profile = $this->candidates->get_by_user( $uid );
		if ( null === $profile ) {
			$this->candidates->create_for( $uid );
			$profile = $this->candidates->get_by_user( $uid );
		}
		return $this->ok( $profile );
	}

	public function put_candidate_self( \WP_REST_Request $request ): \WP_REST_Response {
		$uid     = get_current_user_id();
		$profile = $this->candidates->update( $uid, (array) $request->get_json_params() );

		Core::instance()->events()->emit(
			'candidate.profile_updated',
			array( 'id' => $uid, 'resource_type' => 'candidate_profile', 'resource_id' => (string) $uid )
		);
		return $this->ok( $profile );
	}

	public function get_recruiter_self(): \WP_REST_Response {
		$uid     = get_current_user_id();
		$profile = $this->recruiters->get_by_user( $uid );
		if ( null === $profile ) {
			$this->recruiters->create_for( $uid );
			$profile = $this->recruiters->get_by_user( $uid );
		}
		return $this->ok( $profile );
	}

	public function put_recruiter_self( \WP_REST_Request $request ): \WP_REST_Response {
		$uid     = get_current_user_id();
		$profile = $this->recruiters->update( $uid, (array) $request->get_json_params() );

		Core::instance()->events()->emit(
			'user.updated',
			array( 'id' => $uid, 'resource_type' => 'recruiter_profile', 'resource_id' => (string) $uid )
		);
		return $this->ok( $profile );
	}

	public function get_candidate_recruiter_view( \WP_REST_Request $request ): \WP_REST_Response {
		$id      = (int) $request->get_param( 'id' );
		$profile = $this->candidates->get_by_user( $id );
		if ( null === $profile ) {
			throw ApiError::not_found();
		}
		if ( 'masque' === ( $profile['profile_visibility'] ?? '' ) ) {
			throw ApiError::not_found(); // profil masqué : indistinct d'un inexistant
		}
		return $this->ok( CandidateProfileRepository::recruiter_view( $profile ) );
	}
}
