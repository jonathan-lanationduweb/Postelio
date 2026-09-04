<?php
/**
 * Amorçage du back-office unifié. COUCHE D'ADMINISTRATION uniquement : menu, design system, écrans,
 * actions, assets. Aucune logique métier, aucune table, aucune écriture directe dans les tables des
 * autres plugins — tout passe par leurs contrats/services publics. Ne fatal jamais si un module est
 * absent (chaque écran dégrade en « Module indisponible »).
 *
 * Depuis la Phase 3, ce plugin rend TOUS les écrans Postelio : le plugin historique
 * `postelio-admin` n'est plus sollicité (ni menu, ni écrans, ni actions, ni assets).
 *
 * @package Postelio\Backoffice
 */

namespace Postelio\Backoffice;

use Postelio\Backoffice\Actions\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;
	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Les actions admin-post doivent être enregistrées même hors écran d'administration
		// (admin-post.php n'est pas `is_admin()` au sens des écrans, mais l'est bien pour WordPress) :
		// on les branche systématiquement, la garde de capability restant côté gestionnaire.
		( new Actions() )->register();

		if ( ! is_admin() ) {
			return;
		}
		( new Menu() )->register();
		( new Assets() )->register();
	}
}
