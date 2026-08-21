<?php
/**
 * Endpoints de vérification d'entreprise (namespace `postelio/v1`).
 *
 *  POST /companies/me/verification            (recruteur + e-mail vérifié : demande)
 *  GET  /companies/me/verification            (recruteur : statut)
 *  POST /companies/{uuid}/verification/decision (admin : verified|rejected|manual_review|suspended)
 *
 * Le recruteur ne peut JAMAIS se déclarer `verified` (aucune route ne le permet).
 *
 * @package Postelio\Companies\Verification
 */

namespace Postelio\Companies\Verification;

use Postelio\Companies\Companies\CompanyRepository;
use Postelio\Companies\Members\MembershipService;
use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VerificationController extends Controller {

	private VerificationService $verification;
	private CompanyRepository $companies;
	private MembershipService $memberships;

	public function __construct( VerificationService $verification, CompanyRepository $companies, MembershipService $memberships ) {
		$this->verification = $verification;
		$this->companies    = $companies;
		$this->memberships  = $memberships;
	}

	public function register_routes(): void {
		$ns = $this->namespace();

		register_rest_route( $ns, '/companies/me/verification', array(
			array(
				'methods'             => 'POST',
				'permission_callback' => Guard::require_all( 'pst_request_company_verification', 'pst_email_verified' ),
				'callback'            => $this->guarded( array( $this, 'request' ) ),
			),
			array(
				'methods'             => 'GET',
				'permission_callback' => Guard::require_cap( 'pst_manage_own_company' ),
				'callback'            => $this->guarded( array( $this, 'status' ) ),
			),
		) );

		register_rest_route( $ns, '/companies/(?P<uuid>[0-9a-fA-F-]{36})/verification/decision', array(
			'methods'             => 'POST',
			'permission_callback' => Guard::require_cap( 'pst_verify_company' ),
			'callback'            => $this->guarded( array( $this, 'decide' ) ),
			'args'                => array(
				'uuid' => array( 'validate_callback' => static fn( $v ) => (bool) preg_match( '/^[0-9a-fA-F-]{36}$/', (string) $v ) ),
			),
		) );
	}

	public function request(): \WP_REST_Response {
		$id = $this->memberships->company_of_user( get_current_user_id() );
		if ( 0 === $id ) {
			throw ApiError::not_found( 'Aucune entreprise rattachée.' );
		}
		$verif = $this->verification->request( $id, get_current_user_id() );
		return $this->ok( $this->owner_subset( $verif ) );
	}

	public function status(): \WP_REST_Response {
		$id = $this->memberships->company_of_user( get_current_user_id() );
		if ( 0 === $id ) {
			throw ApiError::not_found( 'Aucune entreprise rattachée.' );
		}
		$c = $this->companies->get( $id );
		return $this->ok( $this->owner_subset( $c['verification'] ?? array( 'status' => 'unverified' ) ) );
	}

	public function decide( \WP_REST_Request $request ): \WP_REST_Response {
		$c = $this->companies->get_by_uuid( (string) $request->get_param( 'uuid' ) );
		if ( null === $c ) {
			throw ApiError::not_found();
		}
		$params   = (array) $request->get_json_params();
		$decision = (string) ( $params['decision'] ?? '' );
		$motif    = sanitize_text_field( (string) ( $params['motif'] ?? '' ) );

		$verif = $this->verification->decide( (int) $c['id'], get_current_user_id(), $decision, $motif );
		return $this->ok( $verif ); // vue admin : détails complets
	}

	/**
	 * Sous-ensemble communiqué au recruteur (motif uniquement si rejet).
	 *
	 * @param array<string, mixed> $verif
	 * @return array<string, mixed>
	 */
	private function owner_subset( array $verif ): array {
		$out = array(
			'status'       => $verif['status'] ?? 'unverified',
			'provider'     => $verif['provider'] ?? null,
			'requested_at' => $verif['requested_at'] ?? null,
			'verified_at'  => $verif['verified_at'] ?? null,
		);
		if ( 'rejected' === ( $verif['status'] ?? '' ) ) {
			$out['motif'] = $verif['motif'] ?? null;
		}
		return $out;
	}
}
