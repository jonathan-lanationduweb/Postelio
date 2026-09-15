<?php
/**
 * Sources d'offres : cartes d'intégration des connecteurs partenaires (France Travail…) via
 * `/job-sources/health` : état Connecté / Non connecté, offres importées, dernière synchronisation,
 * détails repliés. Aucun secret, aucune clé, aucune variable d'environnement affichée. La
 * synchronisation manuelle n'est PAS proposée : aucun contrat du domaine ne l'expose, et le
 * back-office ne crée pas de second moteur de synchronisation.
 *
 * @package Postelio\Backoffice\Screens
 */

namespace Postelio\Backoffice\Screens;

use Postelio\Backoffice\Menu;
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

	protected function eyebrow(): string {
		return 'Postelio · Système';
	}

	protected function body(): string {
		if ( ! Data::module_active( 'job-sources' ) && ! Data::module_active( 'job_sources' ) ) {
			return $this->header( 'Sources d\'offres', 'Import d\'offres partenaires.' )
				. Ui::empty_state( 'Module indisponible', 'Le module Sources d\'offres n\'est pas actif.' );
		}
		$out = $this->header( 'Sources d\'offres', 'Connecteurs partenaires : offres importées et synchronisées automatiquement.', Ui::button( 'Relire l\'état', $this->url( 'postelio-sources' ), '', true ) );

		$res = Rest::call( 'GET', '/postelio/v1/job-sources/health' );
		if ( 200 !== $res['status'] || ! is_array( $res['data'] ) ) {
			return $out . Ui::alert( 'L\'état des connecteurs est momentanément indisponible.', 'warning' );
		}
		$data      = (array) ( $res['data']['data'] ?? $res['data'] );
		$providers = (array) ( $data['providers'] ?? array() );
		if ( empty( $providers ) ) {
			return $out . Ui::empty_state( 'Aucun connecteur', 'Aucun connecteur d\'offres partenaires n\'est enregistré.' );
		}

		$out .= Ui::integrations_open();
		foreach ( $providers as $p ) {
			$out .= $this->integration( (array) $p );
		}
		$out .= Ui::integrations_close();
		$out .= Ui::help( 'La synchronisation est automatique et récurrente. Les identifiants des connecteurs sont lus dans l\'environnement du serveur : ils ne sont ni affichés ni modifiables ici.' );
		return $out;
	}

	/** @param array<string,mixed> $p */
	private function integration( array $p ): string {
		$available = ! empty( $p['available'] );
		$errored   = $available && ! empty( $p['last_run_status'] ) && 'success' !== (string) $p['last_run_status'];
		$state     = $errored ? array( 'Erreur', 'error' ) : ( $available ? array( 'Connecté', 'success' ) : array( 'Non connecté', 'neutral' ) );
		$name      = Fmt::or_dash( $p['label'] ?? ( $p['key'] ?? 'Connecteur' ) );

		$detail = Ui::kv( array(
			'Connecteur'       => Ui::text( '' !== (string) ( $p['key'] ?? '' ) ? (string) $p['key'] : 'inconnu', false, true ),
			'Dernier résultat' => Ui::text( '' !== (string) ( $p['last_run_status'] ?? '' ) ? ( 'success' === (string) $p['last_run_status'] ? 'Réussite' : 'Échec' ) : 'Aucune synchronisation', false, true ),
			'Identifiants'     => Ui::text( 'Configurés côté serveur, jamais affichés', false, true ),
		), true );
		if ( $errored ) {
			$err     = Fmt::excerpt( (string) ( $p['last_error'] ?? '' ), 140 );
			$detail .= Ui::alert( 'La dernière synchronisation a échoué.' . ( '' !== $err ? ' ' . $err : '' ), 'warning' );
		} elseif ( ! $available ) {
			$detail .= Ui::help( 'Ce connecteur n\'est pas connecté. La connexion se configure côté serveur ; aucune clé n\'est saisie dans le back-office.' );
		}

		return Ui::integration(
			$name,
			'Connecteur d\'offres partenaires',
			Ui::badge( $state[0], $state[1], true ),
			$available ? (int) ( $p['active_offers'] ?? 0 ) : null,
			'offres importées',
			array(
				'Dernière synchronisation' => Fmt::datetime( $p['last_run_at'] ?? '' ),
				'Dernière réussite'        => Fmt::datetime( $p['last_success_at'] ?? '' ),
			),
			Ui::details( 'Voir l\'état', $detail )
		);
	}
}
