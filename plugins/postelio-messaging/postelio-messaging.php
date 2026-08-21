<?php
/**
 * Plugin Name: Postelio Messaging
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Messagerie candidat ↔ recruteur contextualisée par une candidature (conversations, messages immuables, lu/non-lu par participant, fermeture). Dépend de core, users, companies, applications.
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-messaging
 *
 * @package Postelio\Messaging
 *
 * Lot 07. NE contient PAS : entretiens, notifications réelles (e-mail), paiement,
 * modération complète, pièces jointes (le CV reste géré par postelio-files).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_MESSAGING_VERSION', '0.1.0' );
define( 'POSTELIO_MESSAGING_FILE', __FILE__ );
define( 'POSTELIO_MESSAGING_DIR', plugin_dir_path( __FILE__ ) );

function postelio_messaging_register_autoloader(): bool {
	if ( ! class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
		return false;
	}
	static $done = false;
	if ( ! $done ) {
		\Postelio\Core\Autoloader::register( 'Postelio\\Messaging\\', POSTELIO_MESSAGING_DIR . 'src/' );
		$done = true;
	}
	return true;
}
postelio_messaging_register_autoloader();

add_action(
	'plugins_loaded',
	static function () {
		foreach ( array( 'Core', 'Users', 'Companies', 'Applications' ) as $dep ) {
			if ( ! class_exists( '\\Postelio\\' . $dep . '\\Plugin' ) ) {
				add_action(
					'admin_notices',
					static function () {
						echo '<div class="notice notice-error"><p><strong>Postelio Messaging</strong> requiert Core, Users, Companies et Applications (activez-les d\'abord).</p></div>';
					}
				);
				return;
			}
		}
		postelio_messaging_register_autoloader();
		\Postelio\Messaging\Plugin::instance()->boot();
	},
	25 // Après applications (20).
);

register_activation_hook( __FILE__, array( '\\Postelio\\Messaging\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Postelio\\Messaging\\Plugin', 'deactivate' ) );
