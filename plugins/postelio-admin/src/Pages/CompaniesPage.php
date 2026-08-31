<?php
/**
 * Entreprises : liste par statut de vérification + actions (vérifier / rejeter / revue /
 * suspendre / réactiver) DÉLÉGUÉES aux services Companies (VerificationService, CompanyModeration).
 * Aucune écriture directe de meta/CPT depuis le back-office.
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Contracts;
use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CompaniesPage extends Page {

	private const PER_PAGE = 20;

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_ADMIN;
	}

	protected function body(): string {
		if ( ! Contracts::has( '\\Postelio\\Companies\\Api\\CompanyAdminDirectory' ) ) {
			return Ui::header( 'Entreprises', 'Back-office Postelio' )
				. Ui::empty_state( 'Module indisponible', 'Le module Entreprises n\'est pas actif.', '🏢' );
		}
		$tab    = $this->current( 'tab', 'all' );
		$q      = $this->current( 's' );
		$paged  = $this->paged();
		$counts = \Postelio\Companies\Api\CompanyAdminDirectory::counts();

		$filters = array( 'q' => $q );
		if ( 'all' !== $tab ) {
			$filters['status'] = $tab;
		}
		$res = \Postelio\Companies\Api\CompanyAdminDirectory::list( $filters, $paged, self::PER_PAGE );

		$out  = Ui::header( 'Entreprises', 'Vérification et cycle de vie des entreprises' );
		$tabs = array( array( 'label' => 'Toutes', 'url' => $this->url( 'postelio-companies', array( 'tab' => 'all' ) ), 'count' => (int) ( $counts['total'] ?? 0 ), 'active' => 'all' === $tab ) );
		foreach ( array( 'verified' => 'Vérifiées', 'pending' => 'En attente', 'manual_review' => 'Revue', 'rejected' => 'Rejetées', 'suspended' => 'Suspendues' ) as $st => $lbl ) {
			$tabs[] = array( 'label' => $lbl, 'url' => $this->url( 'postelio-companies', array( 'tab' => $st ) ), 'count' => (int) ( $counts[ $st ] ?? 0 ), 'active' => $st === $tab );
		}
		$out .= Ui::tabs( $tabs );
		$out .= '<form method="get" class="pst-admin-filters"><input type="hidden" name="page" value="postelio-companies"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><input type="search" name="s" value="' . esc_attr( $q ) . '" placeholder="Nom d\'entreprise…"><button class="pst-btn pst-btn--sm pst-btn--primary" type="submit">Rechercher</button></form>';

		$rows = array();
		foreach ( $res['items'] as $c ) {
			$rows[] = $this->row( $c );
		}
		$out .= Ui::table( array( 'Entreprise', 'Statut', 'SIREN', 'Ville', 'Actions' ), $rows, 'Aucune entreprise.' );
		$out .= Ui::pager( $this->url( 'postelio-companies', array( 'tab' => $tab ) ), $paged, self::PER_PAGE, (int) $res['total'] );
		return $out;
	}

	/** @param array<string,mixed> $c @return array<int,string> */
	private function row( array $c ): array {
		$name   = Ui::text( (string) $c['nom'], true );
		$status = (string) $c['status'];
		$var    = array( 'verified' => 'success', 'suspended' => 'error', 'rejected' => 'error', 'pending' => 'info', 'manual_review' => 'warning', 'unverified' => 'neutral' );
		$lbl    = array( 'verified' => 'Vérifiée', 'suspended' => 'Suspendue', 'rejected' => 'Rejetée', 'pending' => 'En attente', 'manual_review' => 'Revue manuelle', 'unverified' => 'Non vérifiée' );
		$badge  = Ui::badge( $lbl[ $status ] ?? $status, $var[ $status ] ?? 'neutral', true );
		$siren  = Ui::text( '' !== (string) $c['siren'] ? (string) $c['siren'] : '—', false, true );
		$ville  = Ui::text( '' !== (string) $c['ville'] ? (string) $c['ville'] : '—', false, true );
		return array( $name, $badge, $siren, $ville, $this->actions( (string) $c['uuid'], $status ) );
	}

	private function actions( string $uuid, string $status ): string {
		$has_verif = Contracts::has( '\\Postelio\\Companies\\Verification\\VerificationService' );
		$has_mod   = Contracts::has( '\\Postelio\\Companies\\Api\\CompanyModeration' );
		$h = '<div class="pst-admin-actions">';
		if ( 'suspended' === $status && $has_mod ) {
			$h .= Ui::action_button( 'pst_admin_company_unsuspend', array( 'uuid' => $uuid ), 'Réactiver', 'primary' );
		} elseif ( 'verified' === $status && $has_mod ) {
			$h .= Ui::action_button( 'pst_admin_company_suspend', array( 'uuid' => $uuid ), 'Suspendre', 'danger', 'Suspendre cette entreprise ? Ses offres actives seront retirées de la diffusion.' );
		} elseif ( $has_verif && in_array( $status, array( 'unverified', 'pending', 'manual_review', 'rejected' ), true ) ) {
			$h .= Ui::action_button( 'pst_admin_company_verify', array( 'uuid' => $uuid ), 'Vérifier', 'primary' );
			$h .= Ui::action_button( 'pst_admin_company_reject', array( 'uuid' => $uuid ), 'Rejeter', 'danger', 'Rejeter la vérification de cette entreprise ?' );
		} else {
			$h .= Ui::text( '—', false, true );
		}
		return $h . '</div>';
	}
}
