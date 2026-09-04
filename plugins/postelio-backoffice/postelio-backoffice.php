<?php
/**
 * Plugin Name: Postelio Backoffice
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Couche UNIQUE d'administration wp-admin de Postelio : menu, design system, tableau de bord, Mon site (Site Builder), écrans métier et écrans système. Orchestration pure — consomme les contrats des plugins métier, aucune table, aucune logique métier.
 * Version:     0.3.0
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

define( 'POSTELIO_BACKOFFICE_VERSION', '0.3.0' );
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
 * Neutralisation COMPLÈTE de l'ancienne interface (`postelio-admin`), déclarée À L'INCLUSION — donc
 * avant son amorçage (`plugins_loaded:60`), qui lit ces filtres. Tous les écrans étant désormais
 * rendus ici, le plugin historique ne fournit plus ni menu, ni assets, ni actions : sans cela, ses
 * gestionnaires `admin_post_*` répondraient avant les nôtres. Il peut être désactivé sans effet.
 */
add_filter( 'postelio/admin/legacy_menu', '__return_false' );
add_filter( 'postelio/admin/legacy_actions', '__return_false' );
add_filter( 'postelio/admin/legacy_assets', '__return_false' );

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
	65 // Après les plugins métier : leurs contrats sont chargés quand le menu se construit.
);
