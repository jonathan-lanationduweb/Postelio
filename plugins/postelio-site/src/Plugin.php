<?php
/**
 * Amorçage du module postelio-site : SOURCE DE CONFIGURATION du site public Postelio (schéma,
 * stockage en options, API REST publique + admin, capacité `pst_manage_site`, audit via le bus
 * d'événements du core). L'INTERFACE d'édition vit dans postelio-admin ; ici on ne fournit que la
 * configuration et ses contrats. Ne modifie aucun module métier.
 *
 * @package Postelio\Site
 */

namespace Postelio\Site;

use Postelio\Core\Plugin as Core;
use Postelio\Site\Config\SiteSchema;
use Postelio\Site\Http\SiteAdminController;
use Postelio\Site\Http\SiteConfigController;
use Postelio\Site\Permissions\SiteCapability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public const MODULE = 'site';

	private static ?Plugin $instance = null;
	private bool $booted = false;

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
		$core         = Core::instance();

		if ( ! $core->registry()->has( self::MODULE ) ) {
			$core->registry()->register(
				self::MODULE,
				array(
					'version'     => POSTELIO_SITE_VERSION,
					'requires'    => array( 'core' ),
					'load_order'  => 80,
					'text_domain' => 'postelio-site',
				)
			);
			$core->events()->emit( 'plugin.registered', array( 'module' => self::MODULE, 'version' => POSTELIO_SITE_VERSION ) );
		}

		// Version de schéma persistée (pour migrations futures).
		if ( (int) get_option( 'postelio_site_config_version', 0 ) !== SiteSchema::VERSION ) {
			update_option( 'postelio_site_config_version', SiteSchema::VERSION, false );
		}

		( new SiteCapability() )->register();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', static function () {
			load_plugin_textdomain( 'postelio-site', false, dirname( plugin_basename( POSTELIO_SITE_FILE ) ) . '/languages' );
		} );
	}

	public function register_routes(): void {
		( new SiteConfigController() )->register_routes();
		( new SiteAdminController() )->register_routes();
	}

	public static function activate(): void {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			return;
		}
		update_option( 'postelio_site_config_version', SiteSchema::VERSION, false );
	}

	public static function deactivate(): void {
		// Non destructif : on conserve la configuration du site (options) à la désactivation.
	}
}
