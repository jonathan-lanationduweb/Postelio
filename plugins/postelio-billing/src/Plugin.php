<?php
/**
 * Amorçage du module postelio-billing. Renouvellement d'offre payant (Stripe Checkout hosted),
 * ordres/paiements/événements provider, fulfillment exactly-once via le contrat Jobs, retry
 * via le Scheduler Core. Décide/paie ; DÉLÈGUE l'effet métier à JobLifecycle. Aucun code
 * Stripe hors du provider ; aucun envoi d'e-mail (événements → Notifications).
 *
 * @package Postelio\Billing
 */

namespace Postelio\Billing;

use Postelio\Billing\Events\ProviderEventRepository;
use Postelio\Billing\Fulfillment\FulfillmentService;
use Postelio\Billing\Http\AdminController;
use Postelio\Billing\Http\BillingController;
use Postelio\Billing\Http\WebhookController;
use Postelio\Billing\Migrations\CreateBillingTables;
use Postelio\Billing\Orders\OrderRepository;
use Postelio\Billing\Orders\OrderService;
use Postelio\Billing\Payments\PaymentRepository;
use Postelio\Billing\Webhook\WebhookProcessor;
use Postelio\Core\Plugin as Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const MODULE        = 'billing';
	public const SCHEMA_OPTION = 'postelio_billing_schema';
	public const CRON_RETRY    = 'billing_fulfillment_retry';

	private static ?Plugin $instance = null;
	private bool $booted = false;

	private OrderRepository $orders;
	private PaymentRepository $payments;
	private ProviderEventRepository $events;
	private OrderService $order_service;
	private FulfillmentService $fulfillment;
	private WebhookProcessor $processor;

	private function __construct() {
		$this->orders        = new OrderRepository();
		$this->payments      = new PaymentRepository();
		$this->events        = new ProviderEventRepository();
		$this->fulfillment   = new FulfillmentService( $this->orders );
		$this->order_service = new OrderService( $this->orders );
		$this->processor     = new WebhookProcessor( $this->orders, $this->payments, $this->events, $this->fulfillment );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @return CreateBillingTables[] */
	private static function migrations(): array {
		return array( new CreateBillingTables() );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
		$core         = Core::instance();

		if ( ! $core->registry()->has( self::MODULE ) ) {
			$core->registry()->register( self::MODULE, array(
				'version'     => POSTELIO_BILLING_VERSION,
				'requires'    => array( 'core', 'jobs' ),
				'load_order'  => 95,
				'text_domain' => 'postelio-billing',
			) );
			$core->events()->emit( 'plugin.registered', array( 'module' => self::MODULE, 'version' => POSTELIO_BILLING_VERSION ) );
		}

		$core->migrator()->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );

		add_action( 'init', array( $this, 'maybe_upgrade' ), 2 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Worker de reprise du fulfillment (paiement encaissé, renouvellement à (re)tenter).
		$scheduler = $core->scheduler();
		$scheduler->on( self::CRON_RETRY, array( $this, 'run_retry' ) );
		add_action( 'init', function () use ( $scheduler ) {
			$scheduler->recurring( self::CRON_RETRY, 'postelio_15min' );
		}, 20 );
	}

	public function register_routes(): void {
		( new BillingController( $this->order_service, $this->orders, $this->payments ) )->register_routes();
		( new WebhookController( $this->processor ) )->register_routes();
		( new AdminController( $this->orders, $this->payments, $this->events, $this->fulfillment ) )->register_routes();
	}

	public function run_retry(): void {
		$this->fulfillment->run_due();
	}

	public function orders(): OrderRepository {
		return $this->orders;
	}
	public function order_service(): OrderService {
		return $this->order_service;
	}
	public function fulfillment(): FulfillmentService {
		return $this->fulfillment;
	}
	public function processor(): WebhookProcessor {
		return $this->processor;
	}

	public function maybe_upgrade(): void {
		$migrator = Core::instance()->migrator();
		if ( (string) get_option( self::SCHEMA_OPTION, '0' ) !== $migrator->target_version( self::MODULE ) ) {
			$migrator->migrate( self::MODULE );
		}
	}

	public static function activate(): void {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			return;
		}
		$m = Core::instance()->migrator();
		$m->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );
		$m->migrate( self::MODULE );
	}

	public static function deactivate(): void {
		if ( class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			Core::instance()->scheduler()->cancel( self::CRON_RETRY );
		}
		// Non destructif : conserve orders/payments/events (obligations comptables).
	}
}
