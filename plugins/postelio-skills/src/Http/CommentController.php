<?php
/**
 * Commentaires (« Avis » V1) :
 *   GET  /skills/{uuid}/comments   (public : commentaires published)
 *   POST /skills/{uuid}/comments   (pst_comment_skill + e-mail vérifié ; modéré à l'insert)
 *
 * @package Postelio\Skills\Http
 */

namespace Postelio\Skills\Http;

use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Core\Support\Response;
use Postelio\Skills\Comments\CommentPresenter;
use Postelio\Skills\Comments\CommentService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CommentController extends Controller {

	private const UUID = '(?P<uuid>[0-9a-fA-F-]{36})';

	private CommentService $comments;

	public function __construct( CommentService $comments ) {
		$this->comments = $comments;
	}

	public function register_routes(): void {
		$ns = $this->namespace();
		register_rest_route( $ns, '/skills/' . self::UUID . '/comments', array(
			array( 'methods' => 'GET', 'permission_callback' => Guard::public_access(), 'callback' => $this->guarded( array( $this, 'list' ) ) ),
			array( 'methods' => 'POST', 'permission_callback' => Guard::require_all( 'pst_comment_skill', 'pst_email_verified' ), 'callback' => $this->guarded( array( $this, 'create' ) ) ),
		) );
	}

	public function list( \WP_REST_Request $r ): \WP_REST_Response {
		$page     = max( 1, (int) ( $r->get_param( 'page' ) ?: 1 ) );
		$per_page = Response::clamp_per_page( (int) ( $r->get_param( 'per_page' ) ?: 20 ) );
		$res      = $this->comments->list_for_skill( self::uuid( $r ), $page, $per_page );
		$items    = array_map( static fn( $c ) => CommentPresenter::view( $c ), $res['items'] );
		return $this->raw( Response::paginated( $items, $page, $per_page, (int) $res['total'] ) );
	}

	public function create( \WP_REST_Request $r ): \WP_REST_Response {
		$body = (string) ( ( (array) $r->get_json_params() )['body'] ?? '' );
		$c    = $this->comments->create( get_current_user_id(), self::uuid( $r ), $body );
		return $this->ok( CommentPresenter::view( $c ), array(), 201 );
	}

	private static function uuid( \WP_REST_Request $r ): string {
		return (string) ( $r->get_url_params()['uuid'] ?? '' );
	}
}
