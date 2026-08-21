<?php
/**
 * Plugin Name: Postelio Core
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Socle transversal de Postelio : registry des modules, socle REST (namespace postelio/v1, enveloppe, erreurs), bus d'événements, permissions (rôles/capabilities), audit log, framework de migrations, abstraction cron/queue, health/status. AUCUNE logique métier.
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-core
 *
 * @package Postelio\Core
 *
 * Lot 01 — plugin transversal. Ce plugin n'implémente aucun domaine métier
 * (offres, candidatures, profils, messages, entretiens, paiements, vérification).
 * Il fournit uniquement l'infrastructure commune décrite dans /docs/backend/.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Constantes du plugin ---------------------------------------------------
define( 'POSTELIO_CORE_VERSION', '0.1.0' );
define( 'POSTELIO_CORE_FILE', __FILE__ );
define( 'POSTELIO_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'POSTELIO_CORE_URL', plugin_dir_url( __FILE__ ) );

// Namespace REST commun (voir docs/backend/api-contract.md).
if ( ! defined( 'POSTELIO_REST_NAMESPACE' ) ) {
	define( 'POSTELIO_REST_NAMESPACE', 'postelio/v1' );
}

// --- Autoloader PSR-4 (sans dépendance externe) -----------------------------
require_once POSTELIO_CORE_DIR . 'src/Autoloader.php';
\Postelio\Core\Autoloader::register( 'Postelio\\Core\\', POSTELIO_CORE_DIR . 'src/' );

// --- Cycle de vie -----------------------------------------------------------
register_activation_hook( __FILE__, array( '\\Postelio\\Core\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Postelio\\Core\\Plugin', 'deactivate' ) );

// --- Amorçage ---------------------------------------------------------------
add_action(
	'plugins_loaded',
	static function () {
		\Postelio\Core\Plugin::instance()->boot();
	},
	0 // Le core démarre avant les plugins métier.
);
