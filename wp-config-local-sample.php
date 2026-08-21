<?php
/**
 * Modèle de configuration WordPress LOCAL pour Postelio (WAMP).
 *
 * Copier ce fichier vers  /wordpress/wp-config.php  (NON versionné, ignoré par Git)
 * puis renseigner les valeurs locales. NE JAMAIS committer wp-config.php ni de clés.
 *
 * Génération des salts (SECRET keys) :
 *   https://api.wordpress.org/secret-key/1.1/salt/
 * (à coller à la place du bloc « define('AUTH_KEY', ...) » ci-dessous).
 */

// --- Base de données locale dédiée à Postelio -----------------------------
define( 'DB_NAME',     'postelio_local' );
define( 'DB_USER',     'root' );            // WAMP par défaut
define( 'DB_PASSWORD', '' );                // WAMP par défaut : vide (adapter si besoin)
define( 'DB_HOST',     '127.0.0.1:3306' );  // MySQL 8.4 / MariaDB 11.4 selon WAMP
define( 'DB_CHARSET',  'utf8mb4' );
define( 'DB_COLLATE',  '' );
$table_prefix = 'wp_';

// --- URLs locales (voir docs/backend/local-setup.md) ----------------------
// Option A (sans vhost) : http://localhost/Postelio/wordpress
// Option B (vhost)      : http://postelio.local
define( 'WP_HOME',    'http://localhost/Postelio/wordpress' );
define( 'WP_SITEURL', 'http://localhost/Postelio/wordpress' );

// --- Clés de sécurité (REMPLACER par des salts générés, ne pas committer) --
define( 'AUTH_KEY',         'à-générer' );
define( 'SECURE_AUTH_KEY',  'à-générer' );
define( 'LOGGED_IN_KEY',    'à-générer' );
define( 'NONCE_KEY',        'à-générer' );
define( 'AUTH_SALT',        'à-générer' );
define( 'SECURE_AUTH_SALT', 'à-générer' );
define( 'LOGGED_IN_SALT',   'à-générer' );
define( 'NONCE_SALT',       'à-générer' );

// --- Débogage local -------------------------------------------------------
define( 'WP_DEBUG',         true );
define( 'WP_DEBUG_LOG',     true );   // écrit dans wp-content/debug.log (ignoré par Git)
define( 'WP_DEBUG_DISPLAY', false );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
