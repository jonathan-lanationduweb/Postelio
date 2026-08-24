<?php
/**
 * Endpoints savoir-faire :
 *   GET  /skills                     (public : published + visible)
 *   GET  /skills/{uuid}              (public)
 *   GET  /me/skills                  (auteur : tous statuts)
 *   POST /me/skills                  (crée un brouillon)
 *   GET  /me/skills/{uuid}
 *   PUT  /me/skills/{uuid}
 *   POST /me/skills/{uuid}/publish
 *   POST /me/skills/{uuid}/archive
 *
 * Corps whitelisté ; auteur/entreprise dérivés du serveur. Non-divulgation 404.
 *
 * @package Postelio\Skills\Http
 */

namespace Postelio\Skills\Http;

use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Core\Support\Response;
use Postelio\Skills\Skills\SkillPresenter;
use Postelio\Skills\Skills\SkillService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SkillController extends Controller {

	private const UUID = '(?P<uuid>[0-9a-fA-F-]{36})';

	private SkillService $service;

	public function __construct( SkillService $service ) {
		$this->service = $service;
	}

	public function register_routes(): void {
		$ns      = $this->namespace();
		$pub     = Guard::public_access();
		$manage  = Guard::require_all( 'pst_manage_own_skill', 'pst_email_verified' );
		$manage_r = Guard::require_cap( 'pst_manage_own_skill' );
		$publish = Guard::require_all( 'pst_publish_own_skill', 'pst_email_verified' );

		register_rest_route( $ns, '/skills', array( 'methods' => 'GET', 'permission_callback' => $pub, 'callback' => $this->guarded( array( $this, 'list_public' ) ) ) );
		register_rest_route( $ns, '/skills/' . self::UUID, array( 'methods' => 'GET', 'permission_callback' => $pub, 'callback' => $this->guarded( array( $this, 'detail' ) ) ) );

		register_rest_route( $ns, '/me/skills', array(
			array( 'methods' => 'GET', 'permission_callback' => $manage_r, 'callback' => $this->guarded( array( $this, 'mine' ) ) ),
			array( 'methods' => 'POST', 'permission_callback' => $manage, 'callback' => $this->guarded( array( $this, 'create' ) ) ),
		) );
		register_rest_route( $ns, '/me/skills/' . self::UUID, array(
			array( 'methods' => 'GET', 'permission_callback' => $manage_r, 'callback' => $this->guarded( array( $this, 'mine_detail' ) ) ),
			array( 'methods' => 'PUT', 'permission_callback' => $manage, 'callback' => $this->guarded( array( $this, 'update' ) ) ),
		) );
		register_rest_route( $ns, '/me/skills/' . self::UUID . '/publish', array( 'methods' => 'POST', 'permission_callback' => $publish, 'callback' => $this->guarded( array( $this, 'publish' ) ) ) );
		register_rest_route( $ns, '/me/skills/' . self::UUID . '/archive', array( 'methods' => 'POST', 'permission_callback' => $manage_r, 'callback' => $this->guarded( array( $this, 'archive' ) ) ) );
	}

	public function list_public( \WP_REST_Request $r ): \WP_REST_Response {
		$page     = max( 1, (int) ( $r->get_param( 'page' ) ?: 1 ) );
		$per_page = Response::clamp_per_page( (int) ( $r->get_param( 'per_page' ) ?: 20 ) );
		$filters  = array(
			'q'           => (string) ( $r->get_param( 'q' ) ?? '' ),
			'category'    => (string) ( $r->get_param( 'category' ) ?? '' ),
			'tag'         => (string) ( $r->get_param( 'tag' ) ?? '' ),
			'author_type' => (string) ( $r->get_param( 'author_type' ) ?? '' ),
			'company_id'  => 0,
			'sort'        => (string) ( $r->get_param( 'sort' ) ?? 'recent' ),
		);
		if ( $r->get_param( 'company' ) && class_exists( '\\Postelio\\Companies\\Api\\CompanyDirectory' ) ) {
			$filters['company_id'] = \Postelio\Companies\Api\CompanyDirectory::id_from_uuid( (string) $r->get_param( 'company' ) );
		}
		$res   = $this->service->repository()->list_public( $filters, $page, $per_page );
		$items = array_map( static fn( $s ) => SkillPresenter::public_view( $s ), $res['items'] );
		return $this->raw( Response::paginated( $items, $page, $per_page, (int) $res['total'] ) );
	}

	public function detail( \WP_REST_Request $r ): \WP_REST_Response {
		$skill = $this->service->repository()->get_by_uuid( self::uuid( $r ) );
		if ( null === $skill || ! $this->service->repository()->is_public_visible( $skill ) ) {
			throw ApiError::not_found();
		}
		return $this->ok( SkillPresenter::public_view( $skill ) );
	}

	public function mine(): \WP_REST_Response {
		$items = array_map( static fn( $s ) => SkillPresenter::author_view( $s ), $this->service->repository()->list_for_user( get_current_user_id() ) );
		return $this->ok( $items );
	}

	public function mine_detail( \WP_REST_Request $r ): \WP_REST_Response {
		$skill = $this->service->repository()->get_by_uuid( self::uuid( $r ) );
		if ( null === $skill || ! $this->owns( $skill ) ) {
			throw ApiError::not_found();
		}
		return $this->ok( SkillPresenter::author_view( $skill ) );
	}

	public function create( \WP_REST_Request $r ): \WP_REST_Response {
		$skill = $this->service->create( get_current_user_id(), (array) $r->get_json_params() );
		return $this->ok( SkillPresenter::author_view( $skill ), array(), 201 );
	}

	public function update( \WP_REST_Request $r ): \WP_REST_Response {
		$skill = $this->service->update( get_current_user_id(), self::uuid( $r ), (array) $r->get_json_params() );
		return $this->ok( SkillPresenter::author_view( $skill ) );
	}

	public function publish( \WP_REST_Request $r ): \WP_REST_Response {
		$skill = $this->service->publish( get_current_user_id(), self::uuid( $r ) );
		return $this->ok( SkillPresenter::author_view( $skill ) );
	}

	public function archive( \WP_REST_Request $r ): \WP_REST_Response {
		$skill = $this->service->archive( get_current_user_id(), self::uuid( $r ) );
		return $this->ok( SkillPresenter::author_view( $skill ) );
	}

	private function owns( array $skill ): bool {
		$uid = get_current_user_id();
		if ( function_exists( 'current_user_can' ) && current_user_can( 'pst_moderate_content' ) ) {
			return true;
		}
		if ( 'company' === $skill['author_type'] ) {
			return class_exists( '\\Postelio\\Companies\\Api\\CompanyDirectory' ) && \Postelio\Companies\Api\CompanyDirectory::is_member( (int) $skill['company_id'], $uid );
		}
		return (int) $skill['author_id'] === $uid;
	}

	private static function uuid( \WP_REST_Request $r ): string {
		return (string) ( $r->get_url_params()['uuid'] ?? '' );
	}
}
