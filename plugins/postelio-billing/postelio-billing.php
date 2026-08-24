<?php
/**
 * Plugin Name: Postelio Billing
 * Plugin URI:  https://github.com/jonathan-lanationduweb/Postelio
 * Description: Renouvellement d'offre payant (10 €/30 j) via Stripe Checkout hosted. Ordres ≠ paiements, webhook signé = source de vérité, fulfillment exactly-once délégué à Jobs, retry via Scheduler. Aucun code Stripe hors du provider ; aucun envoi d'e-mail (événements → Notifications). Reçu Stripe (pas de facture légale V1).
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Postelio
 * License:     GPL-2.0-or-later
 * Text Domain: postelio-billing
 * Requires Plugins: postelio-core, postelio-jobs
 *
 * @package Postelio\Billing
 *
 * Lot 12. Aucune dépendance Composer (client HTTP Stripe léger via wp_remote_*, cohérent avec
 * le Lot 10). Secrets Stripe en env/wp-config, jamais en base ni Git.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSTELIO_BILLING_VERSION', '0.1.0' );
define( 'POSTELIO_BILLING_FILE', __FILE__ );
define( 'POSTELIO_BILLING_DIR', plugin_dir_path( __FILE__ ) );

function postelio_billing_register_autoloader(): bool {
	if ( ! class_exists( '\\Postelio\\Core\\Autoloader' ) ) {
		return false;
	}
	static $done = false;
	if ( ! $done ) {
		\Postelio\Core\Autoloader::register( 'Postelio\\Billing\\', POSTELIO_BILLING_DIR . 'src/' );
		$done = true;
	}
	return true;
}
postelio_billing_register_autoloader();

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			add_action( 'admin_notices', static function () {
				echo '<div class="notice notice-error"><p><strong>Postelio Billing</strong> requiert Postelio Core (activez-le d\'abord).</p></div>';
			} );
			return;
		}
		postelio_billing_register_autoloader();
		\Postelio\Billing\Plugin::instance()->boot();
	},
	50 // Après jobs/companies/users/notifications (billing dépend de jobs).
);

register_activation_hook( __FILE__, array( '\\Postelio\\Billing\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Postelio\\Billing\\Plugin', 'deactivate' ) );
