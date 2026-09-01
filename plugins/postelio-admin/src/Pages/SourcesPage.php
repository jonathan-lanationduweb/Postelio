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
			return Ui::toolbar( 'Sources d\'offres', 'Import automatique d\'offres partenaires.' ) . Ui::empty_state( 'Module indisponible', 'Le module Sources d\'offres n\'est pas actif.', '🔌' );
		}
		$out = Ui::toolbar( 'Sources d\'offres', 'Import automatique d\'offres partenaires sur Postelio.' );

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
			$p      = (array) $p;
			$avail  = ! empty( $p['available'] );
			$errored = ! $avail ? false : ( ! empty( $p['last_run_status'] ) && 'success' !== $p['last_run_status'] );
			$state   = $errored ? array( 'Erreur', 'error' ) : ( $avail ? array( 'Connecté', 'success' ) : array( 'Non connecté', 'neutral' ) );

			$out  .= Ui::card_open( (string) ( $p['label'] ?? $p['key'] ?? 'Connecteur' ) );
			$out  .= '<p>' . Ui::badge( $state[0], $state[1], true ) . '</p>';
			$out  .= '<dl class="pst-admin-kv">';
			$out  .= '<dt>Offres importées</dt><dd>' . esc_html( (string) ( $p['active_offers'] ?? 0 ) ) . '</dd>';
			$out  .= '<dt>Dernière synchronisation</dt><dd>' . esc_html( $this->fmt( $p['last_run_at'] ?? null ) ) . '</dd>';
			$out  .= '<dt>Dernière réussite</dt><dd>' . esc_html( $this->fmt( $p['last_success_at'] ?? null ) ) . '</dd>';
			$out  .= '</dl>';
			if ( ! $avail ) {
				$out .= '<p class="pst-help">Connectez France&nbsp;Travail pour importer automatiquement des offres partenaires. La connexion (clés d\'API) se configure côté serveur — jamais saisie ici.</p>';
			} elseif ( $errored ) {
				$out .= Ui::alert( 'La dernière synchronisation a échoué. Vérifiez la configuration du connecteur.', 'warning' );
			}
			$out  .= Ui::card_close();
		}
		$out .= '</div>';
		$out .= Ui::alert( 'La synchronisation des offres partenaires est automatique et récurrente.', 'info' );
		return $out;
	}

	private function fmt( $v ): string {
		return $v ? mysql2date( 'd/m/Y H:i', (string) $v ) : '—';
	}
}
