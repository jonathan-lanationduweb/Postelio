<?php
/**
 * Plugin Name: Postelio Users
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Comptes candidats/recruteurs, profils de base, inscription/connexion, récupération de mot de passe, vérification e-mail optionnelle, authentification applicative (Bearer) compatible web + Tauri, préférences, export/suppression RGPD. Dépend de postelio-core.
 * Version:     0.2.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-users
 *
 * @package Postelio\Users
 *
 * Lot 02. NE contient PAS : entreprises, offres, candidatures, CV métier complet,
 * messages, entretiens, paiements, vérification Sirene/RNE.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_USERS_VERSION', '0.2.0' );
define( 'POSTELIO_USERS_FILE', __FILE__ );
define( 'POSTELIO_USERS_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Le socle transversal (postelio-core) est requis : on réutilise son autoloader,
 * son bus d'événements, ses migrations, ses erreurs et ses permissions.
 *
 * L'autoloader est enregistré dès l'inclusion (le core, actif, est chargé avant
 * nous) afin que les hooks d'activation/désactivation résolvent nos classes même
 * quand `plugins_loaded` a déjà été déclenché.
 */
if ( class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
	\Postelio\Core\Autoloader::register( 'Postelio\\Users\\', POSTELIO_USERS_DIR . 'src/' );
}

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p><strong>Postelio Users</strong> requiert le plugin <strong>Postelio Core</strong> (activez-le en premier).</p></div>';
				}
			);
			return;
		}
		\Postelio\Users\Plugin::instance()->boot();
	},
	5 // Après le core (priorité 0).
);

register_activation_hook( __FILE__, array( '\\Postelio\\Users\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Postelio\\Users\\Plugin', 'deactivate' ) );
