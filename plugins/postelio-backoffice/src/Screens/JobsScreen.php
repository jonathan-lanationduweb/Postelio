<?php
/**
 * Offres : liste par statut métier (titre + entreprise, contrat, localisation, source, statut, date)
 * et fiche d'offre : APERÇU DE L'ANNONCE tel que le candidat la voit (titre, entreprise,
 * localisation, contrat, résumé, description, missions / profil / avantages réellement renseignés)
 * en colonne principale ; statut, entreprise, source, dates utiles et actions en colonne latérale.
 * Aucune information absente n'est affichée sous forme de « — ». Les offres externes sont lues via
 * `Jobs\Api\JobDirectory::external` et leur masquage passe par `JobSources\Api\JobSourcesModeration`.
 * Suspension / réactivation déléguées à `Jobs\Api\JobModeration`.
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

final class JobsScreen extends ListScreen {

	private const DIR = '\\Postelio\\Jobs\\Api\\JobAdminDirectory';

	/** @var array<string,array{0:string,1:string}> */
	private const STATUSES = array(
		'draft'     => array( 'Brouillon', 'neutral' ),
		'published' => array( 'Publiée', 'success' ),
		'expiring'  => array( 'Expire bientôt', 'warning' ),
		'expired'   => array( 'Expirée', 'neutral' ),
		'filled'    => array( 'Pourvue', 'info' ),
		'archived'  => array( 'Archivée', 'neutral' ),
		'suspended' => array( 'Suspendue', 'error' ),
	);

	protected function capability(): string {
		return Menu::CAP_ADMIN;
	}

	protected function eyebrow(): string {
		return 'Postelio · Gestion';
	}

	protected function slug(): string {
		return 'postelio-jobs';
	}

	protected function index(): string {
		if ( ! Data::has( self::DIR ) ) {
			return $this->module_missing( 'Offres', 'Offres' );
		}
		$tab    = $this->current( 'tab', 'all' );
		$q      = $this->current( 's' );
		$counts = (array) call_user_func( array( self::DIR, 'counts' ) );

		$filters = array( 'q' => $q );
		if ( 'all' !== $tab ) {
			$filters['status'] = $tab;
		}
		$res = (array) call_user_func( array( self::DIR, 'list' ), $filters, $this->paged(), static::PER_PAGE );

		$out  = $this->header( 'Offres', 'Diffusion et cycle de vie des offres publiées sur Postelio.' );
		$out .= $this->toolbar(
			$this->status_tabs(
				array( 'published' => 'Publiées', 'expiring' => 'Expirent', 'draft' => 'Brouillons', 'expired' => 'Expirées', 'filled' => 'Pourvues', 'suspended' => 'Suspendues', 'archived' => 'Archivées' ),
				$counts,
				$tab,
				'Toutes'
			),
			$this->search( $q, 'Titre de l\'offre…', array( 'tab' => $tab ) )
		);

		$rows = array();
		foreach ( (array) $res['items'] as $j ) {
			$rows[] = $this->row( (array) $j );
		}
		$empty = 'all' === $tab && '' === $q
			? Ui::empty_state( 'Aucune offre pour le moment', 'Les offres publiées par les entreprises apparaîtront ici avec leur état de diffusion.', '', 'brief', true )
			: Ui::empty_state( 'Aucune offre ne correspond', '' !== $q ? 'Essayez un autre titre.' : 'Aucune offre dans cet état.', '', 'brief', true );
		$out  .= empty( $rows ) ? $empty : Ui::table( array( 'Offre', 'Contrat', 'Localisation', 'Source', 'Statut', 'Date', '' ), $rows );
		$out  .= $this->pagination( (int) $res['total'], array( 'tab' => $tab, 's' => $q ) );
		return $out;
	}

	/** @param array<string,mixed> $j @return array<int,string> */
	private function row( array $j ): array {
		$status  = (string) $j['status'];
		$meta    = self::STATUSES[ $status ] ?? array( ucfirst( $status ), 'neutral' );
		$company = (string) ( $j['company']['nom'] ?? '' );
		$native  = 'postelio' === (string) $j['source'];
		$pub     = (string) ( $j['date_publication'] ?? '' );
		$exp     = (string) ( $j['date_expiration'] ?? '' );
		$date    = '' !== $pub ? Ui::meta( 'Publiée le ' . Fmt::date( $pub ), '' !== $exp ? 'Expire le ' . Fmt::date( $exp ) : '' )
			: ( '' !== $exp ? Ui::meta( 'Expire le ' . Fmt::date( $exp ) ) : Ui::text( 'Non publiée', false, true ) );
		return array(
			Ui::entity( (string) $j['title'], $company, (string) ( $j['company']['logo_url'] ?? '' ), true ),
			Ui::text( '' !== (string) ( $j['contrat'] ?? '' ) ? (string) $j['contrat'] : 'Non précisé', false, true ),
			Ui::text( '' !== (string) ( $j['ville'] ?? '' ) ? (string) $j['ville'] : 'Non précisée', false, true ),
			Ui::badge( $native ? 'Postelio' : 'Partenaire', $native ? 'info' : 'neutral' ),
			Ui::badge( $meta[0], $meta[1], true ),
			$date,
			$this->actions( (string) $j['uuid'], $status, true ),
		);
	}

	/** Liste : « Voir » + menu ⋯ ; détail : pile d'actions. */
	private function actions( string $uuid, string $status, bool $in_list ): string {
		$items = array();
		if ( Data::has( '\\Postelio\\Jobs\\Api\\JobModeration' ) && current_user_can( 'pst_manage_all_jobs' ) ) {
			if ( 'suspended' === $status ) {
				$items[] = Ui::action_button( 'pst_admin_job_unsuspend', array( 'uuid' => $uuid ), 'Réactiver l\'offre', $in_list ? '' : 'primary' );
			} elseif ( in_array( $status, array( 'published', 'expiring' ), true ) ) {
				$items[] = Ui::action_button( 'pst_admin_job_suspend', array( 'uuid' => $uuid ), 'Suspendre l\'offre', 'danger', 'Suspendre cette offre ? Elle ne sera plus visible publiquement.' );
			}
		}
		if ( $in_list ) {
			return '<div class="bo-actions">' . $this->view_link( $uuid ) . Ui::menu( $items ) . '</div>';
		}
		return implode( '', $items );
	}

	protected function detail( string $uuid ): string {
		if ( ! Data::has( self::DIR ) ) {
			return $this->module_missing( 'Offres', 'Offres' );
		}
		$j = call_user_func( array( self::DIR, 'detail' ), $uuid );
		if ( ! is_array( $j ) ) {
			$ext = Data::facade( '\\Postelio\\Jobs\\Api\\JobDirectory', 'external', array( $uuid ), null );
			return is_array( $ext ) ? $this->external_detail( $ext ) : $this->not_found( 'Offre', 'Cette offre n\'existe pas.' );
		}
		$status  = (string) $j['status'];
		$meta    = self::STATUSES[ $status ] ?? array( ucfirst( $status ), 'neutral' );
		$company = (string) ( $j['company']['nom'] ?? '' );
		$logo    = (string) ( $j['company']['logo_url'] ?? '' );
		$d       = is_array( $j['detail'] ?? null ) ? $j['detail'] : array();
		$ville   = (string) ( $j['ville'] ?? '' );
		$contrat = (string) ( $j['contrat'] ?? '' );

		$out  = $this->header( (string) $j['titre'], implode( ' · ', array_filter( array( $company, $ville, $contrat ) ) ), $this->back_link(), 'Postelio · Offre' );
		$out .= Ui::cols_open() . Ui::col_open();

		// --- Colonne principale : l'annonce telle que le candidat la voit ------------
		$out .= Ui::card_open( 'Aperçu de l\'annonce', 'Ce que voient les candidats.', Ui::badge( $meta[0], $meta[1], true ) );
		$out .= '<h3 class="bo-preview__title">' . esc_html( (string) $j['titre'] ) . '</h3>';
		$facts = array_filter( array(
			'' !== $company ? '<b>' . esc_html( $company ) . '</b>' : '',
			'' !== $ville ? esc_html( trim( $ville . ( '' !== (string) ( $j['departement'] ?? '' ) ? ' (' . (string) $j['departement'] . ')' : '' ) ) ) : '',
			'' !== $contrat ? esc_html( $contrat ) : '',
			'' !== (string) ( $d['temps_travail'] ?? '' ) ? esc_html( (string) $d['temps_travail'] ) : '',
			'' !== (string) ( $j['teletravail'] ?? '' ) ? esc_html( 'Télétravail : ' . (string) $j['teletravail'] ) : '',
		) );
		if ( $facts ) {
			$out .= '<p class="bo-preview__meta"><span>' . implode( '</span><span>', $facts ) . '</span></p>';
		}
		$resume = trim( (string) ( $d['resume'] ?? '' ) );
		if ( '' !== $resume ) {
			$out .= Ui::excerpt( $resume );
		}
		$description = trim( (string) ( $j['description'] ?? '' ) );
		if ( '' !== $description && $description !== $resume ) {
			$out .= '<div class="bo-section">' . Ui::richtext( $description ) . '</div>';
		}
		$lists = array( 'missions' => 'Missions', 'profil' => 'Profil recherché', 'competences' => 'Compétences', 'avantages' => 'Avantages', 'processus' => 'Processus de recrutement' );
		foreach ( $lists as $key => $title ) {
			$items = is_array( $d[ $key ] ?? null ) ? $d[ $key ] : array();
			$html  = Ui::bullets( $items );
			if ( '' !== $html ) {
				$out .= Ui::section_open( $title ) . $html . Ui::section_close();
			}
		}
		$conditions = Ui::kv_present( array(
			'Salaire'           => (string) ( $d['salaire'] ?? '' ),
			'Salaire annuel'    => (int) ( $j['salaire_annuel'] ?? 0 ) > 0 ? number_format_i18n( (int) $j['salaire_annuel'] ) . ' €' : '',
			'Durée'             => (string) ( $d['duree'] ?? '' ),
			'Niveau d\'études'  => (string) ( $d['niveau_etude_label'] ?? ( $j['niveau_etude'] ?? '' ) ),
			'Expérience'        => (string) ( $d['experience_label'] ?? ( $j['experience'] ?? '' ) ),
			'Catégorie'         => (string) ( $d['categorie_label'] ?? ( $j['categorie'] ?? '' ) ),
		) );
		if ( '' !== $conditions ) {
			$out .= Ui::section_open( 'Conditions' ) . $conditions . Ui::section_close();
		}
		if ( '' === $description && '' === $resume && ! $facts ) {
			$out .= Ui::empty_state( 'Annonce encore vide', 'L\'entreprise n\'a pas encore rédigé le contenu de cette offre.', '', 'brief' );
		}
		$out .= Ui::card_close();

		$out .= Ui::col_close() . Ui::col_open();

		// --- Colonne latérale : statut, entreprise, source, dates, actions ---------
		$out .= Ui::card_open( 'Statut', '', '', 'bo-card--aside' );
		$out .= Ui::kv_present( array(
			'État'   => Ui::html( Ui::badge( $meta[0], $meta[1], true ) ),
			'Source' => Ui::html( Ui::badge( 'Postelio', 'info' ) ),
		), true );
		$stack = $this->actions( $uuid, $status, false );
		if ( '' !== $stack ) {
			$out .= '<div class="bo-section">' . Ui::action_stack( $stack ) . '</div>';
		}
		$out .= Ui::details( 'Détails techniques', Ui::kv( array(
			'Référence' => Ui::text( $uuid, false, true ),
			'Version'   => Ui::text( (string) (int) ( $j['revision'] ?? 0 ), false, true ),
		), true ) );
		$out .= Ui::card_close();

		if ( '' !== $company ) {
			$company_uuid = (string) ( $j['company']['uuid'] ?? '' );
			$out         .= Ui::card_open( 'Entreprise', '', '' !== $company_uuid ? Ui::button( 'Fiche', $this->url( 'postelio-companies', array( 'view' => $company_uuid ) ), 'ghost', true ) : '', 'bo-card--aside' )
				. Ui::entity( $company, $ville, $logo, true ) . Ui::card_close();
		}

		$dates = Ui::kv_present( array(
			'Publiée le'       => '' !== (string) ( $j['date_publication'] ?? '' ) ? Fmt::date( $j['date_publication'] ) : '',
			'Expire le'        => '' !== (string) ( $j['date_expiration'] ?? '' ) ? Fmt::date( $j['date_expiration'] ) : '',
			'Renouvelée le'    => '' !== (string) ( $j['renewed_at'] ?? '' ) ? Fmt::date( $j['renewed_at'] ) : '',
			'Renouvellements'  => (int) ( $j['renewal_count'] ?? 0 ) > 0 ? (string) (int) $j['renewal_count'] : '',
		), true );
		if ( '' !== $dates ) {
			$out .= Ui::card_open( 'Dates utiles', '', '', 'bo-card--aside' ) . $dates . Ui::card_close();
		}

		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
	}

	/** @param array<string,mixed> $ext */
	private function external_detail( array $ext ): string {
		$pv     = is_array( $ext['public_view'] ?? null ) ? $ext['public_view'] : $ext;
		$hidden = 'hidden' === (string) ( $ext['local_visibility'] ?? 'visible' );
		$uuid   = (string) ( $pv['uuid'] ?? $ext['public_uuid'] ?? '' );
		$sync   = (string) ( $ext['sync_status'] ?? 'active' );
		$labels = array( 'france_travail' => 'France Travail' );
		$key    = (string) ( $ext['source_key'] ?? ( $pv['source']['key'] ?? '' ) );
		$source = $labels[ $key ] ?? ( '' !== $key ? ucwords( str_replace( '_', ' ', $key ) ) : 'Partenaire' );
		$title  = (string) ( $pv['title'] ?? 'Offre partenaire' );

		$out  = $this->header( $title, 'Offre importée depuis ' . $source . ' · synchronisée automatiquement', $this->back_link(), 'Postelio · Offre' );
		$out .= Ui::cols_open() . Ui::col_open();
		$out .= Ui::card_open( 'Aperçu de l\'annonce', 'Ce que voient les candidats.', Ui::badge( $hidden ? 'Masquée' : 'Visible', $hidden ? 'error' : 'success', true ) );
		$out .= '<h3 class="bo-preview__title">' . esc_html( $title ) . '</h3>';
		$facts = array_filter( array(
			'' !== (string) ( $pv['company'] ?? '' ) ? '<b>' . esc_html( (string) $pv['company'] ) . '</b>' : '',
			'' !== (string) ( $pv['ville'] ?? ( $pv['location'] ?? '' ) ) ? esc_html( (string) ( $pv['ville'] ?? $pv['location'] ) ) : '',
			'' !== (string) ( $pv['contrat'] ?? ( $pv['contract'] ?? '' ) ) ? esc_html( (string) ( $pv['contrat'] ?? $pv['contract'] ) ) : '',
		) );
		if ( $facts ) {
			$out .= '<p class="bo-preview__meta"><span>' . implode( '</span><span>', $facts ) . '</span></p>';
		}
		$desc = trim( (string) ( $pv['description'] ?? ( $pv['excerpt'] ?? '' ) ) );
		$out .= '' !== $desc ? Ui::richtext( $desc ) : Ui::help( 'Le contenu de cette offre est publié sur le site du partenaire.' );
		$out .= Ui::help( 'Les offres partenaires ne sont pas éditables : leur contenu est synchronisé depuis la source.' );
		$out .= Ui::card_close();
		$out .= Ui::col_close() . Ui::col_open();

		$out .= Ui::card_open( 'Source', '', '', 'bo-card--aside' ) . Ui::entity( $source, 'Offre partenaire', '', true )
			. '<div class="bo-section">' . Ui::kv_present( array(
				'Import'     => Ui::html( Ui::badge( 'active' === $sync ? 'À jour' : 'À vérifier', 'active' === $sync ? 'success' : 'warning', true ) ),
				'Visibilité' => Ui::html( Ui::badge( $hidden ? 'Masquée' : 'Visible', $hidden ? 'error' : 'success', true ) ),
			), true ) . '</div>';
		if ( '' !== $uuid && current_user_can( 'pst_moderate_content' ) && Data::has( '\\Postelio\\JobSources\\Api\\JobSourcesModeration' ) ) {
			$out .= '<div class="bo-section">' . Ui::action_stack(
				$hidden
					? Ui::action_button( 'pst_admin_extjob_unhide', array( 'uuid' => $uuid ), 'Restaurer', 'primary' )
					: Ui::action_button( 'pst_admin_extjob_hide', array( 'uuid' => $uuid ), 'Masquer du public', 'danger', 'Masquer cette offre partenaire du public ?' )
			) . '</div>';
		}
		$out .= Ui::card_close();
		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
	}
}
