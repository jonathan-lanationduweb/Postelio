<?php
/**
 * Amorçage du module postelio-jobs. S'appuie sur postelio-core (registry, REST,
 * events, permissions, cron) et sur les contrats publics de postelio-companies
 * (CompanyDirectory, CompanyVerification). Aucune infrastructure dupliquée.
 *
 * Pas de table dédiée : les offres sont un CPT (`postelio_job`) + meta. Favoris et
 * alertes candidat sont hors périmètre de ce lot.
 *
 * @package Postelio\Jobs
 */

namespace Postelio\Jobs;

use Postelio\Core\Plugin as Core;
use Postelio\Jobs\Cpt\JobPostType;
use Postelio\Jobs\Jobs\JobController;
use Postelio\Jobs\Jobs\JobRepository;
use Postelio\Jobs\Jobs\JobService;
use Postelio\Jobs\Lifecycle\Expiration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const MODULE = 'jobs';

	private static ?Plugin $instance = null;
	private bool $booted            = false;

	private JobRepository $jobs;
	private JobService $service;

	private function __construct() {
		$this->jobs    = new JobRepository();
		$this->service = new JobService( $this->jobs );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$core = Core::instance();

		if ( ! $core->registry()->has( self::MODULE ) ) {
			$core->registry()->register(
				self::MODULE,
				array(
					'version'     => POSTELIO_JOBS_VERSION,
					'requires'    => array( 'core', 'users', 'companies' ),
					'load_order'  => 30,
					'text_domain' => 'postelio-jobs',
				)
			);
			$core->events()->emit( 'plugin.registered', array( 'module' => self::MODULE, 'version' => POSTELIO_JOBS_VERSION ) );
		}

		( new JobPostType() )->register();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Cron d'expiration quotidien (abstraction du core).
		$scheduler = $core->scheduler();
		$scheduler->on( Expiration::CRON, array( $this, 'run_expiration' ) );
		add_action( 'init', function () use ( $scheduler ) {
			$scheduler->recurring( Expiration::CRON, 'daily' );
		}, 20 );

		add_action( 'init', static function () {
			load_plugin_textdomain( 'postelio-jobs', false, dirname( plugin_basename( POSTELIO_JOBS_FILE ) ) . '/languages' );
		} );
	}

	public function register_routes(): void {
		( new JobController( $this->jobs, $this->service ) )->register_routes();
	}

	public function run_expiration(): void {
		( new Expiration( $this->jobs ) )->run();
	}

	public function service(): JobService {
		return $this->service;
	}

	public function repository(): JobRepository {
		return $this->jobs;
	}

	// --- Cycle de vie ------------------------------------------------------

	public static function activate(): void {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			return;
		}
		( new JobPostType() )->register_type();
		// Pas de migration (CPT + meta). Le cron est (re)planifié au boot.
	}

	public static function deactivate(): void {
		Core::instance()->scheduler()->cancel( Expiration::CRON );
	}
}
