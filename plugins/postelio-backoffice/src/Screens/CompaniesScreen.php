<?php
/**
 * Entreprises : liste compacte (logo, nom, ville, vérification, SIREN) et fiche (identité,
 * informations légales renseignées, présentation publique, membres ; statut / vérification /
 * actions en colonne latérale). Une information absente n'est jamais affichée sous forme de « — ».
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

	/** Libellés des méthodes de vérification (jamais la clé technique à l'écran). */
	private const METHODS = array(
		'manual'   => 'Vérification manuelle',
		'admin'    => 'Vérification manuelle',
		'sirene'   => 'Registre SIRENE',
		'insee'    => 'Registre SIRENE',
		'declared' => 'Déclaration de l\'entreprise',
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
		if ( empty( $rows ) ) {
			$out .= 'all' === $tab && '' === $q
				? Ui::empty_state( 'Aucune entreprise pour le moment', 'Les entreprises qui s\'inscrivent apparaîtront ici avec leur état de vérification.', '', 'build', true )
				: Ui::empty_state( 'Aucune entreprise ne correspond', '' !== $q ? 'Essayez un autre nom.' : 'Aucune entreprise dans cet état.', '', 'build', true );
		} else {
			$out .= Ui::table( array( 'Entreprise', 'Vérification', 'SIREN', '' ), $rows );
		}
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
			Ui::text( '' !== (string) ( $c['siren'] ?? '' ) ? (string) $c['siren'] : 'Non renseigné', false, true ),
			$this->actions( (string) $c['uuid'], $status, true ),
		);
	}

	/** Liste : « Voir » + menu ⋯ ; détail : pile d'actions. */
	private function actions( string $uuid, string $status, bool $in_list ): string {
		$has_mod   = Data::has( '\\Postelio\\Companies\\Api\\CompanyModeration' );
		$has_verif = Data::has( '\\Postelio\\Companies\\Verification\\VerificationService' );
		$items     = array();

		if ( 'suspended' === $status && $has_mod && current_user_can( 'pst_suspend_company' ) ) {
			$items[] = Ui::action_button( 'pst_admin_company_unsuspend', array( 'uuid' => $uuid ), 'Réactiver l\'entreprise', $in_list ? '' : 'primary' );
		} elseif ( 'verified' === $status && $has_mod && current_user_can( 'pst_suspend_company' ) ) {
			$items[] = Ui::action_button( 'pst_admin_company_suspend', array( 'uuid' => $uuid ), 'Suspendre l\'entreprise', 'danger', 'Suspendre cette entreprise ? Ses offres actives seront retirées de la diffusion.' );
		} elseif ( $has_verif && current_user_can( 'pst_verify_company' ) && in_array( $status, array( 'unverified', 'pending', 'manual_review', 'rejected' ), true ) ) {
			$items[] = Ui::action_button( 'pst_admin_company_verify', array( 'uuid' => $uuid ), 'Vérifier l\'entreprise', $in_list ? '' : 'primary' );
			$items[] = Ui::action_button( 'pst_admin_company_reject', array( 'uuid' => $uuid ), 'Rejeter la vérification', 'danger', 'Rejeter la vérification de cette entreprise ?' );
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
		$verified     = is_array( $c['legal_verified'] ?? null ) && ! empty( $c['legal_verified'] );
		$legal        = $verified ? (array) $c['legal_verified'] : (array) ( $c['legal_declared'] ?? array() );
		$editorial    = is_array( $c['editorial'] ?? null ) ? $c['editorial'] : array();
		$logo         = (string) ( $editorial['logo_url'] ?? '' );
		$members      = (array) ( $c['members'] ?? array() );
		$ville        = (string) ( $legal['ville_siege'] ?? '' );
		$secteur      = (string) ( $editorial['secteur'] ?? ( $editorial['sector'] ?? '' ) );
		$subtitle     = implode( ' · ', array_filter( array( $secteur, $ville ) ) );

		$out  = $this->header( (string) $c['nom'], $subtitle, $this->back_link(), 'Postelio · Entreprise' );
		$out .= Ui::cols_open() . Ui::col_open();

		// --- Identité -----------------------------------------------------------
		$out .= Ui::card_open( 'Identité' );
		$out .= Ui::identity( (string) $c['nom'], $subtitle, $logo, true, Ui::badge( $meta[0], $meta[1], true ) . ( $members ? Ui::badge( count( $members ) . ( count( $members ) > 1 ? ' membres' : ' membre' ), 'neutral' ) : '' ) );
		$identity = Ui::kv_present( array(
			'Site web' => (string) ( $editorial['site_web'] ?? ( $editorial['website'] ?? '' ) ),
			'Taille'   => (string) ( $editorial['taille'] ?? ( $editorial['effectif'] ?? '' ) ),
			'Secteur'  => $secteur,
		) );
		if ( '' !== $identity ) {
			$out .= '<div class="bo-section">' . $identity . '</div>';
		}
		$out .= Ui::card_close();

		// --- Informations légales : uniquement les champs renseignés ------------
		$labels = array(
			'raison_sociale' => 'Raison sociale', 'forme_juridique' => 'Forme juridique', 'siren' => 'SIREN',
			'siret' => 'SIRET', 'tva' => 'TVA', 'naf_ape' => 'NAF / APE', 'adresse_siege' => 'Adresse',
			'cp_siege' => 'Code postal', 'ville_siege' => 'Ville', 'pays' => 'Pays',
		);
		$pairs = array();
		foreach ( $labels as $k => $label ) {
			$pairs[ $label ] = (string) ( $legal[ $k ] ?? '' );
		}
		$legal_html = Ui::kv_present( $pairs );
		$out       .= Ui::card_open( 'Informations légales', $verified ? 'Données vérifiées.' : ( '' !== $legal_html ? 'Données déclarées par l\'entreprise.' : '' ) );
		$out       .= '' !== $legal_html ? $legal_html : Ui::empty_state( 'Informations légales à compléter', 'L\'entreprise n\'a pas encore renseigné ses informations légales.', '', 'file' );
		$out       .= Ui::card_close();

		// --- Informations publiques ------------------------------------------------
		$desc = trim( (string) ( $c['description'] ?? '' ) );
		$out .= Ui::card_open( 'Présentation publique', 'Ce que voient les candidats.' );
		if ( '' !== $desc ) {
			$out .= '<div class="bo-cardpreview">' . Ui::entity( (string) $c['nom'], $ville, $logo, true )
				. ( 'verified' === $status ? '<p class="bo-cardpreview__badges">' . Ui::badge( 'Entreprise vérifiée', 'success', true ) . '</p>' : '' )
				. Ui::excerpt( Fmt::excerpt( $desc, 600 ) ) . '</div>';
		} else {
			$out .= Ui::empty_state( 'Aucune présentation publiée', 'L\'entreprise n\'a pas encore rédigé sa présentation.', '', 'build' );
		}
		$out .= Ui::card_close();

		// --- Membres ---------------------------------------------------------------
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

		// --- Colonne latérale : statut, vérification, actions --------------------
		$method = (string) ( $verification['provider'] ?? ( $verification['method'] ?? '' ) );
		$out   .= Ui::card_open( 'Statut', '', '', 'bo-card--aside' );
		$out   .= Ui::kv_present( array(
			'Vérification'  => Ui::html( Ui::badge( $meta[0], $meta[1], true ) ),
			'Méthode'       => '' !== $method ? ( self::METHODS[ $method ] ?? ucfirst( str_replace( '_', ' ', $method ) ) ) : '',
			'Vérifiée le'   => '' !== (string) ( $verification['verified_at'] ?? '' ) ? Fmt::date( $verification['verified_at'] ) : '',
			'Motif interne' => current_user_can( 'pst_verify_company' ) ? (string) ( $verification['motif'] ?? '' ) : '',
		), true );
		if ( ! current_user_can( 'pst_verify_company' ) && ! empty( $verification['motif'] ) ) {
			$out .= Ui::protected_notice( 'Le motif interne de vérification est réservé aux profils habilités.' );
		}
		$stack = $this->actions( $uuid, $status, false );
		if ( '' !== $stack ) {
			$out .= '<div class="bo-section">' . Ui::action_stack( $stack ) . '</div>';
		}
		$out .= Ui::details( 'Détails techniques', Ui::kv( array( 'Référence' => Ui::text( $uuid, false, true ) ), true ) );
		$out .= Ui::card_close();

		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
	}
}
