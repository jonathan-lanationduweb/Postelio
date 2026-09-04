<?php
/**
 * Savoir-faire : liste par statut (dont contenus masqués) et détail avec aperçu public. Lecture via
 * `Skills\Api\SkillAdminDirectory`. Le masquage / la restauration passent par
 * `Skills\Api\SkillModeration` : le statut éditorial (`pst_status`) n'est jamais écrit d'ici.
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

final class SkillsScreen extends ListScreen {

	private const DIR = '\\Postelio\\Skills\\Api\\SkillAdminDirectory';

	/** @var array<string,array{0:string,1:string}> */
	private const STATUSES = array(
		'published' => array( 'Publié', 'success' ),
		'draft'     => array( 'Brouillon', 'neutral' ),
		'archived'  => array( 'Archivé', 'neutral' ),
		'hidden'    => array( 'Masqué', 'error' ),
	);

	protected function capability(): string {
		return Menu::CAP_ADMIN;
	}

	protected function slug(): string {
		return 'postelio-skills';
	}

	protected function index(): string {
		if ( ! Data::has( self::DIR ) ) {
			return $this->module_missing( 'Savoir-faire', 'Savoir-faire' );
		}
		$tab    = $this->current( 'tab', 'all' );
		$q      = $this->current( 's' );
		$cat    = $this->current( 'category' );
		$author = $this->current( 'author_type' );
		$counts = (array) call_user_func( array( self::DIR, 'counts' ) );

		$filters = array( 'q' => $q, 'category' => $cat, 'author_type' => $author );
		if ( 'hidden' === $tab ) {
			$filters['hidden'] = true;
		} elseif ( 'all' !== $tab ) {
			$filters['status'] = $tab;
		}
		$res = (array) call_user_func( array( self::DIR, 'list' ), $filters, $this->paged(), static::PER_PAGE );

		$keep = array( 'tab' => $tab, 's' => $q, 'category' => $cat, 'author_type' => $author );

		$out  = Ui::page_header( 'Savoir-faire', 'Contenus éditoriaux publiés par les candidats et les entreprises.' );
		$out .= $this->status_tabs( array_map( static fn( $m ) => $m[0], self::STATUSES ), $counts, $tab, 'Tous' );
		$out .= Ui::filters(
			array( 'page' => $this->slug(), 'tab' => $tab ),
			Ui::search_input( 's', $q, 'Titre…' )
				. Ui::search_input( 'category', $cat, 'Catégorie…' )
				. Ui::select( 'author_type', array( '' => 'Tout auteur', 'candidate' => 'Candidat', 'company' => 'Entreprise' ), $author ),
			'Filtrer'
		);

		$rows = array();
		foreach ( (array) $res['items'] as $s ) {
			$rows[] = $this->row( (array) $s );
		}
		$out .= Ui::table( array( 'Contenu', 'Auteur', 'Catégorie', 'Statut', 'Commentaires', 'Actions' ), $rows, 'Aucun contenu ne correspond.' );
		$out .= $this->pagination( (int) $res['total'], $keep );
		return $out;
	}

	/** @param array<string,mixed> $s @return array<int,string> */
	private function row( array $s ): array {
		$hidden = ! empty( $s['mod_hidden'] );
		$status = $hidden ? 'hidden' : (string) $s['status'];
		$meta   = self::STATUSES[ $status ] ?? array( ucfirst( $status ), 'neutral' );
		return array(
			Ui::entity( (string) $s['title'], Fmt::or_dash( $s['author_name'] ?? '' ), (string) ( $s['image_url'] ?? '' ), true ),
			Ui::badge( 'company' === ( $s['author_type'] ?? '' ) ? 'Entreprise' : 'Candidat', 'company' === ( $s['author_type'] ?? '' ) ? 'info' : 'neutral' ),
			Ui::text( Fmt::or_dash( $s['category'] ?? '' ), false, true ),
			Ui::badge( $meta[0], $meta[1], true ),
			Ui::text( (string) (int) ( $s['comments'] ?? 0 ), false, true ),
			$this->actions( (string) $s['uuid'], $hidden, true ),
		);
	}

	private function actions( string $uuid, bool $hidden, bool $with_view ): string {
		$h = '<div class="bo-actions">';
		if ( $with_view ) {
			$h .= $this->view_link( $uuid );
		}
		if ( current_user_can( 'pst_moderate_content' ) && Data::has( '\\Postelio\\Skills\\Api\\SkillModeration' ) ) {
			$h .= $hidden
				? Ui::action_button( 'pst_admin_skill_unhide', array( 'uuid' => $uuid ), 'Restaurer', 'primary' )
				: Ui::action_button( 'pst_admin_skill_hide', array( 'uuid' => $uuid ), 'Masquer', 'danger', 'Masquer ce contenu du public ?' );
		}
		return $h . '</div>';
	}

	protected function detail( string $uuid ): string {
		if ( ! Data::has( self::DIR ) ) {
			return $this->module_missing( 'Savoir-faire', 'Savoir-faire' );
		}
		$s = call_user_func( array( self::DIR, 'detail' ), $uuid );
		if ( ! is_array( $s ) ) {
			return $this->not_found( 'Savoir-faire', 'Ce contenu n\'existe pas.' );
		}
		$hidden = ! empty( $s['moderation_hidden'] );
		$status = $hidden ? 'hidden' : (string) ( $s['status'] ?? '' );
		$meta   = self::STATUSES[ $status ] ?? array( ucfirst( $status ), 'neutral' );
		$seo    = is_array( $s['seo'] ?? null ) ? $s['seo'] : array();

		$out  = Ui::page_header( (string) $s['title'], Fmt::or_dash( $s['author_name'] ?? '' ), Ui::badge( $meta[0], $meta[1], true ) . $this->back_link() . $this->actions( $uuid, $hidden, false ), 'Postelio · Savoir-faire' );
		$out .= Ui::cols_open() . Ui::col_open();

		$out .= Ui::card_open( 'Contenu' ) . Ui::kv( array(
			'Résumé'        => Ui::text( Fmt::or_dash( $s['summary'] ?? '' ) ),
			'Catégorie'     => Ui::text( Fmt::or_dash( $s['category'] ?? '' ) ),
			'Mots-clés'     => Ui::text( Fmt::or_dash( implode( ', ', (array) ( $s['tags'] ?? array() ) ) ) ),
			'Auteur'        => Ui::text( Fmt::or_dash( $s['author_name'] ?? '' ) . ( 'company' === ( $s['author_type'] ?? '' ) ? ' (entreprise)' : ' (candidat)' ) ),
			'Commentaires'  => Ui::text( (string) (int) ( $s['comments'] ?? 0 ) ),
		) );
		if ( $hidden ) {
			$out .= Ui::alert( 'Ce contenu est actuellement masqué du public par la modération.', 'warning' );
		}
		$out .= Ui::details( 'Détails techniques', Ui::kv( array(
			'Révision'           => Ui::text( (string) (int) ( $s['revision'] ?? 0 ) ),
			'Référence publique' => Ui::text( $uuid, false, true ),
		) ) ) . Ui::card_close();

		$out .= Ui::card_open( 'Référencement' ) . Ui::kv( array(
			'Adresse publique' => Ui::text( Fmt::or_dash( $seo['slug'] ?? '' ) ),
			'Indexation'       => Ui::badge( ! empty( $seo['noindex'] ) ? 'Exclu des moteurs' : 'Indexable', ! empty( $seo['noindex'] ) ? 'warning' : 'success' ),
		) ) . Ui::card_close();

		$out .= Ui::col_close() . Ui::col_open();
		$out .= Ui::card_open( 'Aperçu public' );
		$out .= '<div class="bo-cardpreview">';
		if ( ! empty( $s['image_url'] ) ) {
			$out .= '<img class="bo-cardpreview__img" src="' . esc_url( (string) $s['image_url'] ) . '" alt="">';
		}
		$out .= '<h3 class="bo-cardpreview__title">' . esc_html( (string) $s['title'] ) . '</h3>';
		$out .= Ui::excerpt( Fmt::excerpt( (string) ( $s['content'] ?? '' ), 300 ) );
		$out .= '</div>' . Ui::card_close();
		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
	}
}
