<?php
/**
 * Amorçage du back-office unifié. COUCHE D'ADMINISTRATION uniquement : menu, design system, écrans,
 * assets. Aucune logique métier, aucune table, aucune écriture directe dans les tables des autres
 * plugins — tout passe par leurs contrats/services publics. Ne fatal jamais si un module est absent.
 *
 * Phase 1 : squelette + design system + menu + Tableau de bord + Mon site (Vue d'ensemble, Accueil).
 * Les autres écrans sont DÉLÉGUÉS au plugin legacy Postelio Admin (voir Legacy / Menu::MIGRATED).
 *
 * @package Postelio\Backoffice
 */

namespace Postelio\Backoffice;

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
		if ( $this->booted || ! is_admin() ) {
			return;
		}
		$this->booted = true;

		( new Menu() )->register();
		( new Assets() )->register();
		( new Legacy() )->register();
	}
}
