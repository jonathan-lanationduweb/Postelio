<?php
/**
 * Amorçage du module postelio-messaging. S'appuie sur core (registry, REST, events,
 * migrations, permissions) et les contrats de users/companies/applications.
 *
 * @package Postelio\Messaging
 */

namespace Postelio\Messaging;

use Postelio\Core\Plugin as Core;
use Postelio\Messaging\Conversations\ConversationRepository;
use Postelio\Messaging\Conversations\MessageController;
use Postelio\Messaging\Conversations\MessageRepository;
use Postelio\Messaging\Conversations\MessagingService;
use Postelio\Messaging\Conversations\ParticipantRepository;
use Postelio\Messaging\Migrations\CreateMessagingTables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const MODULE        = 'messaging';
	public const SCHEMA_OPTION = 'postelio_messaging_schema';

	private static ?Plugin $instance = null;
	private bool $booted            = false;
	private MessagingService $svc;

	private function __construct() {
		$this->svc = new MessagingService( new ConversationRepository(), new ParticipantRepository(), new MessageRepository() );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @return CreateMessagingTables[] */
	private static function migrations(): array {
		return array( new CreateMessagingTables() );
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
					'version'     => POSTELIO_MESSAGING_VERSION,
					'requires'    => array( 'core', 'users', 'companies', 'applications' ),
					'load_order'  => 50,
					'text_domain' => 'postelio-messaging',
				)
			);
			$core->events()->emit( 'plugin.registered', array( 'module' => self::MODULE, 'version' => POSTELIO_MESSAGING_VERSION ) );
		}

		$core->migrator()->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );

		add_action( 'init', array( $this, 'maybe_upgrade' ), 2 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', static function () {
			load_plugin_textdomain( 'postelio-messaging', false, dirname( plugin_basename( POSTELIO_MESSAGING_FILE ) ) . '/languages' );
		} );
	}

	public function register_routes(): void {
		( new MessageController( $this->svc ) )->register_routes();
	}

	public function maybe_upgrade(): void {
		$migrator = Core::instance()->migrator();
		if ( (string) get_option( self::SCHEMA_OPTION, '0' ) !== $migrator->target_version( self::MODULE ) ) {
			$migrator->migrate( self::MODULE );
		}
	}

	public function service(): MessagingService {
		return $this->svc;
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
		// Non destructif : conserve conversations, participants et messages.
	}
}
