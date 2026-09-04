<?php
/**
 * Plugin Name: Postelio Site
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Configuration du SITE public Postelio (source de vérité éditoriale : accueil, navigation, footer, apparence, SEO…). Stockée en options WordPress, exposée en REST public pour le front, éditée visuellement depuis le back-office (postelio-admin). Aucune logique métier, aucune table.
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-site
 * Requires Plugins: postelio-core
 *
 * @package Postelio\Site
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_SITE_VERSION', '0.1.1' );
define( 'POSTELIO_SITE_FILE', __FILE__ );
define( 'POSTELIO_SITE_DIR', plugin_dir_path( __FILE__ ) );
define( 'POSTELIO_SITE_URL', plugin_dir_url( __FILE__ ) );

function postelio_site_register_autoloader(): bool {
	if ( ! class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
		return false;
	}
	static $done = false;
	if ( ! $done ) {
		\Postelio\Core\Autoloader::register( 'Postelio\\Site\\', POSTELIO_SITE_DIR . 'src/' );
		$done = true;
	}
	return true;
}
postelio_site_register_autoloader();

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			add_action( 'admin_notices', static function () {
				echo '<div class="notice notice-error"><p><strong>Postelio Site</strong> requiert Postelio Core (activez-le d\'abord).</p></div>';
			} );
			return;
		}
		postelio_site_register_autoloader();
		\Postelio\Site\Plugin::instance()->boot();
	},
	40 // Après le core ; indépendant des modules métier.
);

register_activation_hook( __FILE__, array( '\\Postelio\\Site\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Postelio\\Site\\Plugin', 'deactivate' ) );
