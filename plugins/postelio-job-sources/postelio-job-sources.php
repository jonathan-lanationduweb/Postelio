<?php
/**
 * Plugin Name: Postelio Job Sources
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Agrégation/synchronisation d'offres EXTERNES (France Travail V1) dans une table dédiée, fusionnées à la recherche /jobs. Candidature externe par redirection (jamais de candidature Postelio). Dépend de core et jobs.
 * Version:     0.2.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-job-sources
 *
 * @package Postelio\JobSources
 *
 * Lot 10. Aucun scraping. Indeed/HelloWork/ATS = FUTUR/partenariat (non implémentés).
 * Secrets API en constantes/env (jamais en base ni Git).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_JOBSOURCES_VERSION', '0.2.0' );
define( 'POSTELIO_JOBSOURCES_FILE', __FILE__ );
define( 'POSTELIO_JOBSOURCES_DIR', plugin_dir_path( __FILE__ ) );

function postelio_job_sources_register_autoloader(): bool {
	if ( ! class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
		return false;
	}
	static $done = false;
	if ( ! $done ) {
		\Postelio\Core\Autoloader::register( 'Postelio\\JobSources\\', POSTELIO_JOBSOURCES_DIR . 'src/' );
		$done = true;
	}
	return true;
}
postelio_job_sources_register_autoloader();

add_action(
	'plugins_loaded',
	static function () {
		foreach ( array( 'Core', 'Jobs' ) as $dep ) {
			if ( ! class_exists( '\\Postelio\\' . $dep . '\\Plugin' ) ) {
				add_action(
					'admin_notices',
					static function () {
						echo '<div class="notice notice-error"><p><strong>Postelio Job Sources</strong> requiert Core et Jobs (activez-les d\'abord).</p></div>';
					}
				);
				return;
			}
		}
		postelio_job_sources_register_autoloader();
		\Postelio\JobSources\Plugin::instance()->boot();
	},
	40 // Après jobs.
);

register_activation_hook( __FILE__, array( '\\Postelio\\JobSources\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Postelio\\JobSources\\Plugin', 'deactivate' ) );
