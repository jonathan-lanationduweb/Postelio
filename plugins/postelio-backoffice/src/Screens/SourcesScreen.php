<?php
/**
 * Sources d'offres : état des connecteurs partenaires (France Travail…) via
 * `/job-sources/health`. Aucun secret, aucune clé, aucune variable d'environnement affichée. La
 * synchronisation manuelle n'est PAS proposée : aucun contrat du domaine ne l'expose, et le
 * back-office ne crée pas de second moteur de synchronisation.
 *
 * @package Postelio\Backoffice\Screens
 */

namespace Postelio\Backoffice\Screens;

use Postelio\Backoffice\Menu;
use Postelio\Backoffice\Screens\Screen;
use Postelio\Backoffice\Support\Data;
use Postelio\Backoffice\Support\Fmt;
use Postelio\Backoffice\Support\Rest;
use Postelio\Backoffice\Ui\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SourcesScreen extends Screen {

	protected function capability(): string {
		return Menu::CAP_ADMIN;
	}

	protected function body(): string {
		if ( ! Data::module_active( 'job-sources' ) && ! Data::module_active( 'job_sources' ) ) {
			return Ui::page_header( 'Sources d\'offres', 'Import d\'offres partenaires.' )
				. Ui::empty_state( 'Module indisponible', 'Le module Sources d\'offres n\'est pas actif.' );
		}
		$out = Ui::page_header( 'Sources d\'offres', 'Offres importées automatiquement depuis des partenaires.' );

		$res = Rest::call( 'GET', '/postelio/v1/job-sources/health' );
		if ( 200 !== $res['status'] || ! is_array( $res['data'] ) ) {
			return $out . Ui::alert( 'L\'état des connecteurs est momentanément indisponible.', 'warning' );
		}
		$data      = (array) ( $res['data']['data'] ?? $res['data'] );
		$providers = (array) ( $data['providers'] ?? array() );
		if ( empty( $providers ) ) {
			return $out . Ui::empty_state( 'Aucun connecteur', 'Aucun connecteur d\'offres partenaires n\'est enregistré.' );
		}

		$out .= '<div class="bo-grid bo-grid--2">';
		foreach ( $providers as $p ) {
			$out .= $this->provider_card( (array) $p );
		}
		$out .= '</div>';
		$out .= Ui::help( 'La synchronisation est automatique et récurrente. Les identifiants des connecteurs sont lus dans l\'environnement du serveur : ils ne sont ni affichés ni modifiables ici.' );
		return $out;
	}

	/** @param array<string,mixed> $p */
	private function provider_card( array $p ): string {
		$available = ! empty( $p['available'] );
		$errored   = $available && ! empty( $p['last_run_status'] ) && 'success' !== (string) $p['last_run_status'];
		$state     = $errored ? array( 'Erreur', 'error' ) : ( $available ? array( 'Connecté', 'success' ) : array( 'Non connecté', 'neutral' ) );

		$out  = Ui::card_open( Fmt::or_dash( $p['label'] ?? ( $p['key'] ?? 'Connecteur' ) ), '', Ui::badge( $state[0], $state[1], true ) );
		$out .= Ui::kv( array(
			'Offres importées'          => Ui::text( (string) (int) ( $p['active_offers'] ?? 0 ), true ),
			'Dernière synchronisation'  => Ui::text( Fmt::datetime( $p['last_run_at'] ?? '' ) ),
			'Dernière réussite'         => Ui::text( Fmt::datetime( $p['last_success_at'] ?? '' ) ),
		) );
		if ( ! $available ) {
			$out .= Ui::help( 'Ce connecteur n\'est pas connecté. La connexion se configure côté serveur ; aucune clé n\'est saisie dans le back-office.' );
		} elseif ( $errored ) {
			$err  = Fmt::excerpt( (string) ( $p['last_error'] ?? '' ), 140 );
			$out .= Ui::alert( 'La dernière synchronisation a échoué.' . ( '' !== $err ? ' ' . $err : '' ), 'warning' );
		}
		return $out . Ui::card_close();
	}
}
