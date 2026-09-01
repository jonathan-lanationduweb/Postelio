<?php
/**
 * Plugin Name: Postelio Notifications
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Notifications in-app (cloche/centre) et e-mails transactionnels, pilotés par les événements des autres plugins. File d'envoi, préférences, rappels d'entretien. Dépend de core, users, companies, jobs, applications, messaging, interviews.
 * Version:     0.2.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-notifications
 *
 * @package Postelio\Notifications
 *
 * Lot 09. Réactif : n'appelle jamais wp_mail() directement (Router → EmailDispatcher →
 * file → EmailProvider). Pas de provider commercial en V1 (WpMailProvider dev).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_NOTIFICATIONS_VERSION', '0.2.0' );
define( 'POSTELIO_NOTIFICATIONS_FILE', __FILE__ );
define( 'POSTELIO_NOTIFICATIONS_DIR', plugin_dir_path( __FILE__ ) );

function postelio_notifications_register_autoloader(): bool {
	if ( ! class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
		return false;
	}
	static $done = false;
	if ( ! $done ) {
		\Postelio\Core\Autoloader::register( 'Postelio\\Notifications\\', POSTELIO_NOTIFICATIONS_DIR . 'src/' );
		$done = true;
	}
	return true;
}
postelio_notifications_register_autoloader();

add_action(
	'plugins_loaded',
	static function () {
		foreach ( array( 'Core', 'Users', 'Companies', 'Jobs', 'Applications', 'Messaging', 'Interviews' ) as $dep ) {
			if ( ! class_exists( '\\Postelio\\' . $dep . '\\Plugin' ) ) {
				add_action(
					'admin_notices',
					static function () {
						echo '<div class="notice notice-error"><p><strong>Postelio Notifications</strong> requiert Core, Users, Companies, Jobs, Applications, Messaging et Interviews (activez-les d\'abord).</p></div>';
					}
				);
				return;
			}
		}
		postelio_notifications_register_autoloader();
		\Postelio\Notifications\Plugin::instance()->boot();
	},
	35 // Après interviews (30).
);

register_activation_hook( __FILE__, array( '\\Postelio\\Notifications\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Postelio\\Notifications\\Plugin', 'deactivate' ) );
