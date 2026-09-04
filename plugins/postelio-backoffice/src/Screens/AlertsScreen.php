<?php
/**
 * Favoris & Alertes : supervision AGRÉGÉE (privacy-first). Uniquement des compteurs et l'état du
 * planificateur d'alertes : jamais le contenu des recherches sauvegardées, les critères, ni les
 * favoris d'un utilisateur — ce sont des données personnelles qui n'ont pas à remonter ici.
 *
 * @package Postelio\Backoffice\Screens
 */

namespace Postelio\Backoffice\Screens;

use Postelio\Backoffice\Menu;
use Postelio\Backoffice\Support\Data;
use Postelio\Backoffice\Support\Fmt;
use Postelio\Backoffice\Ui\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AlertsScreen extends Screen {

	private const DIR = '\\Postelio\\Alerts\\Api\\AlertsAdminDirectory';

	protected function capability(): string {
		return Menu::CAP_ADMIN;
	}

	protected function body(): string {
		if ( ! Data::module_active( 'alerts' ) || ! Data::has( self::DIR ) ) {
			return Ui::page_header( 'Favoris & Alertes', 'Supervision des favoris et alertes emploi.' )
				. Ui::empty_state( 'Module indisponible', 'Le module Favoris & Alertes n\'est pas actif.' );
		}
		$s   = (array) call_user_func( array( self::DIR, 'stats' ) );
		$sch = is_array( $s['scheduler'] ?? null ) ? $s['scheduler'] : array();

		$out  = Ui::page_header( 'Favoris & Alertes', 'Compteurs agrégés : aucune donnée personnelle.' );
		$out .= '<div class="bo-stats bo-stats--4">';
		$out .= Ui::stat( 'Favoris enregistrés', (int) ( $s['favorites_total'] ?? 0 ) );
		$out .= Ui::stat( 'Recherches sauvegardées', (int) ( $s['saved_searches_total'] ?? 0 ) );
		$out .= Ui::stat( 'Alertes actives', (int) ( $s['active_alerts_total'] ?? 0 ) );
		$out .= Ui::stat( 'Envois (24 h)', (int) ( $s['digests_sent_24h'] ?? 0 ) );
		$out .= '</div>';

		$armed = ! empty( $sch['dispatch_armed'] );
		$out  .= Ui::card_open( 'Envoi quotidien des alertes' ) . Ui::kv( array(
			'Programmation'        => Ui::badge( $armed ? 'Programmé (07h30, Europe/Paris)' : 'Non programmé', $armed ? 'success' : 'warning', true ),
			'Prochaine exécution'  => Ui::text( Fmt::datetime( $sch['next_dispatch_at'] ?? '' ) ),
		) );
		if ( ! $armed ) {
			$out .= Ui::alert( 'L\'envoi des alertes n\'est pas programmé. Il se réarme au prochain chargement ; si le problème persiste, vérifiez les tâches planifiées.', 'warning' );
		}
		$out .= Ui::card_close();

		$out .= Ui::help( 'Le détail des recherches et des favoris des candidats n\'est jamais affiché : seuls des compteurs agrégés et l\'état du planificateur sont exposés.' );
		return $out;
	}
}
