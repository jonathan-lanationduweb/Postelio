<?php
/**
 * Endpoints messagerie (namespace `postelio/v1`). API unifiée `/me/conversations`
 * pour candidat ET recruteur (le rôle est déterminé côté serveur). UUID uniquement.
 *
 *  GET  /me/conversations
 *  GET  /me/conversations/{uuid}
 *  GET  /me/conversations/{uuid}/messages     (curseur `before`=uuid, `limit`)
 *  POST /me/conversations/{uuid}/messages      (envoi — e-mail vérifié)
 *  POST /me/conversations/{uuid}/read
 *  POST /me/conversations/{uuid}/close         (recruteur/admin)
 *  POST /companies/me/applications/{application_uuid}/conversation  (recruteur ouvre)
 *
 * @package Postelio\Messaging\Conversations
 */

namespace Postelio\Messaging\Conversations;

use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Core\Support\Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MessageController extends Controller {

	private const UUID = '(?P<uuid>[0-9a-fA-F-]{36})';

	private MessagingService $svc;

	public function __construct( MessagingService $svc ) {
		$this->svc = $svc;
	}

	public function register_routes(): void {
		$ns    = $this->namespace();
		$read  = Guard::require_cap( 'pst_send_message' );          // lecture : rôle messagerie (e-mail non exigé)
		$write = Guard::require_all( 'pst_send_message', 'pst_email_verified' ); // envoi : e-mail vérifié

		register_rest_route( $ns, '/me/conversations', array(
			'methods' => 'GET', 'permission_callback' => $read, 'callback' => $this->guarded( array( $this, 'list' ) ),
		) );
		register_rest_route( $ns, '/me/conversations/' . self::UUID, array(
			'methods' => 'GET', 'permission_callback' => $read, 'callback' => $this->guarded( array( $this, 'detail' ) ),
		) );
		register_rest_route( $ns, '/me/conversations/' . self::UUID . '/messages', array(
			array( 'methods' => 'GET', 'permission_callback' => $read, 'callback' => $this->guarded( array( $this, 'messages' ) ) ),
			array( 'methods' => 'POST', 'permission_callback' => $write, 'callback' => $this->guarded( array( $this, 'send' ) ) ),
		) );
		register_rest_route( $ns, '/me/conversations/' . self::UUID . '/read', array(
			'methods' => 'POST', 'permission_callback' => $read, 'callback' => $this->guarded( array( $this, 'read' ) ),
		) );
		register_rest_route( $ns, '/me/conversations/' . self::UUID . '/close', array(
			'methods' => 'POST', 'permission_callback' => $read, 'callback' => $this->guarded( array( $this, 'close' ) ),
		) );
		register_rest_route( $ns, '/companies/me/applications/(?P<application_uuid>[0-9a-fA-F-]{36})/conversation', array(
			'methods' => 'POST', 'permission_callback' => $write, 'callback' => $this->guarded( array( $this, 'open' ) ),
		) );
	}

	public function list(): \WP_REST_Response {
		$uid  = get_current_user_id();
		$rows = array();
		foreach ( $this->svc->list_for_user( $uid ) as $c ) {
			$role = $this->svc->access_role( $uid, $c );
			if ( null === $role ) {
				continue;
			}
			$rows[] = ConversationPresenter::row( $c, $role, $this->svc->unread_for( $uid, $c ) );
		}
		return $this->ok( $rows );
	}

	public function detail( \WP_REST_Request $r ): \WP_REST_Response {
		$uid = get_current_user_id();
		$acc = $this->svc->accessible_or_fail( $uid, self::uuid( $r ) );
		return $this->ok( ConversationPresenter::detail( $acc['conversation'], $acc['role'], $this->svc->unread_for( $uid, $acc['conversation'] ) ) );
	}

	public function messages( \WP_REST_Request $r ): \WP_REST_Response {
		$uid = get_current_user_id();
		$acc = $this->svc->accessible_or_fail( $uid, self::uuid( $r ) );
		$limit = (int) ( $r->get_param( 'limit' ) ?: 30 );
		$before_id = 0;
		$before = (string) ( $r->get_param( 'before' ) ?? '' );
		if ( '' !== $before ) {
			$m = $this->svc->messages()->get_by_uuid( $before );
			$before_id = $m ? (int) $m['id'] : 0;
		}
		$page = $this->svc->messages()->page( (int) $acc['conversation']['id'], $before_id, $limit );
		$items = ConversationPresenter::messages( $page['items'], $uid );
		$oldest = $items[0]['uuid'] ?? null;
		return $this->raw( array(
			'data' => $items,
			'meta' => array( 'has_more' => $page['has_more'], 'before' => $page['has_more'] ? $oldest : null ),
		) );
	}

	public function send( \WP_REST_Request $r ): \WP_REST_Response {
		$body = (string) ( ( (array) $r->get_json_params() )['body'] ?? '' );
		$m    = $this->svc->send( get_current_user_id(), self::uuid( $r ), $body );
		return $this->ok( ConversationPresenter::message( $m, get_current_user_id() ), array(), 201 );
	}

	public function read( \WP_REST_Request $r ): \WP_REST_Response {
		$unread = $this->svc->mark_read( get_current_user_id(), self::uuid( $r ) );
		return $this->ok( array( 'unread_count' => $unread ) );
	}

	public function close( \WP_REST_Request $r ): \WP_REST_Response {
		$c = $this->svc->close( get_current_user_id(), self::uuid( $r ) );
		return $this->ok( array( 'uuid' => $c['public_uuid'], 'status' => $c['status'] ) );
	}

	public function open( \WP_REST_Request $r ): \WP_REST_Response {
		$app_uuid = (string) ( $r->get_url_params()['application_uuid'] ?? '' );
		$c        = $this->svc->open_for_application( get_current_user_id(), $app_uuid );
		return $this->ok( ConversationPresenter::detail( $c, MessagingService::ROLE_RECRUITER, 0 ), array(), 201 );
	}

	private static function uuid( \WP_REST_Request $r ): string {
		return (string) ( $r->get_url_params()['uuid'] ?? '' );
	}
}
