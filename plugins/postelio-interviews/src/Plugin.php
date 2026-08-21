<?php
/**
 * Amorçage du module postelio-interviews. S'appuie sur core (registry, REST, events,
 * migrations, permissions) et les contrats de users/companies/applications.
 *
 * @package Postelio\Interviews
 */

namespace Postelio\Interviews;

use Postelio\Core\Plugin as Core;
use Postelio\Interviews\Interviews\InterviewController;
use Postelio\Interviews\Interviews\InterviewHistoryRepository;
use Postelio\Interviews\Interviews\InterviewRepository;
use Postelio\Interviews\Interviews\InterviewService;
use Postelio\Interviews\Migrations\CreateInterviewTables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const MODULE        = 'interviews';
	public const SCHEMA_OPTION = 'postelio_interviews_schema';

	private static ?Plugin $instance = null;
	private bool $booted            = false;
	private InterviewService $svc;

	private function __construct() {
		$this->svc = new InterviewService( new InterviewRepository(), new InterviewHistoryRepository() );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @return CreateInterviewTables[] */
	private static function migrations(): array {
		return array( new CreateInterviewTables() );
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
					'version'     => POSTELIO_INTERVIEWS_VERSION,
					'requires'    => array( 'core', 'users', 'companies', 'applications' ),
					'load_order'  => 60,
					'text_domain' => 'postelio-interviews',
				)
			);
			$core->events()->emit( 'plugin.registered', array( 'module' => self::MODULE, 'version' => POSTELIO_INTERVIEWS_VERSION ) );
		}

		$core->migrator()->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );

		add_action( 'init', array( $this, 'maybe_upgrade' ), 2 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', static function () {
			load_plugin_textdomain( 'postelio-interviews', false, dirname( plugin_basename( POSTELIO_INTERVIEWS_FILE ) ) . '/languages' );
		} );
	}

	public function register_routes(): void {
		( new InterviewController( $this->svc ) )->register_routes();
	}

	public function maybe_upgrade(): void {
		$migrator = Core::instance()->migrator();
		if ( (string) get_option( self::SCHEMA_OPTION, '0' ) !== $migrator->target_version( self::MODULE ) ) {
			$migrator->migrate( self::MODULE );
		}
	}

	public function service(): InterviewService {
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
		// Non destructif : conserve entretiens et historique.
	}
}
