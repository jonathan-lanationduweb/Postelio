<?php
/**
 * Plugin Name: Postelio Companies
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Entreprises (profil employeur), rattachement des recruteurs (membres/propriétaire) et cadre de vérification (Sirene/RNE via provider, sans API réelle). Dépend de postelio-core et postelio-users.
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-companies
 *
 * @package Postelio\Companies
 *
 * Lot 03. NE contient PAS : offres, candidatures, messagerie, entretiens,
 * facturation, savoir-faire, notifications réelles, modération générale,
 * intégration Sirene/RNE réelle.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_COMPANIES_VERSION', '0.1.0' );
define( 'POSTELIO_COMPANIES_FILE', __FILE__ );
define( 'POSTELIO_COMPANIES_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Enregistre l'autoloader companies via l'autoloader du core.
 *
 * WordPress inclut les plugins par ordre alphabétique : « postelio-companies »
 * précède « postelio-core ». On ne peut donc PAS supposer le core chargé à
 * l'inclusion. On enregistre donc l'autoloader dès que le core est disponible :
 *  - à l'inclusion si le core l'est déjà (cas de l'activation, core actif d'abord) ;
 *  - sinon dans `plugins_loaded` (tous les fichiers plugins sont alors inclus).
 */
function postelio_companies_register_autoloader(): bool {
	if ( ! class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
		return false;
	}
	static $done = false;
	if ( ! $done ) {
		\Postelio\Core\Autoloader::register( 'Postelio\\Companies\\', POSTELIO_COMPANIES_DIR . 'src/' );
		$done = true;
	}
	return true;
}
postelio_companies_register_autoloader();

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) || ! class_exists( '\\Postelio\\Users\\Plugin' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p><strong>Postelio Companies</strong> requiert <strong>Postelio Core</strong> et <strong>Postelio Users</strong> (activez-les d\'abord).</p></div>';
				}
			);
			return;
		}
		postelio_companies_register_autoloader();
		\Postelio\Companies\Plugin::instance()->boot();
	},
	10 // Après core (0) et users (5).
);

register_activation_hook( __FILE__, array( '\\Postelio\\Companies\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Postelio\\Companies\\Plugin', 'deactivate' ) );
