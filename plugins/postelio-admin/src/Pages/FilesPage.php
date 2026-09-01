<?php
/**
 * CV & fichiers : PAS une bibliothèque de CV navigable. KPI globaux (par statut / stockage /
 * provider / quarantaine) + liste TECHNIQUE minimale en métadonnées (UUID court, type, statut,
 * taille, provider, date, référencé par une candidature oui/non). JAMAIS de chemin disque, de
 * storage_key, de nom de fichier, de contenu ni de bouton télécharger (aucun contrat admin de
 * téléchargement n'existe). Lecture seule via FileAdminDirectory.
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Contracts;
use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FilesPage extends Page {

	private const PER_PAGE = 30;
	private const DIR      = '\\Postelio\\Files\\Api\\FileAdminDirectory';

	/** @var array<string,array{0:string,1:string}> */
	private const STATUSES = array(
		'ready'       => array( 'Actif', 'success' ),
		'archived'    => array( 'Archivé', 'neutral' ),
		'quarantined' => array( 'Quarantaine', 'error' ),
		'deleted'     => array( 'Supprimé', 'neutral' ),
	);

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_ADMIN;
	}

	protected function body(): string {
		if ( ! Contracts::has( self::DIR ) ) {
			return Ui::header( 'CV & fichiers', 'Back-office Postelio' )
				. Ui::empty_state( 'Module indisponible', 'Le module Fichiers n\'est pas actif.', '📎' );
		}
		$counts = call_user_func( array( self::DIR, 'counts' ) );
		$bys    = is_array( $counts['by_status'] ?? null ) ? $counts['by_status'] : array();

		$out  = Ui::toolbar( 'CV & fichiers', 'Supervision technique du stockage (métadonnées uniquement).' );

		// KPI.
		$out .= '<div class="pst-admin-grid">';
		$out .= Ui::stat( 'Fichiers', (string) (int) ( $counts['total'] ?? 0 ), 'au total' );
		$out .= Ui::stat( 'Actifs', (string) (int) ( $bys['ready'] ?? 0 ), '', true );
		$out .= Ui::stat( 'Archivés', (string) (int) ( $bys['archived'] ?? 0 ) );
		$out .= Ui::stat( 'Quarantaine', (string) (int) ( $counts['quarantined'] ?? 0 ), 'scanner', (int) ( $counts['quarantined'] ?? 0 ) > 0 );
		$out .= Ui::stat( 'Supprimés', (string) (int) ( $bys['deleted'] ?? 0 ) );
		$out .= Ui::stat( 'Stockage vivant', $this->bytes( (int) ( $counts['live_bytes'] ?? 0 ) ), 'hors supprimés' );
		$out .= '</div>';

		// Providers.
		$prov = is_array( $counts['by_provider'] ?? null ) ? $counts['by_provider'] : array();
		if ( ! empty( $prov ) ) {
			$chips = array();
			foreach ( $prov as $k => $n ) {
				$chips[] = Ui::badge( $k . ' · ' . (int) $n, 'neutral' );
			}
			$out .= Ui::card_open( 'Providers de stockage' ) . '<p>' . implode( ' ', $chips ) . '</p>' . Ui::card_close();
		}

		if ( (int) ( $counts['quarantined'] ?? 0 ) > 0 ) {
			$out .= Ui::alert( 'Des fichiers sont en quarantaine (jugés suspects par le scanner). Le traitement se fait via le module Fichiers ; le back-office n\'effectue aucune action directe sur les fichiers.', 'warning' );
		}

		// Liste technique.
		$tab   = $this->current( 'tab', 'all' );
		$paged = $this->paged();
		$filters = array();
		if ( 'all' !== $tab && isset( self::STATUSES[ $tab ] ) ) {
			$filters['status'] = $tab;
		}
		$res = call_user_func( array( self::DIR, 'list' ), $filters, $paged, self::PER_PAGE );

		$tabs = array( array( 'label' => 'Tous', 'url' => $this->url( 'postelio-files', array( 'tab' => 'all' ) ), 'count' => (int) ( $counts['total'] ?? 0 ), 'active' => 'all' === $tab ) );
		foreach ( self::STATUSES as $st => $meta ) {
			$tabs[] = array( 'label' => $meta[0], 'url' => $this->url( 'postelio-files', array( 'tab' => $st ) ), 'count' => (int) ( $bys[ $st ] ?? 0 ), 'active' => $st === $tab );
		}
		$out .= Ui::tabs( $tabs );

		// Référencement par candidature (batch, sans N+1) si le contrat Applications est présent.
		$refs = array();
		if ( Contracts::has( '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory' ) ) {
			$uuids = array();
			foreach ( $res['items'] as $f ) {
				$uuids[] = (string) $f['uuid'];
			}
			$refs = (array) call_user_func( array( '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory', 'referenced_cv' ), $uuids );
		}

		$rows = array();
		foreach ( $res['items'] as $f ) {
			$rows[] = $this->row( (array) $f, ! empty( $refs[ (string) $f['uuid'] ] ) );
		}
		$out .= Ui::table( array( 'Réf.', 'Type', 'Statut', 'Taille', 'Provider', 'Ajouté', 'Référencé' ), $rows, 'Aucun fichier.' );
		$out .= Ui::pager( $this->url( 'postelio-files', array( 'tab' => $tab ) ), $paged, self::PER_PAGE, (int) $res['total'] );
		return $out;
	}

	/** @param array<string,mixed> $f @return array<int,string> */
	private function row( array $f, bool $referenced ): array {
		$st = (string) $f['status'];
		$m  = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		$ref = $referenced ? Ui::badge( 'Oui', 'success', true ) : Ui::text( 'Non', false, true );
		$type = 'cv' === (string) $f['type'] ? 'CV' : (string) $f['type'];
		return array(
			Ui::text( substr( (string) $f['uuid'], 0, 8 ) . '…', true ),
			Ui::badge( $type, 'neutral' ) . ( ! empty( $f['is_primary'] ) ? ' ' . Ui::badge( 'Principal', 'info' ) : '' ),
			Ui::badge( $m[0], $m[1], true ),
			Ui::text( $this->bytes( (int) $f['size_bytes'] ), false, true ),
			Ui::text( (string) $f['provider'], false, true ),
			Ui::text( substr( (string) $f['created_at'], 0, 10 ), false, true ),
			$ref,
		);
	}

	private function bytes( int $b ): string {
		if ( $b <= 0 ) {
			return '0 o';
		}
		$units = array( 'o', 'Ko', 'Mo', 'Go', 'To' );
		$i     = (int) floor( log( $b, 1024 ) );
		$i     = min( $i, count( $units ) - 1 );
		return number_format_i18n( $b / ( 1024 ** $i ), $i > 1 ? 1 : 0 ) . ' ' . $units[ $i ];
	}
}
