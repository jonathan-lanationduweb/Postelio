<?php
/**
 * Offres : liste par statut métier (avec distinction de la source) et détail. Les offres externes
 * (partenaires) sont lues via `Jobs\Api\JobDirectory::external` et leur masquage passe par
 * `JobSources\Api\JobSourcesModeration`. Suspension / réactivation déléguées à
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

		$out  = Ui::page_header( 'Offres', 'Cycle de vie des offres publiées sur Postelio.' );
		$out .= $this->status_tabs(
			array( 'published' => 'Publiées', 'expiring' => 'Expirent', 'draft' => 'Brouillons', 'expired' => 'Expirées', 'filled' => 'Pourvues', 'suspended' => 'Suspendues', 'archived' => 'Archivées' ),
			$counts,
			$tab,
			'Toutes'
		);
		$out .= Ui::filters( array( 'page' => $this->slug(), 'tab' => $tab ), Ui::search_input( 's', $q, 'Titre de l\'offre…' ), 'Rechercher' );

		$rows = array();
		foreach ( (array) $res['items'] as $j ) {
			$rows[] = $this->row( (array) $j );
		}
		$out .= Ui::table( array( 'Offre', 'Source', 'Contrat', 'Ville', 'Statut', 'Expiration', 'Actions' ), $rows, 'Aucune offre ne correspond.' );
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
			Ui::entity( (string) $j['title'], '' !== $company ? $company : '—', '', true ),
			Ui::badge( $native ? 'Postelio' : 'Partenaire', $native ? 'info' : 'neutral' ),
			Ui::text( Fmt::or_dash( $j['contrat'] ?? '' ), false, true ),
			Ui::text( Fmt::or_dash( $j['ville'] ?? '' ), false, true ),
			Ui::badge( $meta[0], $meta[1], true ),
			Ui::text( Fmt::or_dash( $j['date_expiration'] ?? '' ), false, true ),
			$this->actions( (string) $j['uuid'], $status, true ),
		);
	}

	private function actions( string $uuid, string $status, bool $with_view ): string {
		$h = '<div class="bo-actions">';
		if ( $with_view ) {
			$h .= $this->view_link( $uuid );
		}
		if ( Data::has( '\\Postelio\\Jobs\\Api\\JobModeration' ) && current_user_can( 'pst_manage_all_jobs' ) ) {
			if ( 'suspended' === $status ) {
				$h .= Ui::action_button( 'pst_admin_job_unsuspend', array( 'uuid' => $uuid ), 'Réactiver', 'primary' );
			} elseif ( in_array( $status, array( 'published', 'expiring' ), true ) ) {
				$h .= Ui::action_button( 'pst_admin_job_suspend', array( 'uuid' => $uuid ), 'Suspendre', 'danger', 'Suspendre cette offre ? Elle ne sera plus visible publiquement.' );
			}
		}
		return $h . '</div>';
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

		$out  = Ui::page_header( (string) $j['titre'], Fmt::or_dash( $company ), $this->back_link() . $this->actions( $uuid, $status, false ), 'Postelio · Offre' );
		$out .= Ui::cols_open() . Ui::col_open();

		$out .= Ui::card_open( 'Offre' ) . Ui::kv( array(
			'Entreprise'   => Ui::text( Fmt::or_dash( $company ), true ),
			'Statut'       => Ui::badge( $meta[0], $meta[1], true ),
			'Source'       => Ui::badge( 'Postelio', 'info' ),
			'Contrat'      => Ui::text( Fmt::or_dash( $j['contrat'] ?? '' ) ),
			'Ville'        => Ui::text( Fmt::or_dash( $j['ville'] ?? '' ) ),
			'Catégorie'    => Ui::text( Fmt::or_dash( $j['categorie'] ?? '' ) ),
			'Télétravail'  => Ui::text( Fmt::or_dash( $j['teletravail'] ?? '' ) ),
		) ) . Ui::card_close();

		$out .= Ui::card_open( 'Diffusion' ) . Ui::kv( array(
			'Publication'      => Ui::text( Fmt::or_dash( $j['date_publication'] ?? '' ) ),
			'Expiration'       => Ui::text( Fmt::or_dash( $j['date_expiration'] ?? '' ) ),
			'Renouvellements'  => Ui::text( (string) (int) ( $j['renewal_count'] ?? 0 ) ),
			'Dernier renouvellement' => Ui::text( Fmt::or_dash( $j['renewed_at'] ?? '' ) ),
		) ) . Ui::details( 'Détails techniques', Ui::kv( array(
			'Révision métier'   => Ui::text( (string) (int) ( $j['revision'] ?? 0 ) ),
			'Référence publique' => Ui::text( $uuid, false, true ),
		) ) ) . Ui::card_close();

		$out .= Ui::col_close() . Ui::col_open();
		$out .= Ui::card_open( 'Aperçu', 'Ce que voient les candidats.' );
		$out .= '<div class="bo-cardpreview">';
		$out .= '<h3 class="bo-cardpreview__title">' . esc_html( (string) $j['titre'] ) . '</h3>';
		$out .= '<p class="bo-cardpreview__meta">' . esc_html( trim( Fmt::or_dash( $company ) . ' · ' . Fmt::or_dash( $j['ville'] ?? '' ), ' ·' ) ) . '</p>';
		$out .= '<p class="bo-cardpreview__badges">' . Ui::badge( Fmt::or_dash( $j['contrat'] ?? '' ), 'neutral' ) . '</p>';
		$out .= Ui::excerpt( Fmt::excerpt( (string) ( $j['description'] ?? '' ), 300 ) );
		$out .= '</div>' . Ui::card_close();
		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
	}

	/** @param array<string,mixed> $ext */
	private function external_detail( array $ext ): string {
		$pv     = is_array( $ext['public_view'] ?? null ) ? $ext['public_view'] : $ext;
		$hidden = 'hidden' === (string) ( $ext['local_visibility'] ?? 'visible' );
		$uuid   = (string) ( $pv['uuid'] ?? $ext['public_uuid'] ?? '' );
		$sync   = (string) ( $ext['sync_status'] ?? 'active' );

		$out  = Ui::page_header( Fmt::or_dash( $pv['title'] ?? 'Offre partenaire' ), 'Offre importée d\'un partenaire', $this->back_link(), 'Postelio · Offre' );
		$out .= Ui::cols_open() . Ui::col_open();
		$out .= Ui::card_open( 'Source partenaire' ) . Ui::kv( array(
			'Partenaire'    => Ui::text( Fmt::or_dash( $ext['source_key'] ?? ( $pv['source']['key'] ?? '' ) ), true ),
			'Import'        => Ui::badge( 'active' === $sync ? 'À jour' : 'À vérifier', 'active' === $sync ? 'success' : 'warning', true ),
			'Visibilité'    => Ui::badge( $hidden ? 'Masquée' : 'Visible', $hidden ? 'error' : 'success', true ),
		) ) . Ui::help( 'Les offres partenaires ne sont pas éditables : elles sont synchronisées automatiquement.' ) . Ui::card_close();
		$out .= Ui::col_close() . Ui::col_open();

		if ( '' !== $uuid && current_user_can( 'pst_moderate_content' ) && Data::has( '\\Postelio\\JobSources\\Api\\JobSourcesModeration' ) ) {
			$out .= Ui::card_open( 'Modération' ) . Ui::action_stack(
				$hidden
					? Ui::action_button( 'pst_admin_extjob_unhide', array( 'uuid' => $uuid ), 'Restaurer', 'primary' )
					: Ui::action_button( 'pst_admin_extjob_hide', array( 'uuid' => $uuid ), 'Masquer du public', 'danger', 'Masquer cette offre partenaire du public ?' )
			) . Ui::card_close();
		}
		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
	}
}
