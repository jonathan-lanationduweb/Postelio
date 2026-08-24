<?php
/**
 * Endpoints entreprise :
 *   POST /billing/checkout          (pst_pay_renewal + pst_email_verified) → { order_uuid, checkout_url }
 *   GET  /billing/orders            (historique dashboard, paginé, scope entreprise)
 *   GET  /billing/orders/{uuid}     (statut d'un ordre, scope entreprise)
 *
 * Le front ne fournit JAMAIS montant/devise/durée : seuls product_code/resource_*. Ownership
 * vérifiée côté service (non-divulgation 404 hors entreprise). is_active exigé.
 *
 * @package Postelio\Billing\Http
 */

namespace Postelio\Billing\Http;

use Postelio\Billing\Orders\OrderRepository;
use Postelio\Billing\Orders\OrderService;
use Postelio\Billing\Payments\PaymentRepository;
use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Core\Support\Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BillingController extends Controller {

	private const UUID = '(?P<uuid>[0-9a-fA-F-]{36})';

	private OrderService $orders_service;
	private OrderRepository $orders;
	private PaymentRepository $payments;

	public function __construct( OrderService $orders_service, OrderRepository $orders, PaymentRepository $payments ) {
		$this->orders_service = $orders_service;
		$this->orders         = $orders;
		$this->payments       = $payments;
	}

	public function register_routes(): void {
		$ns  = $this->namespace();
		$pay = Guard::require_all( 'pst_pay_renewal', 'pst_email_verified' );
		register_rest_route( $ns, '/billing/checkout', array( 'methods' => 'POST', 'permission_callback' => $pay, 'callback' => $this->guarded( array( $this, 'checkout' ) ) ) );
		register_rest_route( $ns, '/billing/orders', array( 'methods' => 'GET', 'permission_callback' => $pay, 'callback' => $this->guarded( array( $this, 'history' ) ) ) );
		register_rest_route( $ns, '/billing/orders/' . self::UUID, array( 'methods' => 'GET', 'permission_callback' => $pay, 'callback' => $this->guarded( array( $this, 'show' ) ) ) );
	}

	public function checkout( \WP_REST_Request $r ): \WP_REST_Response {
		$uid = get_current_user_id();
		$this->assert_active( $uid );
		$b   = (array) $r->get_json_params();
		$res = $this->orders_service->checkout(
			$uid,
			(string) ( $b['product_code'] ?? '' ),
			(string) ( $b['resource_type'] ?? '' ),
			(string) ( $b['resource_uuid'] ?? '' )
		);
		return $this->ok( $res, array(), 201 );
	}

	public function history( \WP_REST_Request $r ): \WP_REST_Response {
		$uid        = get_current_user_id();
		$company_id = $this->company_of( $uid );
		if ( 0 === $company_id ) {
			return $this->raw( Response::paginated( array(), 1, 20, 0 ) );
		}
		$page     = max( 1, (int) ( $r->get_param( 'page' ) ?: 1 ) );
		$per_page = Response::clamp_per_page( (int) ( $r->get_param( 'per_page' ) ?: 20 ) );
		$res      = $this->orders->list( array( 'company_id' => $company_id ), $page, $per_page );
		$items    = array_map( function ( $o ) {
			return BillingPresenter::order_view( $o, $this->payments->list_for_order( (int) $o['id'] ) );
		}, $res['items'] );
		return $this->raw( Response::paginated( $items, $page, $per_page, (int) $res['total'] ) );
	}

	public function show( \WP_REST_Request $r ): \WP_REST_Response {
		$uid   = get_current_user_id();
		$order = $this->orders->get_by_uuid( (string) ( $r->get_url_params()['uuid'] ?? '' ) );
		if ( null === $order || ! $this->is_member( (int) $order['company_id'], $uid ) ) {
			throw ApiError::not_found(); // non-divulgation
		}
		return $this->ok( BillingPresenter::order_view( $order, $this->payments->list_for_order( (int) $order['id'] ) ) );
	}

	// --- Helpers --------------------------------------------------------------

	private function assert_active( int $uid ): void {
		if ( class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) && ! \Postelio\Users\Api\UserDirectory::is_active( $uid ) ) {
			throw ApiError::forbidden( 'Action indisponible pour ce compte.' );
		}
	}

	private function company_of( int $uid ): int {
		return class_exists( '\\Postelio\\Companies\\Api\\CompanyDirectory' ) ? \Postelio\Companies\Api\CompanyDirectory::company_of_user( $uid ) : 0;
	}

	private function is_member( int $company_id, int $uid ): bool {
		return class_exists( '\\Postelio\\Companies\\Api\\CompanyDirectory' ) && \Postelio\Companies\Api\CompanyDirectory::is_member( $company_id, $uid );
	}
}
