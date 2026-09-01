<?php
/**
 * Candidatures : supervision plateforme (liste par statut métier + détail). Lecture seule via
 * ApplicationAdminDirectory (contrat propriétaire). Les NOTES RECRUTEUR ne sont JAMAIS exposées ici
 * (privées à l'entreprise) : la carte correspondante reste « protégée ». Aucun ID SQL.
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Contracts;
use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApplicationsPage extends Page {

	private const PER_PAGE = 20;
	private const DIR      = '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory';

	/** @var array<string,array{0:string,1:string}> statut => [label, variante badge] */
	private const STATUSES = array(
		'new'         => array( 'Nouveau', 'info' ),
		'review'      => array( 'À examiner', 'warning' ),
		'shortlisted' => array( 'Présélection', 'info' ),
		'interview'   => array( 'Entretien', 'success' ),
		'selected'    => array( 'Retenu', 'success' ),
		'rejected'    => array( 'Refusé', 'error' ),
		'withdrawn'   => array( 'Retiré', 'neutral' ),
	);

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_ADMIN;
	}

	protected function body(): string {
		if ( ! Contracts::has( self::DIR ) ) {
			return Ui::header( 'Candidatures', 'Back-office Postelio' )
				. Ui::empty_state( 'Module indisponible', 'Le module Candidatures n\'est pas actif.', '📨' );
		}
		$view = $this->current( 'view' );
		if ( '' !== $view ) {
			return $this->detail( $view );
		}

		$tab    = $this->current( 'tab', 'all' );
		$paged  = $this->paged();
		$counts = call_user_func( array( self::DIR, 'counts' ) );

		$filters = array();
		if ( 'all' !== $tab && isset( self::STATUSES[ $tab ] ) ) {
			$filters['status'] = $tab;
		}
		$company = (int) $this->current( 'company_id', '0' );
		$job     = $this->current( 'job_uuid' );
		if ( $company > 0 ) {
			$filters['company_id'] = $company;
		}
		if ( '' !== $job ) {
			$filters['job_uuid'] = $job;
		}
		$res = call_user_func( array( self::DIR, 'list' ), $filters, $paged, self::PER_PAGE );

		$out  = Ui::toolbar( 'Candidatures', 'Supervision des candidatures de la plateforme.' );

		$keep = array();
		if ( $company > 0 ) {
			$keep['company_id'] = $company;
		}
		if ( '' !== $job ) {
			$keep['job_uuid'] = $job;
		}

		// Résumé pipeline (données réelles), cliquable → filtre par étape.
		$out .= '<div class="pst-pipe">';
		foreach ( array( 'new', 'review', 'shortlisted', 'interview', 'selected' ) as $st ) {
			$meta = self::STATUSES[ $st ];
			$out .= '<a class="pst-pipe__step' . ( $st === $tab ? ' is-active' : '' ) . '" href="' . esc_url( $this->url( 'postelio-applications', array_merge( array( 'tab' => $st ), $keep ) ) ) . '">'
				. '<span class="pst-pipe__n">' . (int) ( $counts[ $st ] ?? 0 ) . '</span>'
				. '<span class="pst-pipe__l">' . esc_html( $meta[0] ) . '</span></a>';
		}
		$out .= '</div>';

		// Onglets par statut.
		$tabs = array( array( 'label' => 'Tous', 'url' => $this->url( 'postelio-applications', array_merge( array( 'tab' => 'all' ), $keep ) ), 'count' => (int) ( $counts['total'] ?? 0 ), 'active' => 'all' === $tab ) );
		foreach ( self::STATUSES as $st => $meta ) {
			$tabs[] = array( 'label' => $meta[0], 'url' => $this->url( 'postelio-applications', array_merge( array( 'tab' => $st ), $keep ) ), 'count' => (int) ( $counts[ $st ] ?? 0 ), 'active' => $st === $tab );
		}
		$out .= Ui::tabs( $tabs );

		if ( ! empty( $keep ) ) {
			$out .= '<p class="pst-help">Filtre actif — <a href="' . esc_url( $this->url( 'postelio-applications', array( 'tab' => $tab ) ) ) . '">réinitialiser</a>.</p>';
		}

		$rows = array();
		foreach ( $res['items'] as $a ) {
			$rows[] = $this->row( (array) $a );
		}
		$out .= Ui::table( array( 'Candidat', 'Offre', 'Statut', 'Reçue', 'Entretien', 'Actions' ), $rows, 'Aucune candidature.' );
		$out .= Ui::pager( $this->url( 'postelio-applications', array_merge( array( 'tab' => $tab ), $keep ) ), $paged, self::PER_PAGE, (int) $res['total'] );
		return $out;
	}

	/** @param array<string,mixed> $a @return array<int,string> */
	private function row( array $a ): array {
		$st  = (string) $a['status'];
		$m   = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		$iv  = ! empty( $a['has_interview'] ) ? Ui::badge( 'Planifié', 'success', true ) : Ui::text( '—', false, true );
		$offer_sub = (string) $a['company'];
		return array(
			Ui::entity_cell( (string) $a['candidate'], '', array( 'variant' => 'primary' ) ),
			Ui::entity_cell( (string) $a['job_title'], '' !== $offer_sub ? $offer_sub : '—', array( 'square' => true, 'seed' => '' !== $offer_sub ? $offer_sub : (string) $a['job_title'] ) ),
			Ui::badge( $m[0], $m[1], true ),
			Ui::text( substr( (string) $a['created_at'], 0, 10 ), false, true ),
			$iv,
			'<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-applications', array( 'view' => (string) $a['uuid'] ) ) ) . '">Voir</a>',
		);
	}

	private function detail( string $uuid ): string {
		$a    = call_user_func( array( self::DIR, 'detail' ), $uuid );
		$back = '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-applications' ) ) . '">← Liste</a>';
		if ( null === $a ) {
			return Ui::header( 'Candidature', 'Back-office Postelio', $back ) . Ui::empty_state( 'Introuvable', 'Cette candidature n\'existe pas.', '📨' );
		}
		$st   = (string) $a['status'];
		$m    = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		$snap = is_array( $a['job_snapshot'] ?? null ) ? $a['job_snapshot'] : array();

		$out  = Ui::header( (string) $a['candidate'], 'Candidature — ' . (string) $a['job_title'], $back . ' ' . Ui::badge( $m[0], $m[1], true ) );
		$out .= '<div class="pst-admin-cols"><div>';

		// Vue générale (snapshot offre).
		$out .= Ui::card_open( 'Offre au moment de la candidature' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Offre</dt><dd>' . esc_html( (string) $a['job_title'] ) . '</dd>';
		$out .= '<dt>Entreprise</dt><dd>' . esc_html( (string) $a['company'] ) . '</dd>';
		$out .= '<dt>Contrat</dt><dd>' . esc_html( (string) ( $snap['contrat'] ?? $snap['type_contrat'] ?? '—' ) ) . '</dd>';
		$out .= '<dt>Ville</dt><dd>' . esc_html( (string) ( $snap['ville'] ?? '—' ) ) . '</dd>';
		$out .= '<dt>Révision offre</dt><dd>' . esc_html( (string) ( $a['job_revision'] ?? 0 ) ) . '</dd>';
		$out .= '<dt>Statut</dt><dd>' . Ui::badge( $m[0], $m[1], true ) . '</dd>';
		$out .= '<dt>Reçue le</dt><dd>' . esc_html( $this->fmt( (string) $a['created_at'] ) ) . '</dd>';
		$out .= '<dt>Source</dt><dd>' . esc_html( '' !== (string) ( $a['source'] ?? '' ) ? (string) $a['source'] : 'directe' ) . '</dd>';
		if ( '' !== (string) ( $a['withdrawn_at'] ?? '' ) ) {
			$out .= '<dt>Retirée le</dt><dd>' . esc_html( $this->fmt( (string) $a['withdrawn_at'] ) ) . '</dd>';
		}
		$out .= '</dl>' . Ui::card_close();

		// Message du candidat.
		$out .= Ui::card_open( 'Message du candidat' );
		$msg  = trim( (string) ( $a['message'] ?? '' ) );
		$out .= '' !== $msg ? '<p class="pst-preview__body">' . esc_html( $msg ) . '</p>' : '<p class="pst-help">Aucun message.</p>';
		$out .= Ui::card_close();

		// Réponses de présélection.
		$ans = is_array( $a['answers'] ?? null ) ? $a['answers'] : array();
		$out .= Ui::card_open( 'Réponses de présélection' );
		if ( empty( $ans ) ) {
			$out .= '<p class="pst-help">Aucune question de présélection.</p>';
		} else {
			$out .= '<dl class="pst-admin-kv">';
			foreach ( $ans as $k => $v ) {
				$q = is_array( $v ) ? (string) ( $v['question'] ?? $k ) : (string) $k;
				$r = is_array( $v ) ? (string) ( $v['answer'] ?? $v['value'] ?? '' ) : (string) $v;
				$out .= '<dt>' . esc_html( $q ) . '</dt><dd>' . esc_html( '' !== $r ? $r : '—' ) . '</dd>';
			}
			$out .= '</dl>';
		}
		$out .= Ui::card_close();
		$out .= '</div><div>';

		// CV référencé (métadonnée, jamais de contenu/téléchargement ici).
		$out .= Ui::card_open( 'CV joint' );
		if ( '' !== (string) ( $a['cv_reference'] ?? '' ) ) {
			$out .= '<p>' . Ui::badge( 'CV référencé', 'info', true ) . '</p>';
			$out .= '<p class="pst-help">Référence : ' . esc_html( substr( (string) $a['cv_reference'], 0, 8 ) ) . '… — le fichier est privé (consultable par l\'entreprise destinataire).</p>';
		} else {
			$out .= '<p class="pst-help">Aucun CV rattaché à cette candidature.</p>';
		}
		$out .= Ui::card_close();

		// Notes recruteur — TOUJOURS protégées (confidentielles à l'entreprise).
		$out .= Ui::card_open( 'Notes recruteur' );
		$out .= '<p class="pst-help">' . Ui::badge( 'Protégées', 'neutral', true ) . ' Les notes recruteur sont confidentielles et réservées à l\'entreprise concernée. Elles ne sont pas accessibles depuis la supervision plateforme.</p>';
		$out .= Ui::card_close();

		// Historique.
		$out .= Ui::card_open( 'Historique' ) . Ui::timeline( $this->history( (array) ( $a['history'] ?? array() ) ) ) . Ui::card_close();
		$out .= '</div></div>';
		return $out;
	}

	/** @param array<int,array<string,mixed>> $hist @return array<int,array<string,mixed>> */
	private function history( array $hist ): array {
		$out = array();
		foreach ( $hist as $h ) {
			$action = (string) ( $h['action'] ?? '' );
			$to     = (string) ( $h['to_status'] ?? '' );
			$lbl    = '' !== $to && isset( self::STATUSES[ $to ] ) ? self::STATUSES[ $to ][0] : ( '' !== $action ? $action : 'Événement' );
			$actor  = (string) ( $h['actor_role'] ?? '' );
			$out[]  = array(
				'label' => $lbl . ( '' !== $actor ? ' · ' . $actor : '' ),
				'time'  => $this->fmt( (string) ( $h['created_at'] ?? '' ) ),
				'done'  => true,
			);
		}
		return $out;
	}

	private function fmt( string $v ): string {
		return ( '' !== $v && '0000-00-00 00:00:00' !== $v ) ? mysql2date( 'd/m/Y H:i', $v ) : '—';
	}
}
