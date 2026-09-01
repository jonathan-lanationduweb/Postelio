<?php
/**
 * Favoris & Alertes — SUPERVISION AGRÉGÉE (privacy-first). N'affiche QUE des compteurs et l'état
 * du planificateur d'alertes. Jamais le contenu des recherches, les termes/villes recherchés, ni
 * les favoris d'un utilisateur : ces données personnelles ne sont pas exposées au back-office.
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Contracts;
use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AlertsPage extends Page {

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_ADMIN;
	}

	protected function body(): string {
		if ( ! Contracts::module_active( 'alerts' ) || ! Contracts::has( '\\Postelio\\Alerts\\Api\\AlertsAdminDirectory' ) ) {
			return Ui::toolbar( 'Favoris & Alertes', 'Supervision des favoris et alertes emploi.' )
				. Ui::empty_state( 'Module indisponible', 'Le module Favoris & Alertes n\'est pas actif.', '🔔' );
		}
		$out = Ui::toolbar( 'Favoris & Alertes', 'Supervision agrégée (aucune donnée personnelle : ni recherches, ni favoris individuels).' );

		$s   = \Postelio\Alerts\Api\AlertsAdminDirectory::stats();
		$sch = is_array( $s['scheduler'] ?? null ) ? $s['scheduler'] : array();

		$out .= '<div class="pst-admin-grid">';
		$out .= Ui::stat( 'Favoris enregistrés', (string) (int) ( $s['favorites_total'] ?? 0 ) );
		$out .= Ui::stat( 'Recherches sauvegardées', (string) (int) ( $s['saved_searches_total'] ?? 0 ) );
		$out .= Ui::stat( 'Alertes actives', (string) (int) ( $s['active_alerts_total'] ?? 0 ) );
		$out .= Ui::stat( 'Digests envoyés (24 h)', (string) (int) ( $s['digests_sent_24h'] ?? 0 ) );
		$out .= '</div>';

		$armed = ! empty( $sch['dispatch_armed'] );
		$out  .= Ui::card_open( 'Planificateur d\'alertes' ) . '<dl class="pst-admin-kv">';
		$out  .= '<dt>Traitement quotidien</dt><dd>' . Ui::badge( $armed ? 'Programmé (07h30 Europe/Paris)' : 'Non programmé', $armed ? 'success' : 'warning', true ) . '</dd>';
		$out  .= '<dt>Prochaine exécution</dt><dd>' . esc_html( ! empty( $sch['next_dispatch_at'] ) ? mysql2date( 'd/m/Y H:i', (string) $sch['next_dispatch_at'] ) . ' UTC' : '—' ) . '</dd>';
		$out  .= '</dl>';
		if ( ! $armed ) {
			$out .= Ui::alert( 'Le traitement des alertes n\'est pas programmé. Il se réarme automatiquement au prochain chargement ; si le problème persiste, vérifier WP-Cron.', 'warning' );
		}
		$out .= Ui::card_close();

		$out .= Ui::alert( 'Confidentialité : le détail des recherches et des favoris des candidats n\'est jamais affiché ici. Seuls des compteurs agrégés et l\'état du planificateur sont exposés.', 'info' );
		return $out;
	}
}
