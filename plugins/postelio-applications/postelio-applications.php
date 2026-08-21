<?php
/**
 * Plugin Name: Postelio Applications
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Candidatures candidat ↔ offre ↔ entreprise : création, snapshot d'offre, réponses de présélection, statuts/pipeline Kanban, historique, notes recruteur privées, retrait/refus/sélection. Dépend de core, users, companies, jobs.
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-applications
 *
 * @package Postelio\Applications
 *
 * Lot 05. NE contient PAS : messagerie réelle, entretiens réels, notifications
 * réelles, paiement, matching IA, modération générale.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_APPLICATIONS_VERSION', '0.1.0' );
define( 'POSTELIO_APPLICATIONS_FILE', __FILE__ );
define( 'POSTELIO_APPLICATIONS_DIR', plugin_dir_path( __FILE__ ) );

/**
 * « postelio-applications » précède « postelio-core » dans l'ordre alphabétique de
 * chargement : on enregistre l'autoloader dès que le core est disponible.
 */
function postelio_applications_register_autoloader(): bool {
	if ( ! class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
		return false;
	}
	static $done = false;
	if ( ! $done ) {
		\Postelio\Core\Autoloader::register( 'Postelio\\Applications\\', POSTELIO_APPLICATIONS_DIR . 'src/' );
		$done = true;
	}
	return true;
}
postelio_applications_register_autoloader();

add_action(
	'plugins_loaded',
	static function () {
		foreach ( array( 'Core', 'Users', 'Companies', 'Jobs' ) as $dep ) {
			if ( ! class_exists( '\\Postelio\\' . $dep . '\\Plugin' ) ) {
				add_action(
					'admin_notices',
					static function () {
						echo '<div class="notice notice-error"><p><strong>Postelio Applications</strong> requiert Core, Users, Companies et Jobs (activez-les d\'abord).</p></div>';
					}
				);
				return;
			}
		}
		postelio_applications_register_autoloader();
		\Postelio\Applications\Plugin::instance()->boot();
	},
	20 // Après core(0), users(5), companies(10), jobs(15).
);

register_activation_hook( __FILE__, array( '\\Postelio\\Applications\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Postelio\\Applications\\Plugin', 'deactivate' ) );
