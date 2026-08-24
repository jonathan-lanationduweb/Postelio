<?php
/**
 * Plugin Name: Postelio Skills
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Savoir-faire & Avis : contenus éditoriaux publics (candidat/entreprise) + commentaires. Publication modérée (gate préventive, comme les offres), images via WordPress Media, taxonomies natives, SEO en contrat API. Notation/likes hors V1. Aucun provider externe, aucun e-mail direct.
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-skills
 * Requires Plugins: postelio-core, postelio-users, postelio-companies
 *
 * @package Postelio\Skills
 *
 * Lot 13. Aucune dépendance Composer. Modération réutilisée (ModerationGateway + reports).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_SKILLS_VERSION', '0.1.0' );
define( 'POSTELIO_SKILLS_FILE', __FILE__ );
define( 'POSTELIO_SKILLS_DIR', plugin_dir_path( __FILE__ ) );

function postelio_skills_register_autoloader(): bool {
	if ( ! class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
		return false;
	}
	static $done = false;
	if ( ! $done ) {
		\Postelio\Core\Autoloader::register( 'Postelio\\Skills\\', POSTELIO_SKILLS_DIR . 'src/' );
		$done = true;
	}
	return true;
}
postelio_skills_register_autoloader();

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			add_action( 'admin_notices', static function () {
				echo '<div class="notice notice-error"><p><strong>Postelio Skills</strong> requiert Postelio Core (activez-le d\'abord).</p></div>';
			} );
			return;
		}
		postelio_skills_register_autoloader();
		\Postelio\Skills\Plugin::instance()->boot();
	},
	42 // Après users/companies/moderation ; avant/après importe peu (filtres au runtime).
);

register_activation_hook( __FILE__, array( '\\Postelio\\Skills\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Postelio\\Skills\\Plugin', 'deactivate' ) );
