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
		$view = $this->current( 'view' );
		if ( '' !== $view ) {
			return $this->detail( $view );
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

	private function actions( string $uuid, string $status, bool $with_view = true ): string {
		$h = '<div class="pst-admin-actions">';
		if ( $with_view ) {
			$h .= '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-jobs', array( 'view' => $uuid ) ) ) . '">Voir</a>';
		}
		if ( Contracts::has( '\\Postelio\\Jobs\\Api\\JobModeration' ) && current_user_can( 'pst_manage_all_jobs' ) ) {
			if ( 'suspended' === $status ) {
				$h .= Ui::action_button( 'pst_admin_job_unsuspend', array( 'uuid' => $uuid ), 'Réactiver', 'primary' );
			} elseif ( in_array( $status, array( 'published', 'expiring' ), true ) ) {
				$h .= Ui::action_button( 'pst_admin_job_suspend', array( 'uuid' => $uuid ), 'Suspendre', 'danger', 'Suspendre cette offre ? Elle ne sera plus visible publiquement.' );
			}
		}
		return $h . '</div>';
	}

	/** Détail offre (native ou externe). */
	private function detail( string $uuid ): string {
		$j = \Postelio\Jobs\Api\JobAdminDirectory::detail( $uuid );
		$back = '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-jobs' ) ) . '">← Liste</a>';

		if ( null === $j ) {
			// Offre externe éventuelle.
			$ext = class_exists( '\\Postelio\\Jobs\\Api\\JobDirectory' ) ? \Postelio\Jobs\Api\JobDirectory::external( $uuid ) : null;
			if ( null === $ext ) {
				return Ui::header( 'Offre', 'Back-office Postelio', $back ) . Ui::empty_state( 'Introuvable', 'Cette offre n\'existe pas.', '📄' );
			}
			return $this->external_detail( $ext, $back );
		}

		$status = (string) $j['status'];
		$out    = Ui::header( (string) $j['titre'], 'Fiche offre — ' . (string) ( $j['company']['nom'] ?? '' ), $back . ' ' . $this->actions( $uuid, $status, false ) );
		$out   .= '<div class="pst-admin-cols"><div>';

		$out .= Ui::card_open( 'Offre' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Entreprise</dt><dd>' . esc_html( (string) ( $j['company']['nom'] ?? '—' ) ) . '</dd>';
		$out .= '<dt>Source</dt><dd>' . Ui::badge( 'Postelio', 'info' ) . '</dd>';
		$out .= '<dt>Statut</dt><dd>' . Ui::badge( ucfirst( $status ), 'published' === $status ? 'success' : ( 'suspended' === $status ? 'error' : 'neutral' ), true ) . '</dd>';
		$out .= '<dt>Contrat</dt><dd>' . esc_html( (string) ( $j['contrat'] ?? '—' ) ) . '</dd>';
		$out .= '<dt>Ville</dt><dd>' . esc_html( (string) ( $j['ville'] ?? '—' ) ) . '</dd>';
		$out .= '<dt>Catégorie</dt><dd>' . esc_html( (string) ( $j['categorie'] ?? '—' ) ) . '</dd>';
		$out .= '<dt>Télétravail</dt><dd>' . esc_html( (string) ( $j['teletravail'] ?? '—' ) ) . '</dd>';
		$out .= '<dt>Publication</dt><dd>' . esc_html( (string) ( $j['date_publication'] ?? '—' ) ) . '</dd>';
		$out .= '<dt>Expiration</dt><dd>' . esc_html( (string) ( $j['date_expiration'] ?? '—' ) ) . '</dd>';
		$out .= '</dl>' . Ui::card_close();

		$out .= Ui::card_open( 'Cycle de vie' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Révision</dt><dd>' . esc_html( (string) ( $j['revision'] ?? 0 ) ) . '</dd>';
		$out .= '<dt>Renouvellements</dt><dd>' . esc_html( (string) ( $j['renewal_count'] ?? 0 ) ) . '</dd>';
		$out .= '<dt>Renouvelé le</dt><dd>' . esc_html( '' !== (string) ( $j['renewed_at'] ?? '' ) ? (string) $j['renewed_at'] : '—' ) . '</dd>';
		$out .= '</dl>' . Ui::card_close();
		$out .= '</div><div>';

		// Aperçu
		$out .= Ui::card_open( 'Aperçu' );
		$out .= '<div style="border:1px solid var(--pst-border);border-radius:12px;padding:16px;background:#fff">';
		$out .= '<div style="font-weight:800;color:var(--pst-primary);font-size:16px">' . esc_html( (string) $j['titre'] ) . '</div>';
		$out .= '<div class="pst-admin-stat__sub">' . esc_html( (string) ( $j['company']['nom'] ?? '' ) ) . ' · ' . esc_html( (string) ( $j['ville'] ?? '' ) ) . '</div>';
		$out .= '<p style="margin:8px 0">' . Ui::badge( (string) ( $j['contrat'] ?? '' ), 'neutral' ) . '</p>';
		$out .= '<p style="color:var(--pst-text-soft);font-size:13px">' . esc_html( mb_substr( wp_strip_all_tags( (string) ( $j['description'] ?? '' ) ), 0, 260 ) ) . '</p>';
		$out .= '</div>' . Ui::card_close();
		$out .= '</div></div>';
		return $out;
	}

	/** @param array<string,mixed> $ext */
	private function external_detail( array $ext, string $back ): string {
		$pv  = is_array( $ext['public_view'] ?? null ) ? $ext['public_view'] : $ext;
		$out = Ui::header( (string) ( $pv['title'] ?? 'Offre externe' ), 'Offre externe', $back );
		$out .= Ui::card_open( 'Source externe' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Provider</dt><dd>' . esc_html( (string) ( $ext['source_key'] ?? $pv['source']['key'] ?? 'externe' ) ) . '</dd>';
		$out .= '<dt>État</dt><dd>' . Ui::badge( (string) ( $ext['sync_status'] ?? 'active' ), 'active' === ( $ext['sync_status'] ?? 'active' ) ? 'success' : 'warning', true ) . '</dd>';
		$out .= '<dt>Disponibilité</dt><dd>' . esc_html( (string) ( $ext['local_visibility'] ?? 'visible' ) ) . '</dd>';
		$out .= '</dl>' . Ui::card_close();
		if ( current_user_can( 'pst_moderate_content' ) && Contracts::has( '\\Postelio\\JobSources\\Api\\JobSourcesModeration' ) ) {
			$uuid = (string) ( $pv['uuid'] ?? $ext['public_uuid'] ?? '' );
			$hidden = 'hidden' === (string) ( $ext['local_visibility'] ?? 'visible' );
			$out .= Ui::card_open( 'Modération' )
				. ( $hidden
					? Ui::action_button( 'pst_admin_extjob_unhide', array( 'uuid' => $uuid ), 'Restaurer', 'primary' )
					: Ui::action_button( 'pst_admin_extjob_hide', array( 'uuid' => $uuid ), 'Masquer', 'danger', 'Masquer cette offre externe du public ?' ) )
				. Ui::card_close();
		}
		return $out;
	}
}
