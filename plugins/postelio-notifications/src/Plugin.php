<?php
/**
 * Amorçage du module postelio-notifications. Réactif : écoute les événements des autres
 * plugins et décide des canaux (in-app / e-mail). S'appuie sur core (events, migrations,
 * scheduler, REST, permissions) et les contrats publics de users/companies/jobs/
 * applications/messaging/interviews.
 *
 * @package Postelio\Notifications
 */

namespace Postelio\Notifications;

use Postelio\Core\Plugin as Core;
use Postelio\Notifications\Migrations\CreateNotificationTables;
use Postelio\Notifications\Notifications\DeliveryRepository;
use Postelio\Notifications\Notifications\EmailDispatcher;
use Postelio\Notifications\Notifications\NotificationController;
use Postelio\Notifications\Notifications\NotificationRepository;
use Postelio\Notifications\Notifications\NotificationRouter;
use Postelio\Notifications\Notifications\NotificationService;
use Postelio\Notifications\Notifications\PreferenceController;
use Postelio\Notifications\Notifications\PreferenceService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const MODULE        = 'notifications';
	public const SCHEMA_OPTION = 'postelio_notifications_schema';

	private static ?Plugin $instance = null;
	private bool $booted = false;

	private NotificationService $notifications;
	private EmailDispatcher $emails;
	private PreferenceService $prefs;
	private NotificationRouter $router;

	private function __construct() {
		$this->notifications = new NotificationService( new NotificationRepository() );
		$this->emails        = new EmailDispatcher( new DeliveryRepository() );
		$this->prefs         = new PreferenceService();
		$this->router        = new NotificationRouter( $this->notifications, $this->emails, $this->prefs );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @return CreateNotificationTables[] */
	private static function migrations(): array {
		return array( new CreateNotificationTables() );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
		$core         = Core::instance();

		if ( ! $core->registry()->has( self::MODULE ) ) {
			$core->registry()->register(
				self::MODULE,
				array(
					'version'     => POSTELIO_NOTIFICATIONS_VERSION,
					'requires'    => array( 'core', 'users', 'companies', 'jobs', 'applications', 'messaging', 'interviews' ),
					'load_order'  => 70,
					'text_domain' => 'postelio-notifications',
				)
			);
			$core->events()->emit( 'plugin.registered', array( 'module' => self::MODULE, 'version' => POSTELIO_NOTIFICATIONS_VERSION ) );
		}

		$core->migrator()->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );

		// Écoute des événements métier + worker/rappels (Scheduler unique du core).
		$this->router->register( $core->events(), $core->scheduler() );

		add_action( 'init', array( $this, 'maybe_upgrade' ), 2 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', static function () {
			load_plugin_textdomain( 'postelio-notifications', false, dirname( plugin_basename( POSTELIO_NOTIFICATIONS_FILE ) ) . '/languages' );
		} );
	}

	public function register_routes(): void {
		( new NotificationController( $this->notifications ) )->register_routes();
		( new PreferenceController( $this->prefs ) )->register_routes();
	}

	public function maybe_upgrade(): void {
		$migrator = Core::instance()->migrator();
		if ( (string) get_option( self::SCHEMA_OPTION, '0' ) !== $migrator->target_version( self::MODULE ) ) {
			$migrator->migrate( self::MODULE );
		}
	}

	public function router(): NotificationRouter {
		return $this->router;
	}
	public function emails(): EmailDispatcher {
		return $this->emails;
	}
	public function notifications(): NotificationService {
		return $this->notifications;
	}
	public function preferences(): PreferenceService {
		return $this->prefs;
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
		// Non destructif : conserve notifications et livraisons. On retire seulement le
		// worker récurrent (les rappels ponctuels ne feront rien sans handler).
		if ( class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			Core::instance()->scheduler()->cancel( 'notifications_worker' );
		}
	}
}
