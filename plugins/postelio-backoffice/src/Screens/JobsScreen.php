<?php
/**
 * Offres : liste par statut métier (titre + entreprise dans la même cellule, contrat, ville, source,
 * statut, expiration) et détail (contenu à gauche ; entreprise, statut, cycle de vie et actions à
 * droite). Les offres externes (partenaires) sont lues via `Jobs\Api\JobDirectory::external` et leur
 * masquage passe par `JobSources\Api\JobSourcesModeration`. Suspension / réactivation déléguées à
 * `Jobs\Api\JobModeration`.
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
		$out .= Ui::table( array( 'Offre', 'Contrat', 'Ville', 'Source', 'Statut', 'Candidatures', 'Expiration', '' ), $rows, 'Aucune offre ne correspond', '' !== $q ? 'Essayez un autre titre.' : '' );
		$out .= $this->pagination( (int) $res['total'], array( 'tab' => $tab, 's' => $q ) );
		return $out;
	}

	/** @param array<string,mixed> $j @return array<int,string> */
	private function row( array $j ): array {
		$status  = (string) $j['status'];
		$meta    = self::STATUSES[ $status ] ?? array( ucfirst( $status ), 'neutral' );
		$company = (string) ( $j['company']['nom'] ?? '' );
		$native  = 'postelio' === (string) $j['source'];
		return array(
			Ui::entity( (string) $j['title'], '' !== $company ? $company : '—', (string) ( $j['company']['logo_url'] ?? '' ), true ),
			Ui::text( Fmt::or_dash( $j['contrat'] ?? '' ), false, true ),
			Ui::text( Fmt::or_dash( $j['ville'] ?? '' ), false, true ),
			Ui::badge( $native ? 'Postelio' : 'Partenaire', $native ? 'info' : 'neutral' ),
			Ui::badge( $meta[0], $meta[1], true ),
			Ui::text( '—', false, true ),
			Ui::text( Fmt::date( $j['date_expiration'] ?? '' ), false, true ),
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

		$out  = $this->header( (string) $j['titre'], implode( ' · ', array_filter( array( $company, (string) ( $j['ville'] ?? '' ) ) ) ), $this->back_link(), 'Postelio · Offre' );
		$out .= Ui::cols_open() . Ui::col_open();

		// Colonne principale : contenu de l'offre.
		$out .= Ui::card_open( 'Contenu de l\'offre', '', Ui::badge( Fmt::or_dash( $j['contrat'] ?? '' ), 'neutral' ) . Ui::badge( Fmt::or_dash( $j['ville'] ?? '' ), 'neutral' ) );
		$desc = Fmt::excerpt( (string) ( $j['description'] ?? '' ), 1200 );
		$out .= '' !== $desc ? Ui::excerpt( $desc ) : Ui::help( 'Aucune description.' );
		$out .= '<div class="bo-section">' . Ui::kv( array(
			'Catégorie'    => Ui::text( Fmt::or_dash( $j['categorie'] ?? '' ) ),
			'Télétravail'  => Ui::text( Fmt::or_dash( $j['teletravail'] ?? '' ) ),
		) ) . '</div>';
		$out .= Ui::details( 'Détails techniques', Ui::kv( array(
			'Révision métier'    => Ui::text( (string) (int) ( $j['revision'] ?? 0 ) ),
			'Référence publique' => Ui::text( $uuid, false, true ),
		), true ) ) . Ui::card_close();

		$out .= Ui::col_close() . Ui::col_open();

		// Colonne latérale : entreprise, statut, cycle de vie, actions.
		$out .= Ui::card_open( 'Entreprise', '', '', 'bo-card--aside' ) . Ui::entity( Fmt::or_dash( $company ), 'Source : Postelio', $logo, true ) . Ui::card_close();

		$out .= Ui::card_open( 'Statut', '', '', 'bo-card--aside' ) . Ui::kv( array(
			'État'         => Ui::badge( $meta[0], $meta[1], true ),
			'Candidatures' => Ui::text( '—', false, true ),
		), true );
		$stack = $this->actions( $uuid, $status, false );
		if ( '' !== $stack ) {
			$out .= '<div class="bo-section">' . Ui::action_stack( $stack ) . '</div>';
		}
		$out .= Ui::card_close();

		$out .= Ui::card_open( 'Cycle de vie', '', '', 'bo-card--aside' ) . Ui::timeline( array(
			array( 'label' => 'Publication', 'time' => Fmt::date( $j['date_publication'] ?? '' ), 'done' => ! empty( $j['date_publication'] ) ),
			array( 'label' => 'Dernier renouvellement' . ( (int) ( $j['renewal_count'] ?? 0 ) > 0 ? ' (' . (int) $j['renewal_count'] . ')' : '' ), 'time' => Fmt::date( $j['renewed_at'] ?? '' ), 'done' => ! empty( $j['renewed_at'] ) ),
			array( 'label' => 'Expiration', 'time' => Fmt::date( $j['date_expiration'] ?? '' ), 'done' => in_array( $status, array( 'expired', 'archived' ), true ) ),
		) ) . Ui::card_close();

		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
	}

	/** @param array<string,mixed> $ext */
	private function external_detail( array $ext ): string {
		$pv     = is_array( $ext['public_view'] ?? null ) ? $ext['public_view'] : $ext;
		$hidden = 'hidden' === (string) ( $ext['local_visibility'] ?? 'visible' );
		$uuid   = (string) ( $pv['uuid'] ?? $ext['public_uuid'] ?? '' );
		$sync   = (string) ( $ext['sync_status'] ?? 'active' );
		$source = Fmt::or_dash( $ext['source_key'] ?? ( $pv['source']['key'] ?? '' ) );

		$out  = $this->header( Fmt::or_dash( $pv['title'] ?? 'Offre partenaire' ), 'Offre importée d\'un partenaire · synchronisée automatiquement', $this->back_link(), 'Postelio · Offre' );
		$out .= Ui::cols_open() . Ui::col_open();
		$out .= Ui::card_open( 'Contenu de l\'offre' ) . Ui::help( 'Les offres partenaires ne sont pas éditables : leur contenu est synchronisé depuis la source.' ) . Ui::card_close();
		$out .= Ui::col_close() . Ui::col_open();

		$out .= Ui::card_open( 'Source partenaire', '', '', 'bo-card--aside' ) . Ui::entity( $source, 'Connecteur', '', true )
			. '<div class="bo-section">' . Ui::kv( array(
				'Import'     => Ui::badge( 'active' === $sync ? 'À jour' : 'À vérifier', 'active' === $sync ? 'success' : 'warning', true ),
				'Visibilité' => Ui::badge( $hidden ? 'Masquée' : 'Visible', $hidden ? 'error' : 'success', true ),
			), true ) . '</div>' . Ui::card_close();

		if ( '' !== $uuid && current_user_can( 'pst_moderate_content' ) && Data::has( '\\Postelio\\JobSources\\Api\\JobSourcesModeration' ) ) {
			$out .= Ui::card_open( 'Modération', '', '', 'bo-card--aside' ) . Ui::action_stack(
				$hidden
					? Ui::action_button( 'pst_admin_extjob_unhide', array( 'uuid' => $uuid ), 'Restaurer', 'primary' )
					: Ui::action_button( 'pst_admin_extjob_hide', array( 'uuid' => $uuid ), 'Masquer du public', 'danger', 'Masquer cette offre partenaire du public ?' )
			) . Ui::card_close();
		}
		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
	}
}
