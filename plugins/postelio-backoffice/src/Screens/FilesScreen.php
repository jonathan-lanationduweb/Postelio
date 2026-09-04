<?php
/**
 * CV & fichiers : supervision TECHNIQUE du stockage privé. Ce n'est PAS une bibliothèque de CV
 * navigable : compteurs par statut / provider / quarantaine + liste de MÉTADONNÉES (référence
 * courte, type, statut, taille, date, référencé par une candidature). Jamais de chemin disque, de
 * clé de stockage, de nom de fichier d'origine, de contenu, ni de lien de téléchargement — aucun
 * contrat admin ne l'autorise.
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

final class FilesScreen extends ListScreen {

	protected const PER_PAGE = 30;

	private const DIR = '\\Postelio\\Files\\Api\\FileAdminDirectory';

	/** @var array<string,array{0:string,1:string}> */
	private const STATUSES = array(
		'ready'       => array( 'Actif', 'success' ),
		'archived'    => array( 'Archivé', 'neutral' ),
		'quarantined' => array( 'En quarantaine', 'error' ),
		'deleted'     => array( 'Supprimé', 'neutral' ),
	);

	protected function capability(): string {
		return Menu::CAP_ADMIN;
	}

	protected function slug(): string {
		return 'postelio-files';
	}

	protected function index(): string {
		if ( ! Data::has( self::DIR ) ) {
			return $this->module_missing( 'CV & fichiers', 'Fichiers' );
		}
		$counts    = (array) call_user_func( array( self::DIR, 'counts' ) );
		$by_status = is_array( $counts['by_status'] ?? null ) ? $counts['by_status'] : array();
		$quarant   = (int) ( $counts['quarantined'] ?? 0 );

		$out  = Ui::page_header( 'CV & fichiers', 'Stockage privé : compteurs et métadonnées uniquement.' );
		$out .= '<div class="bo-stats">';
		$out .= Ui::stat( 'Fichiers', (int) ( $counts['total'] ?? 0 ) );
		$out .= Ui::stat( 'Actifs', (int) ( $by_status['ready'] ?? 0 ) );
		$out .= Ui::stat( 'Archivés', (int) ( $by_status['archived'] ?? 0 ) );
		$out .= Ui::stat( 'En quarantaine', $quarant, '', $quarant > 0 );
		$out .= Ui::stat( 'Supprimés', (int) ( $by_status['deleted'] ?? 0 ) );
		$out .= Ui::stat( 'Volume conservé', Fmt::bytes( (int) ( $counts['live_bytes'] ?? 0 ) ) );
		$out .= '</div>';

		if ( $quarant > 0 ) {
			$out .= Ui::alert( 'Des fichiers sont en quarantaine : jugés suspects à l\'analyse. Leur traitement relève du module Fichiers ; le back-office n\'agit jamais sur les fichiers.', 'warning' );
		}

		$tab     = $this->current( 'tab', 'all' );
		$filters = array();
		if ( 'all' !== $tab && isset( self::STATUSES[ $tab ] ) ) {
			$filters['status'] = $tab;
		}
		$res = (array) call_user_func( array( self::DIR, 'list' ), $filters, $this->paged(), static::PER_PAGE );

		$out .= $this->status_tabs(
			array_map( static fn( $m ) => $m[0], self::STATUSES ),
			array_merge( array( 'total' => (int) ( $counts['total'] ?? 0 ) ), $by_status ),
			$tab
		);

		// Référencement par une candidature : résolu en LOT (aucune requête par ligne).
		$refs  = array();
		$items = (array) $res['items'];
		if ( Data::has( '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory' ) ) {
			$uuids = array();
			foreach ( $items as $f ) {
				$uuids[] = (string) $f['uuid'];
			}
			$refs = (array) Data::facade( '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory', 'referenced_cv', array( $uuids ), array() );
		}

		$rows = array();
		foreach ( $items as $f ) {
			$f      = (array) $f;
			$rows[] = $this->row( $f, ! empty( $refs[ (string) $f['uuid'] ] ) );
		}
		$out .= Ui::table( array( 'Référence', 'Type', 'Statut', 'Taille', 'Ajouté', 'Rattaché à une candidature' ), $rows, 'Aucun fichier.' );
		$out .= $this->pagination( (int) $res['total'], array( 'tab' => $tab ) );

		$providers = is_array( $counts['by_provider'] ?? null ) ? $counts['by_provider'] : array();
		if ( ! empty( $providers ) ) {
			$chips = '';
			foreach ( $providers as $k => $n ) {
				$chips .= Ui::badge( (string) $k . ' · ' . (int) $n, 'neutral' ) . ' ';
			}
			$out .= Ui::details( 'Détails techniques', Ui::kv( array( 'Répartition par stockage' => '<span class="bo-chips">' . $chips . '</span>' ) ) );
		}
		$out .= Ui::help( 'Aucun chemin de stockage, nom de fichier d\'origine ni contenu n\'est affiché, et aucun téléchargement n\'est proposé : les fichiers restent privés à leur propriétaire et à l\'entreprise destinataire.' );
		return $out;
	}

	/** @param array<string,mixed> $f @return array<int,string> */
	private function row( array $f, bool $referenced ): array {
		$st   = (string) $f['status'];
		$meta = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		$type = 'cv' === (string) $f['type'] ? 'CV' : (string) $f['type'];
		return array(
			Ui::text( Fmt::ref( (string) $f['uuid'] ), true ),
			Ui::badge( $type, 'neutral' ) . ( ! empty( $f['is_primary'] ) ? ' ' . Ui::badge( 'Principal', 'info' ) : '' ),
			Ui::badge( $meta[0], $meta[1], true ),
			Ui::text( Fmt::bytes( (int) $f['size_bytes'] ), false, true ),
			Ui::text( Fmt::date( (string) $f['created_at'] ), false, true ),
			$referenced ? Ui::badge( 'Oui', 'success', true ) : Ui::text( 'Non', false, true ),
		);
	}
}
