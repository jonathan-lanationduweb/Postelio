<?php
/**
 * Plugin Name: Postelio Interviews
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Entretiens candidat ↔ recruteur liés à une candidature : proposition, confirmation, refus, autre créneau, modification, annulation, réalisé, historique. Visio / sur place / téléphone. Dépend de core, users, companies, applications.
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-interviews
 *
 * @package Postelio\Interviews
 *
 * Lot 08. NE contient PAS : envoi réel d'e-mails/SMS/push, intégrations calendrier
 * (Google/Teams/Meet/Zoom), facturation. Les événements préparent ces briques.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_INTERVIEWS_VERSION', '0.1.0' );
define( 'POSTELIO_INTERVIEWS_FILE', __FILE__ );
define( 'POSTELIO_INTERVIEWS_DIR', plugin_dir_path( __FILE__ ) );

function postelio_interviews_register_autoloader(): bool {
	if ( ! class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
		return false;
	}
	static $done = false;
	if ( ! $done ) {
		\Postelio\Core\Autoloader::register( 'Postelio\\Interviews\\', POSTELIO_INTERVIEWS_DIR . 'src/' );
		$done = true;
	}
	return true;
}
postelio_interviews_register_autoloader();

add_action(
	'plugins_loaded',
	static function () {
		foreach ( array( 'Core', 'Users', 'Companies', 'Jobs', 'Applications' ) as $dep ) {
			if ( ! class_exists( '\\Postelio\\' . $dep . '\\Plugin' ) ) {
				add_action(
					'admin_notices',
					static function () {
						echo '<div class="notice notice-error"><p><strong>Postelio Interviews</strong> requiert Core, Users, Companies, Jobs et Applications (activez-les d\'abord).</p></div>';
					}
				);
				return;
			}
		}
		postelio_interviews_register_autoloader();
		\Postelio\Interviews\Plugin::instance()->boot();
	},
	30 // Après applications (20) et messaging (25).
);

register_activation_hook( __FILE__, array( '\\Postelio\\Interviews\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Postelio\\Interviews\\Plugin', 'deactivate' ) );
