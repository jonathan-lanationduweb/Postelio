<?php
/**
 * Plugin Name: Postelio Files
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Infrastructure de fichiers privés (CV candidat, versions, snapshot de candidature, téléchargement/aperçu sécurisés) derrière une abstraction StorageProvider (local privé en V1). Dépend de postelio-core et postelio-users.
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-files
 *
 * @package Postelio\Files
 *
 * Lot 06. NE contient PAS : messagerie, entretiens, facturation, S3 réel,
 * antivirus externe, parsing IA du CV.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_FILES_VERSION', '0.1.0' );
define( 'POSTELIO_FILES_FILE', __FILE__ );
define( 'POSTELIO_FILES_DIR', plugin_dir_path( __FILE__ ) );

function postelio_files_register_autoloader(): bool {
	if ( ! class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
		return false;
	}
	static $done = false;
	if ( ! $done ) {
		\Postelio\Core\Autoloader::register( 'Postelio\\Files\\', POSTELIO_FILES_DIR . 'src/' );
		$done = true;
	}
	return true;
}
postelio_files_register_autoloader();

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) || ! class_exists( '\\Postelio\\Users\\Plugin' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p><strong>Postelio Files</strong> requiert <strong>Core</strong> et <strong>Users</strong> (activez-les d\'abord).</p></div>';
				}
			);
			return;
		}
		postelio_files_register_autoloader();
		\Postelio\Files\Plugin::instance()->boot();
	},
	6 // Après core (0) et users (5).
);

register_activation_hook( __FILE__, array( '\\Postelio\\Files\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Postelio\\Files\\Plugin', 'deactivate' ) );
