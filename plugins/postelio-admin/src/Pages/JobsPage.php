<?php
/**
 * Offres : liste admin par statut métier (plus lisible que le CPT WP brut), avec distinction
 * visuelle de la source. Actions (suspendre/réactiver) DÉLÉGUÉES à JobModeration. Phase 1 :
 * offres natives Postelio (le comptage des offres externes s'affiche via la page Sources).
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Contracts;
use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobsPage extends Page {

	private const PER_PAGE = 20;

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_ADMIN;
	}

	protected function body(): string {
		if ( ! Contracts::has( '\\Postelio\\Jobs\\Api\\JobAdminDirectory' ) ) {
			return Ui::header( 'Offres', 'Back-office Postelio' )
				. Ui::empty_state( 'Module indisponible', 'Le module Offres n\'est pas actif.', '📄' );
		}
		$tab    = $this->current( 'tab', 'all' );
		$q      = $this->current( 's' );
		$paged  = $this->paged();
		$counts = \Postelio\Jobs\Api\JobAdminDirectory::counts();

		$filters = array( 'q' => $q );
		if ( 'all' !== $tab ) {
			$filters['status'] = $tab;
		}
		$res = \Postelio\Jobs\Api\JobAdminDirectory::list( $filters, $paged, self::PER_PAGE );

		$out  = Ui::header( 'Offres', 'Cycle de vie des offres Postelio' );
		$tabs = array( array( 'label' => 'Toutes', 'url' => $this->url( 'postelio-jobs', array( 'tab' => 'all' ) ), 'count' => (int) ( $counts['total'] ?? 0 ), 'active' => 'all' === $tab ) );
		foreach ( array( 'draft' => 'Brouillons', 'published' => 'Publiées', 'expiring' => 'Expirent', 'expired' => 'Expirées', 'filled' => 'Pourvues', 'archived' => 'Archivées', 'suspended' => 'Suspendues' ) as $st => $lbl ) {
			$tabs[] = array( 'label' => $lbl, 'url' => $this->url( 'postelio-jobs', array( 'tab' => $st ) ), 'count' => (int) ( $counts[ $st ] ?? 0 ), 'active' => $st === $tab );
		}
		$out .= Ui::tabs( $tabs );
		$out .= '<form method="get" class="pst-admin-filters"><input type="hidden" name="page" value="postelio-jobs"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><input type="search" name="s" value="' . esc_attr( $q ) . '" placeholder="Titre de l\'offre…"><button class="pst-btn pst-btn--sm pst-btn--primary" type="submit">Rechercher</button></form>';

		$rows = array();
		foreach ( $res['items'] as $j ) {
			$rows[] = $this->row( $j );
		}
		$out .= Ui::table( array( 'Titre', 'Entreprise', 'Source', 'Contrat', 'Ville', 'Statut', 'Expiration', 'Actions' ), $rows, 'Aucune offre.' );
		$out .= Ui::pager( $this->url( 'postelio-jobs', array( 'tab' => $tab ) ), $paged, self::PER_PAGE, (int) $res['total'] );
		return $out;
	}

	/** @param array<string,mixed> $j @return array<int,string> */
	private function row( array $j ): array {
		$status = (string) $j['status'];
		$var    = array( 'published' => 'success', 'expiring' => 'warning', 'expired' => 'neutral', 'suspended' => 'error', 'filled' => 'info', 'archived' => 'neutral', 'draft' => 'neutral' );
		$badge  = Ui::badge( ucfirst( $status ), $var[ $status ] ?? 'neutral', true );
		$source = Ui::badge( 'postelio' === $j['source'] ? 'Postelio' : (string) $j['source'], 'postelio' === $j['source'] ? 'info' : 'warning' );
		$exp    = Ui::text( '' !== (string) $j['date_expiration'] ? (string) $j['date_expiration'] : '—', false, true );
		return array(
			Ui::text( (string) $j['title'], true ),
			Ui::text( '' !== (string) $j['company']['nom'] ? (string) $j['company']['nom'] : '—', false, true ),
			$source,
			Ui::text( '' !== (string) $j['contrat'] ? (string) $j['contrat'] : '—', false, true ),
			Ui::text( '' !== (string) $j['ville'] ? (string) $j['ville'] : '—', false, true ),
			$badge,
			$exp,
			$this->actions( (string) $j['uuid'], $status ),
		);
	}

	private function actions( string $uuid, string $status ): string {
		if ( ! Contracts::has( '\\Postelio\\Jobs\\Api\\JobModeration' ) || ! current_user_can( 'pst_manage_all_jobs' ) ) {
			return Ui::text( '—', false, true );
		}
		if ( 'suspended' === $status ) {
			return Ui::action_button( 'pst_admin_job_unsuspend', array( 'uuid' => $uuid ), 'Réactiver', 'primary' );
		}
		if ( in_array( $status, array( 'published', 'expiring' ), true ) ) {
			return Ui::action_button( 'pst_admin_job_suspend', array( 'uuid' => $uuid ), 'Suspendre', 'danger', 'Suspendre cette offre ? Elle ne sera plus visible publiquement.' );
		}
		return Ui::text( '—', false, true );
	}
}
