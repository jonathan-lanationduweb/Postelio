<?php
/**
 * Plugin Name: Postelio Moderation
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Modération centralisée : signalements, file de cases, décisions humaines, moteur de règles local + passerelle préventive (messages/offres). Décide ; délègue l'exécution aux domaines. Aucun provider externe en V1.
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-moderation
 *
 * @package Postelio\Moderation
 *
 * Lot 11. Aucun provider externe (LocalRuleEngine + revue humaine). N'écrit jamais les
 * tables d'un autre domaine : exécute via leurs contrats publics.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_MODERATION_VERSION', '0.1.0' );
define( 'POSTELIO_MODERATION_FILE', __FILE__ );
define( 'POSTELIO_MODERATION_DIR', plugin_dir_path( __FILE__ ) );

function postelio_moderation_register_autoloader(): bool {
	if ( ! class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
		return false;
	}
	static $done = false;
	if ( ! $done ) {
		\Postelio\Core\Autoloader::register( 'Postelio\\Moderation\\', POSTELIO_MODERATION_DIR . 'src/' );
		$done = true;
	}
	return true;
}
postelio_moderation_register_autoloader();

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			add_action( 'admin_notices', static function () {
				echo '<div class="notice notice-error"><p><strong>Postelio Moderation</strong> requiert Postelio Core (activez-le d\'abord).</p></div>';
			} );
			return;
		}
		postelio_moderation_register_autoloader();
		\Postelio\Moderation\Plugin::instance()->boot();
	},
	45 // Après les domaines (notifications 35, job-sources 40).
);

register_activation_hook( __FILE__, array( '\\Postelio\\Moderation\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Postelio\\Moderation\\Plugin', 'deactivate' ) );
