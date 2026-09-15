<?php
/**
 * Entreprises : liste par statut de vérification (ligne riche : logo, nom, ville, vérification,
 * SIREN) et fiche détaillée (identité, vérification, données légales, membres, aperçu public).
 * Toutes les décisions (vérifier / rejeter / suspendre / réactiver) sont DÉLÉGUÉES aux services du
 * module Companies. Le motif interne de vérification reste réservé à `pst_verify_company`.
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

final class CompaniesScreen extends ListScreen {

	private const DIR = '\\Postelio\\Companies\\Api\\CompanyAdminDirectory';

	/** @var array<string,array{0:string,1:string}> */
	private const STATUSES = array(
		'verified'      => array( 'Vérifiée', 'success' ),
		'pending'       => array( 'En attente', 'info' ),
		'manual_review' => array( 'À vérifier', 'warning' ),
		'unverified'    => array( 'Non vérifiée', 'neutral' ),
		'rejected'      => array( 'Rejetée', 'error' ),
		'suspended'     => array( 'Suspendue', 'error' ),
	);

	protected function capability(): string {
		return Menu::CAP_ADMIN;
	}

	protected function eyebrow(): string {
		return 'Postelio · Gestion';
	}

	protected function slug(): string {
		return 'postelio-companies';
	}

	protected function index(): string {
		if ( ! Data::has( self::DIR ) ) {
			return $this->module_missing( 'Entreprises', 'Entreprises' );
		}
		$tab    = $this->current( 'tab', 'all' );
		$q      = $this->current( 's' );
		$counts = (array) call_user_func( array( self::DIR, 'counts' ) );

		$filters = array( 'q' => $q );
		if ( 'all' !== $tab ) {
			$filters['status'] = $tab;
		}
		$res = (array) call_user_func( array( self::DIR, 'list' ), $filters, $this->paged(), static::PER_PAGE );

		$to_check = (int) ( $counts['pending'] ?? 0 ) + (int) ( $counts['manual_review'] ?? 0 );

		$actions = '';
		if ( $to_check > 0 && current_user_can( 'pst_verify_company' ) && 'pending' !== $tab && 'manual_review' !== $tab ) {
			$actions = Ui::button( $to_check . ( $to_check > 1 ? ' entreprises à vérifier' : ' entreprise à vérifier' ), $this->url( $this->slug(), array( 'tab' => 'pending' ) ), 'accent' );
		}
		$out  = $this->header( 'Entreprises', 'Vérification administrative et cycle de vie des entreprises.', $actions );
		$out .= $this->toolbar(
			$this->status_tabs(
				array( 'verified' => 'Vérifiées', 'pending' => 'En attente', 'manual_review' => 'À vérifier', 'rejected' => 'Rejetées', 'suspended' => 'Suspendues' ),
				$counts,
				$tab,
				'Toutes'
			),
			$this->search( $q, 'Nom d\'entreprise…', array( 'tab' => $tab ) )
		);

		$rows = array();
		foreach ( (array) $res['items'] as $c ) {
			$rows[] = $this->row( (array) $c );
		}
		$out .= Ui::table( array( 'Entreprise', 'Vérification', 'SIREN', 'Offres', 'Membres', '' ), $rows, 'Aucune entreprise ne correspond', '' !== $q ? 'Essayez un autre nom.' : '' );
		$out .= $this->pagination( (int) $res['total'], array( 'tab' => $tab, 's' => $q ) );
		return $out;
	}

	/** @param array<string,mixed> $c @return array<int,string> */
	private function row( array $c ): array {
		$status = (string) $c['status'];
		$meta   = self::STATUSES[ $status ] ?? array( ucfirst( $status ), 'neutral' );
		return array(
			Ui::entity( (string) $c['nom'], (string) ( $c['ville'] ?? '' ), (string) ( $c['logo_url'] ?? '' ), true ),
			Ui::badge( $meta[0], $meta[1], true ),
			Ui::text( Fmt::or_dash( $c['siren'] ?? '' ), false, true ),
			Ui::text( '—', false, true ),
			Ui::text( '—', false, true ),
			$this->actions( (string) $c['uuid'], $status, true ),
		);
	}

	/** Liste : « Voir » + menu ⋯ ; détail : boutons en en-tête. */
	private function actions( string $uuid, string $status, bool $in_list ): string {
		$has_mod   = Data::has( '\\Postelio\\Companies\\Api\\CompanyModeration' );
		$has_verif = Data::has( '\\Postelio\\Companies\\Verification\\VerificationService' );
		$items     = array();

		if ( 'suspended' === $status && $has_mod && current_user_can( 'pst_suspend_company' ) ) {
			$items[] = Ui::action_button( 'pst_admin_company_unsuspend', array( 'uuid' => $uuid ), 'Réactiver', $in_list ? '' : 'primary' );
		} elseif ( 'verified' === $status && $has_mod && current_user_can( 'pst_suspend_company' ) ) {
			$items[] = Ui::action_button( 'pst_admin_company_suspend', array( 'uuid' => $uuid ), 'Suspendre', 'danger', 'Suspendre cette entreprise ? Ses offres actives seront retirées de la diffusion.' );
		} elseif ( $has_verif && current_user_can( 'pst_verify_company' ) && in_array( $status, array( 'unverified', 'pending', 'manual_review', 'rejected' ), true ) ) {
			$items[] = Ui::action_button( 'pst_admin_company_verify', array( 'uuid' => $uuid ), 'Vérifier', $in_list ? '' : 'primary' );
			$items[] = Ui::action_button( 'pst_admin_company_reject', array( 'uuid' => $uuid ), 'Rejeter', 'danger', 'Rejeter la vérification de cette entreprise ?' );
		}
		if ( $in_list ) {
			return '<div class="bo-actions">' . $this->view_link( $uuid ) . Ui::menu( $items ) . '</div>';
		}
		return implode( '', $items );
	}

	protected function detail( string $uuid ): string {
		if ( ! Data::has( self::DIR ) ) {
			return $this->module_missing( 'Entreprises', 'Entreprises' );
		}
		$c = call_user_func( array( self::DIR, 'detail' ), $uuid );
		if ( ! is_array( $c ) ) {
			return $this->not_found( 'Entreprise', 'Cette entreprise n\'existe pas.' );
		}
		$verification = is_array( $c['verification'] ?? null ) ? $c['verification'] : array();
		$status       = (string) ( $verification['status'] ?? 'unverified' );
		$meta         = self::STATUSES[ $status ] ?? array( ucfirst( $status ), 'neutral' );
		$legal        = is_array( $c['legal_verified'] ?? null ) && ! empty( $c['legal_verified'] )
			? (array) $c['legal_verified']
			: (array) ( $c['legal_declared'] ?? array() );
		$editorial    = is_array( $c['editorial'] ?? null ) ? $c['editorial'] : array();
		$logo         = (string) ( $editorial['logo_url'] ?? '' );
		$members      = (array) ( $c['members'] ?? array() );

		$out  = $this->header( (string) $c['nom'], implode( ' · ', array_filter( array( (string) ( $legal['forme_juridique'] ?? '' ), (string) ( $legal['ville_siege'] ?? '' ) ) ) ), $this->back_link() . $this->actions( $uuid, $status, false ), 'Postelio · Entreprise' );
		$out .= Ui::cols_open() . Ui::col_open();

		// Fiche : identité + vérification.
		$out .= Ui::card_open( 'Fiche entreprise' );
		$out .= Ui::identity( (string) $c['nom'], (string) ( $legal['ville_siege'] ?? '' ), $logo, true, Ui::badge( $meta[0], $meta[1], true ) . Ui::badge( count( $members ) . ( count( $members ) > 1 ? ' membres' : ' membre' ), 'neutral' ) );
		$pairs = array( 'Méthode de vérification' => Ui::text( Fmt::or_dash( $verification['provider'] ?? '' ) ) );
		if ( current_user_can( 'pst_verify_company' ) && ! empty( $verification['motif'] ) ) {
			$pairs['Motif interne'] = Ui::text( (string) $verification['motif'] );
		}
		$out .= '<div class="bo-section">' . Ui::kv( $pairs ) . '</div>';
		if ( ! current_user_can( 'pst_verify_company' ) ) {
			$out .= Ui::protected_notice( 'Le motif interne de vérification est réservé aux profils habilités.' );
		}
		$out .= Ui::card_close();

		$labels = array(
			'raison_sociale' => 'Raison sociale', 'forme_juridique' => 'Forme juridique', 'siren' => 'SIREN',
			'siret' => 'SIRET', 'tva' => 'TVA', 'naf_ape' => 'NAF / APE', 'adresse_siege' => 'Adresse',
			'cp_siege' => 'Code postal', 'ville_siege' => 'Ville', 'pays' => 'Pays',
		);
		$pairs = array();
		foreach ( $labels as $k => $label ) {
			$pairs[ $label ] = Ui::text( Fmt::or_dash( $legal[ $k ] ?? '' ) );
		}
		$out .= Ui::card_open( 'Données légales', ! empty( $c['legal_verified'] ) ? 'Données vérifiées.' : 'Données déclarées par l\'entreprise.' ) . Ui::kv( $pairs ) . Ui::card_close();

		$rows = array();
		foreach ( $members as $m ) {
			$m      = (array) $m;
			$name   = trim( (string) ( $m['name'] ?? '' ) );
			$owner  = 'owner' === ( $m['role'] ?? '' );
			$rows[] = array(
				Ui::entity( '' !== $name ? $name : 'Membre', $owner ? 'Propriétaire du compte' : 'Recruteur' ),
				Ui::badge( $owner ? 'Propriétaire' : 'Recruteur', $owner ? 'info' : 'neutral' ),
			);
		}
		$out .= Ui::card_open( 'Membres', '', '', 'bo-card--flush' ) . Ui::table( array( 'Membre', 'Rôle' ), $rows, 'Aucun membre rattaché.' ) . Ui::card_close();
		$out .= Ui::col_close() . Ui::col_open();

		$out .= Ui::card_open( 'Aperçu public', 'Ce que voient les candidats.', '', 'bo-card--aside' );
		$out .= '<div class="bo-cardpreview">';
		$out .= Ui::entity( (string) $c['nom'], (string) ( $legal['ville_siege'] ?? '' ), $logo, true );
		if ( 'verified' === $status ) {
			$out .= '<p class="bo-cardpreview__badges">' . Ui::badge( 'Entreprise vérifiée', 'success', true ) . '</p>';
		}
		$desc = Fmt::excerpt( (string) ( $c['description'] ?? '' ), 260 );
		$out .= '' !== $desc ? Ui::excerpt( $desc ) : Ui::help( 'Aucune présentation publiée.' );
		$out .= '</div>' . Ui::card_close();

		$out .= Ui::card_open( 'Activité', '', '', 'bo-card--aside' ) . Ui::kv( array(
			'Offres publiées' => Ui::text( '—', false, true ),
			'Candidatures'    => Ui::text( '—', false, true ),
		), true ) . Ui::help( '« — » : compteur non exposé par une façade de lecture.' ) . Ui::card_close();

		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
	}
}
