<?php
/**
 * Endpoints admin (cap `pst_manage_billing`) :
 *   GET  /billing/admin/orders
 *   GET  /billing/admin/orders/{uuid}
 *   POST /billing/admin/orders/{uuid}/retry-fulfillment
 *   GET  /billing/health
 *
 * Aucun secret exposé (ni clés, ni customer secret). Le mode test/live et l'état de
 * configuration sont diagnostiqués.
 *
 * @package Postelio\Billing\Http
 */

namespace Postelio\Billing\Http;

use Postelio\Billing\Config\SellerConfig;
use Postelio\Billing\Domain\OrderStatus;
use Postelio\Billing\Domain\PaymentStatus;
use Postelio\Billing\Events\ProviderEventRepository;
use Postelio\Billing\Fulfillment\FulfillmentService;
use Postelio\Billing\Orders\OrderRepository;
use Postelio\Billing\Payments\PaymentRepository;
use Postelio\Billing\Provider\ProviderRegistry;
use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Core\Support\Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminController extends Controller {

	private const UUID = '(?P<uuid>[0-9a-fA-F-]{36})';

	private OrderRepository $orders;
	private PaymentRepository $payments;
	private ProviderEventRepository $events;
	private FulfillmentService $fulfillment;

	public function __construct( OrderRepository $orders, PaymentRepository $payments, ProviderEventRepository $events, FulfillmentService $fulfillment ) {
		$this->orders      = $orders;
		$this->payments    = $payments;
		$this->events      = $events;
		$this->fulfillment = $fulfillment;
	}

	public function register_routes(): void {
		$ns    = $this->namespace();
		$admin = Guard::require_cap( 'pst_manage_billing' );
		register_rest_route( $ns, '/billing/admin/orders', array( 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => $this->guarded( array( $this, 'list' ) ) ) );
		register_rest_route( $ns, '/billing/admin/orders/' . self::UUID, array( 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => $this->guarded( array( $this, 'detail' ) ) ) );
		register_rest_route( $ns, '/billing/admin/orders/' . self::UUID . '/retry-fulfillment', array( 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => $this->guarded( array( $this, 'retry' ) ) ) );
		register_rest_route( $ns, '/billing/health', array( 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => $this->guarded( array( $this, 'health' ) ) ) );
	}

	public function list( \WP_REST_Request $r ): \WP_REST_Response {
		$page     = max( 1, (int) ( $r->get_param( 'page' ) ?: 1 ) );
		$per_page = Response::clamp_per_page( (int) ( $r->get_param( 'per_page' ) ?: 20 ) );
		$filters  = array();
		if ( $r->get_param( 'status' ) ) {
			$filters['status'] = (string) $r->get_param( 'status' );
		}
		$res   = $this->orders->list( $filters, $page, $per_page );
		$items = array_map( array( $this, 'admin_view' ), $res['items'] );
		return $this->raw( Response::paginated( $items, $page, $per_page, (int) $res['total'] ) );
	}

	public function detail( \WP_REST_Request $r ): \WP_REST_Response {
		$order = $this->orders->get_by_uuid( (string) ( $r->get_url_params()['uuid'] ?? '' ) );
		if ( null === $order ) {
			throw ApiError::not_found();
		}
		return $this->ok( $this->admin_view( $order ) );
	}

	public function retry( \WP_REST_Request $r ): \WP_REST_Response {
		$order = $this->orders->get_by_uuid( (string) ( $r->get_url_params()['uuid'] ?? '' ) );
		if ( null === $order ) {
			throw ApiError::not_found();
		}
		$updated = $this->fulfillment->fulfill( (int) $order['id'] );
		return $this->ok( $this->admin_view( $updated ?: $order ) );
	}

	public function health(): \WP_REST_Response {
		global $wpdb;
		$ph      = ProviderRegistry::resolve()->health();
		$orders  = OrderRepository::table();
		$payments = PaymentRepository::table();
		$last_evt = $this->events->last_received();
		$last_pay = $wpdb->get_var( $wpdb->prepare( "SELECT paid_at FROM {$payments} WHERE status = %s AND paid_at IS NOT NULL ORDER BY id DESC LIMIT 1", PaymentStatus::SUCCEEDED ) );
		$failed   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$orders} WHERE status = %s", OrderStatus::FULFILLMENT_FAILED ) );

		$configured = (bool) ( $ph['configured'] ?? false );
		$mode       = (string) ( $ph['mode'] ?? 'unknown' );
		$webhook_ok = (bool) ( $ph['webhook_configured'] ?? false );
		$status     = 'ok';
		if ( ! $configured || ! $webhook_ok || 'unknown' === $mode ) {
			$status = ( 'fake' === ( $ph['provider'] ?? '' ) ) ? 'ok' : 'degraded';
		}

		return $this->ok( array(
			'status'                  => $status,
			'provider'               => (string) ( $ph['provider'] ?? 'stripe' ),
			'mode'                   => $mode,
			'configured'             => $configured,
			'webhook_configured'     => $webhook_ok,
			'last_webhook_at'        => $last_evt ? str_replace( ' ', 'T', (string) $last_evt['received_at'] ) . 'Z' : null,
			'last_successful_payment_at' => $last_pay ? str_replace( ' ', 'T', (string) $last_pay ) . 'Z' : null,
			'failed_fulfillment_count' => $failed,
			'seller_configured'      => SellerConfig::is_complete(),
			'invoice_legal_ready'    => SellerConfig::legal_invoice_ready(),
		) );
	}

	/** @param array<string,mixed> $order @return array<string,mixed> */
	private function admin_view( array $order ): array {
		$view = BillingPresenter::order_view( $order, $this->payments->list_for_order( (int) $order['id'] ) );
		$view['fulfillment_attempts']   = (int) $order['fulfillment_attempts'];
		$view['last_fulfillment_error'] = $order['last_fulfillment_error'] ?? null;
		$view['provider']               = (string) $order['provider'];
		$view['provider_session_id']    = $order['provider_session_id'] ?? null; // référence non secrète (cross-ref dashboard)
		$view['payments']               = array_map( static function ( $p ) {
			return array(
				'status'             => (string) $p['status'],
				'amount'             => (int) $p['amount'],
				'currency'           => (string) $p['currency'],
				'payment_intent_id'  => $p['provider_payment_intent_id'] ?? null,
				'created_at'         => str_replace( ' ', 'T', (string) $p['created_at'] ) . 'Z',
			);
		}, $this->payments->list_for_order( (int) $order['id'] ) );
		return $view;
	}
}
