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
		$view = $this->current( 'view' );
		if ( '' !== $view ) {
			return $this->detail( $view );
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

	private function actions( string $uuid, string $status, bool $with_view = true ): string {
		$has_verif = Contracts::has( '\\Postelio\\Companies\\Verification\\VerificationService' );
		$has_mod   = Contracts::has( '\\Postelio\\Companies\\Api\\CompanyModeration' );
		$h = '<div class="pst-admin-actions">';
		if ( $with_view ) {
			$h .= '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-companies', array( 'view' => $uuid ) ) ) . '">Voir</a>';
		}
		if ( 'suspended' === $status && $has_mod ) {
			$h .= Ui::action_button( 'pst_admin_company_unsuspend', array( 'uuid' => $uuid ), 'Réactiver', 'primary' );
		} elseif ( 'verified' === $status && $has_mod ) {
			$h .= Ui::action_button( 'pst_admin_company_suspend', array( 'uuid' => $uuid ), 'Suspendre', 'danger', 'Suspendre cette entreprise ? Ses offres actives seront retirées de la diffusion.' );
		} elseif ( $has_verif && in_array( $status, array( 'unverified', 'pending', 'manual_review', 'rejected' ), true ) ) {
			$h .= Ui::action_button( 'pst_admin_company_verify', array( 'uuid' => $uuid ), 'Vérifier', 'primary' );
			$h .= Ui::action_button( 'pst_admin_company_reject', array( 'uuid' => $uuid ), 'Rejeter', 'danger', 'Rejeter la vérification de cette entreprise ?' );
		} elseif ( ! $with_view ) {
			$h .= Ui::text( '—', false, true );
		}
		return $h . '</div>';
	}

	/** Détail entreprise : identité + légal + vérification + membres + aperçu public. */
	private function detail( string $uuid ): string {
		$c = \Postelio\Companies\Api\CompanyAdminDirectory::detail( $uuid );
		if ( null === $c ) {
			return Ui::header( 'Entreprise', 'Back-office Postelio' ) . Ui::empty_state( 'Introuvable', 'Cette entreprise n\'existe pas.', '🏢' );
		}
		$status = (string) ( $c['verification']['status'] ?? 'unverified' );
		$legal  = is_array( $c['legal_verified'] ?? null ) && $c['legal_verified'] ? $c['legal_verified'] : ( is_array( $c['legal_declared'] ?? null ) ? $c['legal_declared'] : array() );
		$edit   = is_array( $c['editorial'] ?? null ) ? $c['editorial'] : array();

		$back = '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-companies' ) ) . '">← Liste</a>';
		$out  = Ui::header( (string) $c['nom'], 'Fiche entreprise', $back . ' ' . $this->actions( $uuid, $status, false ) );
		$out .= '<div class="pst-admin-cols"><div>';

		// Légal
		$out .= Ui::card_open( 'Données légales' ) . '<dl class="pst-admin-kv">';
		foreach ( array( 'raison_sociale' => 'Raison sociale', 'forme_juridique' => 'Forme juridique', 'siren' => 'SIREN', 'siret' => 'SIRET', 'tva' => 'TVA', 'naf_ape' => 'NAF/APE', 'adresse_siege' => 'Adresse', 'cp_siege' => 'CP', 'ville_siege' => 'Ville', 'pays' => 'Pays' ) as $k => $lbl ) {
			$out .= '<dt>' . esc_html( $lbl ) . '</dt><dd>' . esc_html( '' !== (string) ( $legal[ $k ] ?? '' ) ? (string) $legal[ $k ] : '—' ) . '</dd>';
		}
		$out .= '</dl>' . Ui::card_close();

		// Vérification
		$var = array( 'verified' => 'success', 'suspended' => 'error', 'rejected' => 'error', 'pending' => 'info', 'manual_review' => 'warning', 'unverified' => 'neutral' );
		$out .= Ui::card_open( 'Vérification' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Statut</dt><dd>' . Ui::badge( $status, $var[ $status ] ?? 'neutral', true ) . '</dd>';
		$out .= '<dt>Provider</dt><dd>' . esc_html( (string) ( $c['verification']['provider'] ?? '—' ) ) . '</dd>';
		if ( current_user_can( 'pst_verify_company' ) && ! empty( $c['verification']['motif'] ) ) {
			$out .= '<dt>Motif interne</dt><dd>' . esc_html( (string) $c['verification']['motif'] ) . '</dd>';
		}
		$out .= '</dl>' . Ui::card_close();

		// Membres
		$rows = array();
		foreach ( (array) ( $c['members'] ?? array() ) as $m ) {
			$rows[] = array( Ui::text( '' !== (string) $m['name'] ? (string) $m['name'] : ( '#' . (int) $m['user_id'] ), true ), Ui::badge( 'owner' === $m['role'] ? 'Propriétaire' : 'Recruteur', 'owner' === $m['role'] ? 'info' : 'neutral' ) );
		}
		$out .= Ui::card_open( 'Membres' ) . Ui::table( array( 'Membre', 'Rôle' ), $rows, 'Aucun membre.' ) . Ui::card_close();
		$out .= '</div><div>';

		// Aperçu public
		$out .= Ui::card_open( 'Aperçu public' );
		$out .= '<div style="border:1px solid var(--pst-border);border-radius:12px;padding:16px;background:#fff">';
		$logo = (string) ( $edit['logo_url'] ?? '' );
		if ( '' !== $logo ) {
			$out .= '<img src="' . esc_url( $logo ) . '" alt="" style="height:44px;border-radius:8px;margin-bottom:8px">';
		}
		$out .= '<div style="font-weight:800;color:var(--pst-primary);font-size:16px">' . esc_html( (string) $c['nom'] ) . ' ' . ( 'verified' === $status ? Ui::badge( 'Vérifiée', 'success' ) : '' ) . '</div>';
		$out .= '<div class="pst-admin-stat__sub">' . esc_html( (string) ( $legal['ville_siege'] ?? '' ) ) . '</div>';
		$desc = wp_strip_all_tags( (string) ( $c['description'] ?? '' ) );
		$out .= '<p style="margin:8px 0 0;color:var(--pst-text-soft);font-size:13px">' . esc_html( mb_substr( $desc, 0, 220 ) ) . '</p>';
		$out .= '</div>' . Ui::card_close();
		$out .= '</div></div>';
		return $out;
	}
}
