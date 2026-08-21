<?php
/**
 * Plugin Name: Postelio Jobs
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Offres d'emploi (brouillons, publication conditionnée à une entreprise vérifiée — D1, cycle de vie, expiration, archivage, duplication). Dépend de postelio-core, postelio-users et postelio-companies.
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-jobs
 *
 * @package Postelio\Jobs
 *
 * Lot 04. NE contient PAS : candidatures, messagerie, entretiens, facturation
 * réelle (renouvellement payant), savoir-faire, notifications réelles,
 * modération générale. Favoris/alertes candidat : hors périmètre de ce lot.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_JOBS_VERSION', '0.1.0' );
define( 'POSTELIO_JOBS_FILE', __FILE__ );
define( 'POSTELIO_JOBS_DIR', plugin_dir_path( __FILE__ ) );

/**
 * WordPress inclut les plugins par ordre alphabétique ; on ne peut donc pas
 * supposer le core chargé à l'inclusion. On enregistre l'autoloader dès que le
 * core est disponible (inclusion si possible — pour l'activation — sinon dans
 * plugins_loaded).
 */
function postelio_jobs_register_autoloader(): bool {
	if ( ! class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
		return false;
	}
	static $done = false;
	if ( ! $done ) {
		\Postelio\Core\Autoloader::register( 'Postelio\\Jobs\\', POSTELIO_JOBS_DIR . 'src/' );
		$done = true;
	}
	return true;
}
postelio_jobs_register_autoloader();

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' )
			|| ! class_exists( '\\Postelio\\Users\\Plugin' )
			|| ! class_exists( '\\Postelio\\Companies\\Plugin' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p><strong>Postelio Jobs</strong> requiert <strong>Core</strong>, <strong>Users</strong> et <strong>Companies</strong> (activez-les d\'abord).</p></div>';
				}
			);
			return;
		}
		postelio_jobs_register_autoloader();
		\Postelio\Jobs\Plugin::instance()->boot();
	},
	15 // Après core (0), users (5), companies (10).
);

register_activation_hook( __FILE__, array( '\\Postelio\\Jobs\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Postelio\\Jobs\\Plugin', 'deactivate' ) );
