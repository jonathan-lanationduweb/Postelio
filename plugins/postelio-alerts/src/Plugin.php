<?php
/**
 * Amorçage du module postelio-alerts (favoris, recherches sauvegardées, alertes).
 *
 * Câble : migrations (3 tables), services (favoris / recherches / matching / deliveries),
 * planification sur le Scheduler du core (ancre 07h30 Europe/Paris auto-replanifiée, DST-correct),
 * intégrations découplées (Notifications via filtres, RGPD via événements/filtre users), REST.
 * Notifications et Job Sources sont OPTIONNELS : le module fonctionne (dégradé) sans eux.
 *
 * @package Postelio\Alerts
 */

namespace Postelio\Alerts;

use Postelio\Alerts\Alerts\AlertDispatcher;
use Postelio\Alerts\Alerts\AlertScheduler;
use Postelio\Alerts\Alerts\DeliveryRepository;
use Postelio\Alerts\Alerts\MatchingService;
use Postelio\Alerts\Favorites\FavoriteRepository;
use Postelio\Alerts\Favorites\FavoriteService;
use Postelio\Alerts\Http\FavoritesController;
use Postelio\Alerts\Http\SavedSearchController;
use Postelio\Alerts\Integration\AccountSync;
use Postelio\Alerts\Integration\NotificationsBridge;
use Postelio\Alerts\Migrations\CreateAlertsTables;
use Postelio\Alerts\Searches\SavedSearchRepository;
use Postelio\Alerts\Searches\SavedSearchService;
use Postelio\Core\Plugin as Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const MODULE        = 'alerts';
	public const SCHEMA_OPTION = 'postelio_alerts_schema';

	private static ?Plugin $instance = null;
	private bool $booted = false;

	private FavoriteRepository $favorites_repo;
	private SavedSearchRepository $searches_repo;
	private DeliveryRepository $deliveries_repo;

	private ?FavoriteService $favorites = null;
	private ?SavedSearchService $searches = null;
	private ?MatchingService $matching = null;

	private function __construct() {
		$this->favorites_repo  = new FavoriteRepository();
		$this->searches_repo   = new SavedSearchRepository();
		$this->deliveries_repo = new DeliveryRepository();
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @return CreateAlertsTables[] */
	private static function migrations(): array {
		return array( new CreateAlertsTables() );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
		$core         = Core::instance();
		$events       = $core->events();

		if ( ! $core->registry()->has( self::MODULE ) ) {
			$core->registry()->register( self::MODULE, array(
				'version'     => POSTELIO_ALERTS_VERSION,
				'requires'    => array( 'core', 'users', 'jobs' ),
				'load_order'  => 65,
				'text_domain' => 'postelio-alerts',
			) );
			$events->emit( 'plugin.registered', array( 'module' => self::MODULE, 'version' => POSTELIO_ALERTS_VERSION ) );
		}

		$core->migrator()->register( self::MODULE, self::SCHEMA_OPTION, self::migrations() );

		// Services.
		$this->matching  = new MatchingService( $this->searches_repo, $this->deliveries_repo, $events );
		$this->favorites = new FavoriteService( $this->favorites_repo, $events );
		$this->searches  = new SavedSearchService( $this->searches_repo, $this->deliveries_repo, $this->matching, $events );

		// Planification (Scheduler du core uniquement).
		$dispatcher = new AlertDispatcher( $this->searches_repo, $this->matching, $this->deliveries_repo, $core->scheduler() );
		( new AlertScheduler( $core->scheduler(), $dispatcher ) )->register();

		// Intégrations découplées.
		( new NotificationsBridge() )->register();
		( new AccountSync( $events, $this->favorites, $this->searches_repo, $this->deliveries_repo ) )->register();

		add_action( 'init', array( $this, 'maybe_upgrade' ), 2 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		( new FavoritesController( $this->favorites ) )->register_routes();
		( new SavedSearchController( $this->searches ) )->register_routes();
	}

	public function favorites(): FavoriteService {
		return $this->favorites;
	}
	public function searches(): SavedSearchService {
		return $this->searches;
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
		// Réarme l'ancre quotidienne immédiatement (déterministe après réactivation).
		AlertScheduler::arm( Core::instance()->scheduler() );
	}

	public static function deactivate(): void {
		// Non destructif : conserve favoris/recherches/deliveries. Retire seulement les tâches.
		if ( class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			AlertScheduler::clear( Core::instance()->scheduler() );
		}
	}
}
