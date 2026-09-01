<?php
/**
 * Entretiens : supervision plateforme (liste par statut + détail avec chronologie). Lecture seule
 * via InterviewAdminDirectory. Les COORDONNÉES SENSIBLES (adresse / téléphone / lien visio) ne
 * figurent JAMAIS dans la liste ; en détail, elles ne sont demandées au contrat que si l'admin
 * dispose de la capacité plateforme. Aucun ID SQL.
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Contracts;
use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class InterviewsPage extends Page {

	private const PER_PAGE = 20;
	private const DIR      = '\\Postelio\\Interviews\\Api\\InterviewAdminDirectory';

	/** @var array<string,array{0:string,1:string}> */
	private const STATUSES = array(
		'proposed'             => array( 'Proposé', 'info' ),
		'confirmed'            => array( 'Confirmé', 'success' ),
		'reschedule_requested' => array( 'Replanification', 'warning' ),
		'declined'             => array( 'Refusé', 'error' ),
		'cancelled'            => array( 'Annulé', 'neutral' ),
		'completed'            => array( 'Terminé', 'success' ),
	);

	private const TYPES = array( 'video' => 'Visio', 'onsite' => 'Sur place', 'phone' => 'Téléphone' );

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_ADMIN;
	}

	protected function body(): string {
		if ( ! Contracts::has( self::DIR ) ) {
			return Ui::header( 'Entretiens', 'Back-office Postelio' )
				. Ui::empty_state( 'Module indisponible', 'Le module Entretiens n\'est pas actif.', '📅' );
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
		$res = call_user_func( array( self::DIR, 'list' ), $filters, $paged, self::PER_PAGE );

		$out  = Ui::toolbar( 'Entretiens', 'Suivi des entretiens proposés aux candidats.' );
		$tabs = array( array( 'label' => 'Tous', 'url' => $this->url( 'postelio-interviews', array( 'tab' => 'all' ) ), 'count' => (int) ( $counts['total'] ?? 0 ), 'active' => 'all' === $tab ) );
		foreach ( self::STATUSES as $st => $meta ) {
			$tabs[] = array( 'label' => $meta[0], 'url' => $this->url( 'postelio-interviews', array( 'tab' => $st ) ), 'count' => (int) ( $counts[ $st ] ?? 0 ), 'active' => $st === $tab );
		}
		$out .= Ui::tabs( $tabs );

		$rows = array();
		foreach ( $res['items'] as $iv ) {
			$rows[] = $this->row( (array) $iv );
		}
		$out .= Ui::table( array( 'Candidat', 'Entreprise', 'Créneau', 'Type', 'Statut', 'Actions' ), $rows, 'Aucun entretien.' );
		$out .= Ui::pager( $this->url( 'postelio-interviews', array( 'tab' => $tab ) ), $paged, self::PER_PAGE, (int) $res['total'] );
		return $out;
	}

	/** @param array<string,mixed> $iv @return array<int,string> */
	private function row( array $iv ): array {
		$st = (string) $iv['status'];
		$m  = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		$co = (string) $iv['company'];
		return array(
			Ui::entity_cell( (string) $iv['candidate'], (string) $iv['job_title'] ),
			Ui::entity_cell( '' !== $co ? $co : '—', '', array( 'square' => true, 'variant' => 'neutral' ) ),
			Ui::text( $this->fmt( (string) $iv['scheduled_at'] ), false, true ),
			Ui::badge( self::TYPES[ (string) $iv['type'] ] ?? (string) $iv['type'], 'neutral' ),
			Ui::badge( $m[0], $m[1], true ),
			'<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-interviews', array( 'view' => (string) $iv['uuid'] ) ) ) . '">Voir</a>',
		);
	}

	private function detail( string $uuid ): string {
		// La capacité plateforme (garde de page) conditionne l'accès aux coordonnées sensibles.
		$can_coords = current_user_can( \Postelio\Admin\Menu::CAP_ADMIN );
		$iv         = call_user_func( array( self::DIR, 'detail' ), $uuid, $can_coords );
		$back       = '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-interviews' ) ) . '">← Liste</a>';
		if ( null === $iv ) {
			return Ui::header( 'Entretien', 'Back-office Postelio', $back ) . Ui::empty_state( 'Introuvable', 'Cet entretien n\'existe pas.', '📅' );
		}
		$st = (string) $iv['status'];
		$m  = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );

		$out  = Ui::header( 'Entretien — ' . (string) $iv['candidate'], (string) $iv['company'] . ' · ' . (string) $iv['job_title'], $back . ' ' . Ui::badge( $m[0], $m[1], true ) );
		$out .= '<div class="pst-admin-cols"><div>';

		// Planification.
		$out .= Ui::card_open( 'Planification' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Type</dt><dd>' . Ui::badge( self::TYPES[ (string) $iv['type'] ] ?? (string) $iv['type'], 'neutral' ) . '</dd>';
		$out .= '<dt>Créneau</dt><dd>' . esc_html( $this->fmt( (string) $iv['scheduled_at'] ) ) . '</dd>';
		$out .= '<dt>Fuseau</dt><dd>' . esc_html( (string) ( $iv['timezone'] ?? 'UTC' ) ) . '</dd>';
		if ( '' !== (string) ( $iv['proposed_at'] ?? '' ) ) {
			$out .= '<dt>Re-créneau proposé</dt><dd>' . esc_html( $this->fmt( (string) $iv['proposed_at'] ) ) . '</dd>';
		}
		if ( '' !== (string) ( $iv['cancelled_at'] ?? '' ) ) {
			$out .= '<dt>Annulé le</dt><dd>' . esc_html( $this->fmt( (string) $iv['cancelled_at'] ) ) . '</dd>';
		}
		$out .= '</dl>' . Ui::card_close();

		// Coordonnées sensibles — capability-gated.
		$out .= Ui::card_open( 'Coordonnées', 'sensible' );
		if ( ! $can_coords ) {
			$out .= '<p class="pst-help">' . Ui::badge( 'Protégées', 'neutral', true ) . ' Coordonnées accessibles uniquement avec la capacité d\'administration plateforme.</p>';
		} elseif ( empty( $iv['has_coordinates'] ) ) {
			$out .= '<p class="pst-help">Aucune coordonnée renseignée.</p>';
		} else {
			$out .= $this->coords_html( is_array( $iv['coordinates'] ?? null ) ? $iv['coordinates'] : array(), (string) $iv['type'] );
		}
		$out .= Ui::card_close();

		// Instructions (non sensibles).
		if ( '' !== trim( (string) ( $iv['instructions'] ?? '' ) ) ) {
			$out .= Ui::card_open( 'Instructions' ) . '<p class="pst-preview__body">' . esc_html( (string) $iv['instructions'] ) . '</p>' . Ui::card_close();
		}
		$out .= '</div><div>';

		// Candidature liée.
		$out .= Ui::card_open( 'Candidature liée' );
		$app_uuid = (string) ( $iv['application_uuid'] ?? '' );
		if ( '' !== $app_uuid && Contracts::has( '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory' ) ) {
			$out .= '<p><a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-applications', array( 'view' => $app_uuid ) ) ) . '">Ouvrir la candidature</a></p>';
		} else {
			$out .= '<p class="pst-help">Référence : ' . esc_html( '' !== $app_uuid ? substr( $app_uuid, 0, 8 ) . '…' : '—' ) . '</p>';
		}
		$out .= Ui::card_close();

		// Historique / chronologie.
		$out .= Ui::card_open( 'Chronologie' ) . Ui::timeline( $this->history( (array) ( $iv['history'] ?? array() ) ) ) . Ui::card_close();
		$out .= '</div></div>';
		return $out;
	}

	/** @param array<string,mixed> $coords */
	private function coords_html( array $coords, string $type ): string {
		$h = '<dl class="pst-admin-kv">';
		$loc = is_array( $coords['location'] ?? null ) ? $coords['location'] : array();
		$vid = is_array( $coords['video'] ?? null ) ? $coords['video'] : array();
		$tel = is_array( $coords['phone'] ?? null ) ? $coords['phone'] : array();
		if ( 'onsite' === $type && ! empty( $loc ) ) {
			$h .= '<dt>Adresse</dt><dd>' . esc_html( (string) ( $loc['address'] ?? $loc['adresse'] ?? implode( ', ', array_filter( array_map( 'strval', $loc ) ) ) ) ) . '</dd>';
		} elseif ( 'video' === $type && ! empty( $vid ) ) {
			$h .= '<dt>Lien visio</dt><dd>' . esc_html( (string) ( $vid['url'] ?? $vid['link'] ?? '—' ) ) . '</dd>';
			if ( ! empty( $vid['provider'] ) ) {
				$h .= '<dt>Fournisseur</dt><dd>' . esc_html( (string) $vid['provider'] ) . '</dd>';
			}
		} elseif ( 'phone' === $type && ! empty( $tel ) ) {
			$h .= '<dt>Téléphone</dt><dd>' . esc_html( (string) ( $tel['number'] ?? $tel['phone'] ?? '—' ) ) . '</dd>';
		} else {
			$h .= '<dt>Détails</dt><dd>—</dd>';
		}
		return $h . '</dl>';
	}

	/** @param array<int,array<string,mixed>> $hist @return array<int,array<string,mixed>> */
	private function history( array $hist ): array {
		$out = array();
		foreach ( $hist as $h ) {
			$to    = (string) ( $h['to_status'] ?? '' );
			$lbl   = '' !== $to && isset( self::STATUSES[ $to ] ) ? self::STATUSES[ $to ][0] : (string) ( $h['action'] ?? 'Événement' );
			$actor = (string) ( $h['actor_role'] ?? '' );
			$out[] = array( 'label' => $lbl . ( '' !== $actor ? ' · ' . $actor : '' ), 'time' => $this->fmt( (string) ( $h['created_at'] ?? '' ) ), 'done' => true );
		}
		return $out;
	}

	private function fmt( string $v ): string {
		return ( '' !== $v && '0000-00-00 00:00:00' !== $v ) ? mysql2date( 'd/m/Y H:i', $v ) : '—';
	}
}
