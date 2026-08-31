<?php
/**
 * Savoir-faire : liste par statut (+ masqués) et détail, via SkillAdminDirectory. Actions
 * hide/unhide DÉLÉGUÉES à SkillModeration. Ne modifie jamais `pst_status` directement.
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Contracts;
use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SkillsPage extends Page {

	private const PER_PAGE = 20;

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_ADMIN;
	}

	protected function body(): string {
		if ( ! Contracts::has( '\\Postelio\\Skills\\Api\\SkillAdminDirectory' ) ) {
			return Ui::header( 'Savoir-faire', 'Back-office Postelio' ) . Ui::empty_state( 'Module indisponible', 'Le module Savoir-faire n\'est pas actif.', '📝' );
		}
		$view = $this->current( 'view' );
		if ( '' !== $view ) {
			return $this->detail( $view );
		}
		$tab      = $this->current( 'tab', 'all' );
		$q        = $this->current( 's' );
		$cat      = $this->current( 'category' );
		$atype    = $this->current( 'author_type' );
		$paged    = $this->paged();
		$counts   = \Postelio\Skills\Api\SkillAdminDirectory::counts();

		$filters = array( 'q' => $q, 'category' => $cat, 'author_type' => $atype );
		if ( 'hidden' === $tab ) {
			$filters['hidden'] = true;
		} elseif ( 'all' !== $tab ) {
			$filters['status'] = $tab;
		}
		$res = \Postelio\Skills\Api\SkillAdminDirectory::list( $filters, $paged, self::PER_PAGE );

		$out  = Ui::header( 'Savoir-faire', 'Contenus éditoriaux publics (candidat / entreprise)' );
		$tabs = array( array( 'label' => 'Tous', 'url' => $this->url( 'postelio-skills', array( 'tab' => 'all' ) ), 'count' => (int) ( $counts['total'] ?? 0 ), 'active' => 'all' === $tab ) );
		foreach ( array( 'draft' => 'Brouillons', 'published' => 'Publiés', 'archived' => 'Archivés', 'hidden' => 'Masqués' ) as $st => $lbl ) {
			$tabs[] = array( 'label' => $lbl, 'url' => $this->url( 'postelio-skills', array( 'tab' => $st ) ), 'count' => (int) ( $counts[ $st ] ?? 0 ), 'active' => $st === $tab );
		}
		$out .= Ui::tabs( $tabs );
		$out .= '<form method="get" class="pst-admin-filters"><input type="hidden" name="page" value="postelio-skills"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">'
			. '<input type="search" name="s" value="' . esc_attr( $q ) . '" placeholder="Titre…">'
			. '<input type="search" name="category" value="' . esc_attr( $cat ) . '" placeholder="Catégorie (slug)">'
			. '<select name="author_type"><option value="">Tout auteur</option><option value="candidate"' . selected( $atype, 'candidate', false ) . '>Personnel</option><option value="company"' . selected( $atype, 'company', false ) . '>Entreprise</option></select>'
			. '<button class="pst-btn pst-btn--sm pst-btn--primary" type="submit">Filtrer</button></form>';

		$rows = array();
		foreach ( $res['items'] as $s ) {
			$rows[] = $this->row( (array) $s );
		}
		$out .= Ui::table( array( 'Titre', 'Auteur', 'Type', 'Catégorie', 'Statut', 'Comm.', 'Actions' ), $rows, 'Aucun savoir-faire.' );
		$out .= Ui::pager( $this->url( 'postelio-skills', array( 'tab' => $tab ) ), $paged, self::PER_PAGE, (int) $res['total'] );
		return $out;
	}

	/** @param array<string,mixed> $s @return array<int,string> */
	private function row( array $s ): array {
		$hidden = ! empty( $s['mod_hidden'] );
		$status = $hidden ? 'hidden' : (string) $s['status'];
		$var    = array( 'published' => 'success', 'draft' => 'neutral', 'archived' => 'neutral', 'hidden' => 'error' );
		return array(
			Ui::text( (string) $s['title'], true ),
			Ui::text( '' !== (string) $s['author_name'] ? (string) $s['author_name'] : '—', false, true ),
			Ui::badge( 'company' === $s['author_type'] ? 'Entreprise' : 'Personnel', 'company' === $s['author_type'] ? 'info' : 'neutral' ),
			Ui::text( '' !== (string) $s['category'] ? (string) $s['category'] : '—', false, true ),
			Ui::badge( ucfirst( $status ), $var[ $status ] ?? 'neutral', true ),
			Ui::text( (string) (int) $s['comments'], false, true ),
			$this->actions( (string) $s['uuid'], $hidden ),
		);
	}

	private function actions( string $uuid, bool $hidden ): string {
		$h = '<div class="pst-admin-actions"><a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-skills', array( 'view' => $uuid ) ) ) . '">Voir</a>';
		if ( current_user_can( 'pst_moderate_content' ) && Contracts::has( '\\Postelio\\Skills\\Api\\SkillModeration' ) ) {
			$h .= $hidden
				? Ui::action_button( 'pst_admin_skill_unhide', array( 'uuid' => $uuid ), 'Restaurer', 'primary' )
				: Ui::action_button( 'pst_admin_skill_hide', array( 'uuid' => $uuid ), 'Masquer', 'danger', 'Masquer ce contenu du public ?' );
		}
		return $h . '</div>';
	}

	private function detail( string $uuid ): string {
		$s = \Postelio\Skills\Api\SkillAdminDirectory::detail( $uuid );
		$back = '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-skills' ) ) . '">← Liste</a>';
		if ( null === $s ) {
			return Ui::header( 'Savoir-faire', 'Back-office Postelio', $back ) . Ui::empty_state( 'Introuvable', 'Contenu introuvable.', '📝' );
		}
		$hidden = ! empty( $s['moderation_hidden'] );
		$out  = Ui::header( (string) $s['title'], 'Fiche savoir-faire', $back . ' ' . $this->actions( $uuid, $hidden ) );
		$out .= '<div class="pst-admin-cols"><div>';

		$out .= Ui::card_open( 'Contenu' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Résumé</dt><dd>' . esc_html( (string) ( $s['summary'] ?? '—' ) ) . '</dd>';
		$out .= '<dt>Catégorie</dt><dd>' . esc_html( (string) ( $s['category'] ?? '—' ) ) . '</dd>';
		$out .= '<dt>Tags</dt><dd>' . esc_html( implode( ', ', (array) ( $s['tags'] ?? array() ) ) ) . '</dd>';
		$out .= '<dt>Auteur</dt><dd>' . esc_html( (string) ( $s['author_name'] ?? '—' ) ) . ' (' . esc_html( (string) ( $s['author_type'] ?? '' ) ) . ')</dd>';
		$out .= '<dt>Statut</dt><dd>' . Ui::badge( (string) ( $s['status'] ?? '' ), 'neutral', true ) . ( $hidden ? ' ' . Ui::badge( 'Masqué modération', 'error' ) : '' ) . '</dd>';
		$out .= '<dt>Révision</dt><dd>' . esc_html( (string) ( $s['revision'] ?? 0 ) ) . '</dd>';
		$out .= '<dt>Commentaires</dt><dd>' . esc_html( (string) (int) ( $s['comments'] ?? 0 ) ) . '</dd>';
		$out .= '</dl>' . Ui::card_close();

		// SEO
		$seo = is_array( $s['seo'] ?? null ) ? $s['seo'] : array();
		$out .= Ui::card_open( 'SEO (contrat)' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Slug</dt><dd>' . esc_html( (string) ( $seo['slug'] ?? '—' ) ) . '</dd>';
		$out .= '<dt>noindex</dt><dd>' . Ui::badge( ! empty( $seo['noindex'] ) ? 'oui' : 'non', ! empty( $seo['noindex'] ) ? 'warning' : 'success' ) . '</dd>';
		$out .= '</dl>' . Ui::card_close();
		$out .= '</div><div>';

		// Aperçu public
		$out .= Ui::card_open( 'Aperçu public' );
		$out .= '<div class="pst-preview">';
		if ( ! empty( $s['image_url'] ) ) {
			$out .= '<img class="pst-preview__img" src="' . esc_url( (string) $s['image_url'] ) . '" alt="">';
		}
		$out .= '<div class="pst-preview__title">' . esc_html( (string) $s['title'] ) . '</div>';
		$out .= '<p class="pst-preview__body">' . esc_html( mb_substr( wp_strip_all_tags( (string) ( $s['content'] ?? '' ) ), 0, 260 ) ) . '</p>';
		$out .= '</div>' . Ui::card_close();
		$out .= '</div></div>';
		return $out;
	}
}
