<?php
/**
 * Amorçage du module postelio-applications. S'appuie sur core (registry, REST,
 * events, migrations, permissions) et les contrats publics de users/companies/jobs.
 *
 * @package Postelio\Applications
 */

namespace Postelio\Applications;

use Postelio\Applications\Applications\ApplicationController;
use Postelio\Applications\Applications\ApplicationRepository;
use Postelio\Applications\Applications\ApplicationService;
use Postelio\Applications\Applications\HistoryRepository;
use Postelio\Applications\Applications\NoteRepository;
use Postelio\Applications\Migrations\CreateApplicationsTables;
use Postelio\Core\Plugin as Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const MODULE        = 'applications';
	public const SCHEMA_OPTION = 'postelio_applications_schema';

	private static ?Plugin $instance = null;
	private bool $booted            = false;

	private ApplicationRepository $apps;
	private ApplicationService $service;

	private function __construct() {
		$this->apps    = new ApplicationRepository();
		$this->service = new ApplicationService( $this->apps, new HistoryRepository(), new NoteRepository() );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @return CreateApplicationsTables[] */
	private static function migrations(): array {
		return array( new CreateApplicationsTables() );
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
					'version'     => POSTELIO_APPLICATIONS_VERSION,
					'requires'    => array( 'core', 'users', 'companies', 'jobs' ),
					'load_order'  => 40,
					'text_domain' => 'postelio-applications',
				)
			);
			$core->events()->emit( 'plugin.registered', array( 'module' => self::MODULE, 'version' => POSTELIO_APPLICATIONS_VERSION ) );
		}

		$core->migrator()->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );

		// Pont files ↔ applications (par filtres, sans dépendance de classe).
		( new \Postelio\Applications\Integration\FilesAccess() )->register();

		add_action( 'init', array( $this, 'maybe_upgrade' ), 2 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', static function () {
			load_plugin_textdomain( 'postelio-applications', false, dirname( plugin_basename( POSTELIO_APPLICATIONS_FILE ) ) . '/languages' );
		} );
	}

	public function register_routes(): void {
		( new ApplicationController( $this->service, $this->apps ) )->register_routes();
	}

	public function maybe_upgrade(): void {
		$migrator = Core::instance()->migrator();
		if ( (string) get_option( self::SCHEMA_OPTION, '0' ) !== $migrator->target_version( self::MODULE ) ) {
			$migrator->migrate( self::MODULE );
		}
	}

	public function service(): ApplicationService {
		return $this->service;
	}

	// --- Cycle de vie ------------------------------------------------------

	public static function activate(): void {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			return;
		}
		$m = Core::instance()->migrator();
		$m->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );
		$m->migrate( self::MODULE );
	}

	public static function deactivate(): void {
		// Non destructif : conserve candidatures, historique et notes.
	}
}
