<?php
/**
 * Santé du système : agrège le snapshot du core + l'état des modules (et leurs /health). Aucun
 * secret. États : OK / DÉGRADÉ / NON CONFIGURÉ / ERREUR / ABSENT.
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Health;
use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HealthPage extends Page {

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_ADMIN;
	}

	protected function body(): string {
		$snap = Health::snapshot();
		$core = $snap['core'];

		$out  = Ui::header( 'Santé du système', 'État des modules Postelio (sans aucun secret)' );

		// Core
		$out .= Ui::card_open( 'Core' );
		$out .= '<div class="pst-admin-kv"><dt>Statut</dt><dd>' . Ui::badge( Health::label( (string) $core['status'] ), Health::badge_variant( (string) $core['status'] ), true ) . '</dd>';
		$out .= '<dt>Version</dt><dd>' . esc_html( (string) $core['version'] ) . '</dd>';
		$out .= '<dt>Schéma</dt><dd>' . esc_html( (string) $core['schema'] ) . '</dd>';
		foreach ( (array) $core['checks'] as $k => $v ) {
			$out .= '<dt>' . esc_html( ucfirst( str_replace( '_', ' ', (string) $k ) ) ) . '</dt><dd>' . Ui::badge( $v ? 'OK' : 'KO', $v ? 'success' : 'error' ) . '</dd>';
		}
		$out .= '</div>' . Ui::card_close();

		// Modules
		$rows = array();
		foreach ( $snap['modules'] as $m ) {
			$rows[] = array(
				Ui::text( (string) $m['label'], true ),
				Ui::badge( Health::label( (string) $m['status'] ), Health::badge_variant( (string) $m['status'] ), true ),
				Ui::text( (string) ( $m['meta'] ?? '' ), false, true ),
			);
		}
		$out .= Ui::card_open( 'Modules' );
		$out .= Ui::table( array( 'Module', 'État', 'Détails' ), $rows, 'Aucun module enregistré.' );
		$out .= Ui::card_close();

		return $out;
	}
}
