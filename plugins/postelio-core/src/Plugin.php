<?php
/**
 * Amorçage et câblage du socle transversal Postelio.
 *
 * @package Postelio\Core
 */

namespace Postelio\Core;

use Postelio\Core\Audit\AuditListener;
use Postelio\Core\Audit\AuditLog;
use Postelio\Core\Jobs\Scheduler;
use Postelio\Core\Migrations\CreateAuditLogTable;
use Postelio\Core\Migrations\Migrator;
use Postelio\Core\Permissions\Roles;
use Postelio\Core\Rest\Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const MODULE = 'core';

	/** Option stockant la version de schéma installée pour le core. */
	public const SCHEMA_OPTION = 'postelio_core_schema';

	/** Option stockant la version applicative de la plateforme. */
	public const PLATFORM_VERSION_OPTION = 'postelio_platform_version';

	private static ?Plugin $instance = null;

	private Registry $registry;
	private Events $events;
	private Migrator $migrator;
	private Scheduler $scheduler;
	private bool $booted = false;

	private function __construct() {
		$this->registry  = new Registry();
		$this->events    = new Events();
		$this->migrator  = new Migrator();
		$this->scheduler = new Scheduler( $this->events );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function registry(): Registry {
		return $this->registry;
	}

	public function events(): Events {
		return $this->events;
	}

	public function migrator(): Migrator {
		return $this->migrator;
	}

	public function scheduler(): Scheduler {
		return $this->scheduler;
	}

	/**
	 * Câblage à `plugins_loaded`. Idempotent.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// 1. Le core se déclare lui-même dans le registry (socle, sans dépendance).
		$this->registry->register(
			self::MODULE,
			array(
				'version'      => POSTELIO_CORE_VERSION,
				'requires'     => array(),
				'load_order'   => 0,
				'text_domain'  => 'postelio-core',
			)
		);

		// 2. Déclare la migration du core (table d'audit).
		$this->migrator->register( self::MODULE, self::SCHEMA_OPTION, array( new CreateAuditLogTable() ) );

		// 3. Audit générique : écoute tous les événements auditables.
		( new AuditListener( new AuditLog(), $this->events ) )->subscribe();

		// 4. Traductions.
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'postelio-core', false, dirname( plugin_basename( POSTELIO_CORE_FILE ) ) . '/languages' );
			}
		);

		// 5. Socle REST (namespace, endpoints transversaux).
		add_action(
			'rest_api_init',
			function () {
				( new Server( $this->registry, $this->events ) )->register_routes();
			}
		);

		// 6. Filet de sécurité : applique les migrations en attente si la version a changé
		//    (utile après un `git pull` sans réactivation du plugin).
		add_action(
			'init',
			function () {
				$this->maybe_upgrade();
			},
			1
		);

		// 7. Le socle est prêt : les plugins métier peuvent s'enregistrer.
		$this->events->emit( 'core.ready', array( 'version' => POSTELIO_CORE_VERSION ) );
	}

	/**
	 * Applique les migrations si la version de schéma stockée est en retard.
	 */
	public function maybe_upgrade(): void {
		if ( (string) get_option( self::SCHEMA_OPTION, '' ) !== $this->migrator->target_version( self::MODULE ) ) {
			$this->migrator->migrate( self::MODULE );
		}
	}

	// --- Cycle de vie WordPress --------------------------------------------

	/**
	 * Activation : crée les rôles/capabilities et exécute les migrations.
	 * Non destructif et ré-exécutable sans effet de bord.
	 */
	public static function activate(): void {
		$plugin = self::instance();

		// Enregistre la migration du core même hors `boot()` (contexte d'activation).
		$plugin->migrator->register( self::MODULE, self::SCHEMA_OPTION, array( new CreateAuditLogTable() ) );
		$plugin->migrator->migrate( self::MODULE );

		Roles::install();

		update_option( self::PLATFORM_VERSION_OPTION, POSTELIO_CORE_VERSION );
	}

	/**
	 * Désactivation : NON destructif (voir docs/backend/implementation-plan.md).
	 * Ne supprime ni tables ni rôles ni données. Nettoie seulement le transitoire.
	 */
	public static function deactivate(): void {
		Scheduler::clear_all();
	}
}
