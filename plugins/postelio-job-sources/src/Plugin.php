<?php
/**
 * Amorçage du module postelio-job-sources. Agrège des offres externes (France Travail V1)
 * dans une table dédiée et les fusionne à la recherche `GET /jobs` via les filtres publics
 * de postelio-jobs. N'appelle jamais wp_mail ; ne crée jamais de candidature Postelio.
 *
 * @package Postelio\JobSources
 */

namespace Postelio\JobSources;

use Postelio\Core\Plugin as Core;
use Postelio\JobSources\Http\AdminController;
use Postelio\JobSources\Http\ApplyRedirectController;
use Postelio\JobSources\Jobs\ExternalJobRepository;
use Postelio\JobSources\Jobs\JobsBridge;
use Postelio\JobSources\Jobs\SyncRunRepository;
use Postelio\JobSources\Migrations\CreateExternalJobsTables;
use Postelio\JobSources\Sources\JobSourceRegistry;
use Postelio\JobSources\Sync\SyncOrchestrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const MODULE        = 'job_sources';
	public const SCHEMA_OPTION = 'postelio_job_sources_schema';

	private static ?Plugin $instance = null;
	private bool $booted = false;

	private JobSourceRegistry $registry;
	private ExternalJobRepository $jobs;
	private SyncRunRepository $runs;
	private SyncOrchestrator $orchestrator;

	private function __construct() {
		$this->registry     = new JobSourceRegistry();
		$this->jobs         = new ExternalJobRepository();
		$this->runs         = new SyncRunRepository();
		$this->orchestrator = new SyncOrchestrator( $this->registry, $this->jobs, $this->runs );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @return CreateExternalJobsTables[] */
	private static function migrations(): array {
		return array( new CreateExternalJobsTables() );
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
					'version'     => POSTELIO_JOBSOURCES_VERSION,
					'requires'    => array( 'core', 'jobs' ),
					'load_order'  => 80,
					'text_domain' => 'postelio-job-sources',
				)
			);
			$core->events()->emit( 'plugin.registered', array( 'module' => self::MODULE, 'version' => POSTELIO_JOBSOURCES_VERSION ) );
		}

		$core->migrator()->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );

		// Fusion recherche + présentation + résolution externe via les filtres de jobs.
		( new JobsBridge( $this->jobs, $this->registry ) )->attach();

		// Sync périodique (Scheduler unique du core). Sans slices configurés / secrets → no-op.
		$core->scheduler()->on( 'job_sources_sync', array( $this, 'run_sync' ) );
		$core->scheduler()->recurring( 'job_sources_sync', (string) apply_filters( 'postelio/job_sources/sync_recurrence', 'hourly' ) );

		add_action( 'init', array( $this, 'maybe_upgrade' ), 2 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		( new ApplyRedirectController( $this->jobs, $this->registry ) )->register_routes();
		( new AdminController( $this->registry, $this->jobs, $this->runs ) )->register_routes();
	}

	public function run_sync(): void {
		foreach ( $this->registry->providers() as $key => $provider ) {
			if ( $provider->is_available() ) {
				$this->orchestrator->run_provider( $key );
			}
		}
	}

	public function orchestrator(): SyncOrchestrator {
		return $this->orchestrator;
	}
	public function external_jobs(): ExternalJobRepository {
		return $this->jobs;
	}
	public function registry(): JobSourceRegistry {
		return $this->registry;
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
		// Non destructif : conserve les offres externes. Retire seulement la sync récurrente.
		if ( class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			Core::instance()->scheduler()->cancel( 'job_sources_sync' );
		}
	}
}
