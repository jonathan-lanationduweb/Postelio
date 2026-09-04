<?php
/**
 * Plugin Name: Postelio Backoffice
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Couche UNIQUE d'administration wp-admin de Postelio (menu, design system, tableau de bord, Mon site / Site Builder, écrans métier). Orchestration pure : consomme les contrats des plugins métier, aucune table, aucune logique métier. Prend la main progressivement sur les écrans de Postelio Admin (legacy).
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-backoffice
 * Requires Plugins: postelio-core
 *
 * @package Postelio\Backoffice
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_BACKOFFICE_VERSION', '0.1.0' );
define( 'POSTELIO_BACKOFFICE_FILE', __FILE__ );
define( 'POSTELIO_BACKOFFICE_DIR', plugin_dir_path( __FILE__ ) );
define( 'POSTELIO_BACKOFFICE_URL', plugin_dir_url( __FILE__ ) );

function postelio_backoffice_register_autoloader(): bool {
	if ( ! class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
		return false;
	}
	static $done = false;
	if ( ! $done ) {
		\Postelio\Core\Autoloader::register( 'Postelio\\Backoffice\\', POSTELIO_BACKOFFICE_DIR . 'src/' );
		$done = true;
	}
	return true;
}
postelio_backoffice_register_autoloader();

/*
 * Compatibilité legacy — déclarée À L'INCLUSION (avant tout `plugins_loaded`) : Postelio Admin
 * boote à plugins_loaded:60 et lit ces filtres. Le nouveau back-office devient propriétaire du
 * menu « Postelio » ; les assets legacy ne se chargent plus que sur les écrans NON migrés.
 * Voir Postelio\Backoffice\Legacy.
 */
add_filter( 'postelio/admin/legacy_menu', '__return_false' );
add_filter( 'postelio/admin/legacy_assets', static function ( $enabled, $page ) {
	if ( class_exists( '\\Postelio\\Backoffice\\Menu' ) && \Postelio\Backoffice\Menu::is_migrated( (string) $page ) ) {
		return false;
	}
	return $enabled;
}, 10, 2 );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			add_action( 'admin_notices', static function () {
				echo '<div class="notice notice-error"><p><strong>Postelio Backoffice</strong> requiert Postelio Core (activez-le d\'abord).</p></div>';
			} );
			return;
		}
		postelio_backoffice_register_autoloader();
		\Postelio\Backoffice\Plugin::instance()->boot();
	},
	65 // Après Postelio Admin (60) : le legacy a déjà lu les filtres de compatibilité.
);
