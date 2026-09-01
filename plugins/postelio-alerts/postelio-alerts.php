<?php
/**
 * Plugin Name: Postelio Alerts
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Favoris, recherches sauvegardées et alertes emploi (candidat). Alertes daily/weekly planifiées via le Scheduler du core (07h30 Europe/Paris, DST-correct), déduplication garantie par table de deliveries (contrainte UNIQUE), matching UNIQUE via le contrat de recherche Jobs (natif + externe France Travail), aucun accès SQL direct aux offres, aucun e-mail direct (événements → Notifications). Digest : une notification par cycle.
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-alerts
 * Requires Plugins: postelio-core, postelio-users, postelio-jobs
 *
 * @package Postelio\Alerts
 *
 * Lot 14. Aucune dépendance Composer. Notifications/Job Sources optionnels (dégradation propre).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_ALERTS_VERSION', '0.1.0' );
define( 'POSTELIO_ALERTS_FILE', __FILE__ );
define( 'POSTELIO_ALERTS_DIR', plugin_dir_path( __FILE__ ) );

function postelio_alerts_register_autoloader(): bool {
	if ( ! class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
		return false;
	}
	static $done = false;
	if ( ! $done ) {
		\Postelio\Core\Autoloader::register( 'Postelio\\Alerts\\', POSTELIO_ALERTS_DIR . 'src/' );
		$done = true;
	}
	return true;
}
postelio_alerts_register_autoloader();

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			add_action( 'admin_notices', static function () {
				echo '<div class="notice notice-error"><p><strong>Postelio Alerts</strong> requiert Postelio Core (activez-le d\'abord).</p></div>';
			} );
			return;
		}
		postelio_alerts_register_autoloader();
		\Postelio\Alerts\Plugin::instance()->boot();
	},
	44 // Après jobs/job-sources/notifications (filtres/événements résolus au runtime).
);

register_activation_hook( __FILE__, array( '\\Postelio\\Alerts\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Postelio\\Alerts\\Plugin', 'deactivate' ) );
