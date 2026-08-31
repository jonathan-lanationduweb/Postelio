<?php
/**
 * Sources d'offres : état des connecteurs (France Travail…) via /job-sources/health. Aucun
 * secret. Ne simule aucun provider inexistant. La synchronisation manuelle n'est PAS exposée
 * par le domaine → non proposée (pas de second moteur de sync dans l'admin).
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Contracts;
use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SourcesPage extends Page {

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_ADMIN;
	}

	protected function body(): string {
		if ( ! Contracts::module_active( 'job-sources' ) && ! Contracts::module_active( 'job_sources' ) ) {
			return Ui::header( 'Sources d\'offres', 'Back-office Postelio' ) . Ui::empty_state( 'Module indisponible', 'Le module Sources d\'offres n\'est pas actif.', '🔌' );
		}
		$out = Ui::header( 'Sources d\'offres', 'Connecteurs d\'agrégation d\'offres externes' );

		$res = Contracts::rest( 'GET', '/postelio/v1/job-sources/health' );
		if ( 200 !== $res['status'] || ! is_array( $res['data'] ) ) {
			return $out . Ui::alert( 'État des sources momentanément indisponible.', 'warning' );
		}
		$providers = (array) ( $res['data']['data']['providers'] ?? array() );
		if ( empty( $providers ) ) {
			return $out . Ui::empty_state( 'Aucun connecteur', 'Aucun connecteur d\'offres n\'est enregistré.', '🔌' );
		}

		$out .= '<div class="pst-admin-grid pst-admin-grid--2">';
		foreach ( $providers as $p ) {
			$p     = (array) $p;
			$avail = ! empty( $p['available'] );
			$out  .= Ui::card_open( (string) ( $p['label'] ?? $p['key'] ?? 'Provider' ), $avail ? '' : '(non configuré)' );
			$out  .= '<p>' . Ui::badge( $avail ? 'Configuré' : 'Non configuré', $avail ? 'success' : 'info', true ) . '</p>';
			$out  .= '<dl class="pst-admin-kv">';
			$out  .= '<dt>Offres actives</dt><dd>' . esc_html( (string) ( $p['active_offers'] ?? 0 ) ) . '</dd>';
			$out  .= '<dt>Dernier run</dt><dd>' . esc_html( $this->fmt( $p['last_run_at'] ?? null ) ) . ' ' . ( ! empty( $p['last_run_status'] ) ? Ui::badge( (string) $p['last_run_status'], 'success' === $p['last_run_status'] ? 'success' : 'warning' ) : '' ) . '</dd>';
			$out  .= '<dt>Dernière réussite</dt><dd>' . esc_html( $this->fmt( $p['last_success_at'] ?? null ) ) . '</dd>';
			if ( ! empty( $p['last_error'] ) ) {
				$out .= '<dt>Dernière erreur</dt><dd>' . esc_html( mb_substr( (string) $p['last_error'], 0, 160 ) ) . '</dd>';
			}
			$out  .= '</dl>';
			if ( ! $avail ) {
				$out .= '<p class="pst-admin-stat__sub">Secrets requis en environnement (POSTELIO_FT_CLIENT_ID / _SECRET). Jamais saisis ici.</p>';
			}
			$out  .= Ui::card_close();
		}
		$out .= '</div>';
		$out .= Ui::alert( 'La synchronisation s\'exécute via le planificateur du domaine (worker récurrent). Le déclenchement manuel n\'est pas exposé par un contrat — aucun second moteur de sync n\'est ajouté ici.', 'info' );
		return $out;
	}

	private function fmt( $v ): string {
		return $v ? mysql2date( 'd/m/Y H:i', (string) $v ) : '—';
	}
}
