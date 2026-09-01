<?php
/**
 * Plugin Name: Postelio Admin
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Back-office Postelio : centre de contrôle wp-admin (tableau de bord, utilisateurs, entreprises, offres, modération, santé…). Couche d'administration pure — consomme les contrats/services des plugins Postelio, aucune logique métier ni écriture directe. Ne fatal jamais si un module est désactivé.
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-admin
 * Requires Plugins: postelio-core
 *
 * @package Postelio\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_ADMIN_VERSION', '0.2.0' );
define( 'POSTELIO_ADMIN_FILE', __FILE__ );
define( 'POSTELIO_ADMIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'POSTELIO_ADMIN_URL', plugin_dir_url( __FILE__ ) );

function postelio_admin_register_autoloader(): bool {
	if ( ! class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
		return false;
	}
	static $done = false;
	if ( ! $done ) {
		\Postelio\Core\Autoloader::register( 'Postelio\\Admin\\', POSTELIO_ADMIN_DIR . 'src/' );
		$done = true;
	}
	return true;
}
postelio_admin_register_autoloader();

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			add_action( 'admin_notices', static function () {
				echo '<div class="notice notice-error"><p><strong>Postelio Admin</strong> requiert Postelio Core (activez-le d\'abord).</p></div>';
			} );
			return;
		}
		postelio_admin_register_autoloader();
		\Postelio\Admin\Plugin::instance()->boot();
	},
	60 // Après les plugins métier ; le menu se construit sur admin_menu.
);
