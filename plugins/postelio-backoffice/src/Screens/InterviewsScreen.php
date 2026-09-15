<?php
/**
 * Entretiens : vue par période (Aujourd'hui · À venir · À confirmer · Historique) en cartes
 * compactes (heure, candidat, offre, entreprise, mode, statut) et détail avec chronologie. Lecture
 * seule via `Interviews\Api\InterviewAdminDirectory`. CONFIDENTIALITÉ : les coordonnées (adresse,
 * lien visio, téléphone) ne figurent JAMAIS dans la liste ; en détail, elles ne sont demandées au
 * contrat que si l'utilisateur dispose de la capacité d'administration plateforme.
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

final class InterviewsScreen extends ListScreen {

	private const DIR = '\\Postelio\\Interviews\\Api\\InterviewAdminDirectory';

	/** @var array<string,array{0:string,1:string}> */
	private const STATUSES = array(
		'proposed'             => array( 'Proposé', 'info' ),
		'confirmed'            => array( 'Confirmé', 'success' ),
		'reschedule_requested' => array( 'Nouveau créneau demandé', 'warning' ),
		'declined'             => array( 'Refusé', 'error' ),
		'cancelled'            => array( 'Annulé', 'neutral' ),
		'completed'            => array( 'Terminé', 'success' ),
	);

	private const TYPES = array( 'video' => 'Visioconférence', 'onsite' => 'Sur place', 'phone' => 'Téléphone' );

	/** Type d'entretien => canal de coordonnées correspondant (contrat du module Entretiens). */
	private const CHANNELS = array( 'onsite' => 'location', 'video' => 'video', 'phone' => 'phone' );

	/** Vues : clé => [libellé, filtre statut passé au contrat (null = tous)]. */
	private const VIEWS = array(
		'upcoming'  => array( 'À venir', null ),
		'today'     => array( 'Aujourd\'hui', null ),
		'pending'   => array( 'À confirmer', 'proposed' ),
		'history'   => array( 'Historique', null ),
	);

	protected function capability(): string {
		return Menu::CAP_ADMIN;
	}

	protected function eyebrow(): string {
		return 'Postelio · Activité';
	}

	protected function slug(): string {
		return 'postelio-interviews';
	}

	protected function index(): string {
		if ( ! Data::has( self::DIR ) ) {
			return $this->module_missing( 'Entretiens', 'Entretiens' );
		}
		$view = $this->current( 'tab', 'upcoming' );
		if ( ! isset( self::VIEWS[ $view ] ) ) {
			$view = 'upcoming';
		}
		$counts = (array) call_user_func( array( self::DIR, 'counts' ) );

		// Le contrat filtre par statut ; le découpage temporel est fait côté présentation sur la page
		// courante (aucune requête supplémentaire, aucune logique métier).
		$filters = array();
		if ( null !== self::VIEWS[ $view ][1] ) {
			$filters['status'] = self::VIEWS[ $view ][1];
		}
		$res   = (array) call_user_func( array( self::DIR, 'list' ), $filters, $this->paged(), static::PER_PAGE );
		$items = array_map( static fn( $i ) => (array) $i, (array) $res['items'] );
		$now   = time();
		$today = wp_date( 'Y-m-d' );

		if ( 'today' === $view ) {
			$items = array_values( array_filter( $items, static fn( $iv ) => wp_date( 'Y-m-d', (int) strtotime( (string) ( $iv['scheduled_at'] ?? '' ) ) ) === $today ) );
		} elseif ( 'upcoming' === $view ) {
			$items = array_values( array_filter( $items, static fn( $iv ) => (int) strtotime( (string) ( $iv['scheduled_at'] ?? '' ) ) >= $now && ! in_array( (string) $iv['status'], array( 'declined', 'cancelled', 'completed' ), true ) ) );
		} elseif ( 'history' === $view ) {
			$items = array_values( array_filter( $items, static fn( $iv ) => (int) strtotime( (string) ( $iv['scheduled_at'] ?? '' ) ) < $now || in_array( (string) $iv['status'], array( 'declined', 'cancelled', 'completed' ), true ) ) );
		}

		$tabs = array();
		foreach ( self::VIEWS as $key => $def ) {
			$tabs[] = array( 'label' => $def[0], 'url' => $this->url( $this->slug(), array( 'tab' => $key ) ), 'active' => $key === $view, 'count' => 'pending' === $key ? (int) ( $counts['proposed'] ?? 0 ) : null );
		}

		$out  = $this->header( 'Entretiens', 'Entretiens proposés aux candidats par les entreprises.', Ui::badge( (int) ( $counts['total'] ?? 0 ) . ' au total', 'neutral' ) );
		$out .= $this->toolbar( Ui::tabs( $tabs, 'Vue des entretiens' ) );

		if ( empty( $items ) ) {
			$msgs = array( 'upcoming' => 'Aucun entretien à venir sur cette page.', 'today' => 'Aucun entretien prévu aujourd\'hui.', 'pending' => 'Aucun entretien en attente de confirmation.', 'history' => 'Aucun entretien passé sur cette page.' );
			return $out . Ui::empty_state( 'Rien à afficher', $msgs[ $view ] ) . $this->pagination( (int) $res['total'], array( 'tab' => $view ) );
		}

		// Groupes datés (jour) → cartes compactes.
		$groups = array();
		foreach ( $items as $iv ) {
			$ts               = (int) strtotime( (string) ( $iv['scheduled_at'] ?? '' ) );
			$day              = $ts > 0 ? wp_date( 'l j F Y', $ts ) : 'Date inconnue';
			$groups[ $day ][] = $iv;
		}
		foreach ( $groups as $day => $list ) {
			$out .= Ui::day_open( ucfirst( (string) $day ) );
			foreach ( $list as $iv ) {
				$out .= $this->slot( $iv );
			}
			$out .= Ui::day_close();
		}
		$out .= $this->pagination( (int) $res['total'], array( 'tab' => $view ) );
		return $out;
	}

	/** @param array<string,mixed> $iv */
	private function slot( array $iv ): string {
		$st   = (string) $iv['status'];
		$meta = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		$ts   = (int) strtotime( (string) ( $iv['scheduled_at'] ?? '' ) );
		return Ui::slot(
			$this->url( $this->slug(), array( 'view' => (string) $iv['uuid'] ) ),
			$ts > 0 ? wp_date( 'H:i', $ts ) : '—',
			$ts > 0 ? wp_date( 'd/m', $ts ) : '',
			(string) $iv['candidate'],
			(string) $iv['job_title'] . ' · ' . Fmt::or_dash( $iv['company'] ),
			Ui::badge( self::TYPES[ (string) $iv['type'] ] ?? (string) $iv['type'], 'neutral' ) . Ui::badge( $meta[0], $meta[1], true )
		);
	}

	protected function detail( string $uuid ): string {
		if ( ! Data::has( self::DIR ) ) {
			return $this->module_missing( 'Entretiens', 'Entretiens' );
		}
		// La capacité plateforme conditionne la DEMANDE des coordonnées au contrat propriétaire.
		$can_coords = current_user_can( Menu::CAP_ADMIN );
		$iv         = call_user_func( array( self::DIR, 'detail' ), $uuid, $can_coords );
		if ( ! is_array( $iv ) ) {
			return $this->not_found( 'Entretien', 'Cet entretien n\'existe pas.' );
		}
		$st   = (string) $iv['status'];
		$meta = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		$type = (string) $iv['type'];

		$out  = $this->header( (string) $iv['candidate'], Fmt::or_dash( $iv['company'] ) . ' · ' . (string) $iv['job_title'], $this->back_link(), 'Postelio · Entretien' );
		$out .= Ui::cols_open() . Ui::col_open();

		$pairs = array(
			'Créneau' => Ui::text( Fmt::datetime( $iv['scheduled_at'] ?? '' ), true ),
			'Mode'    => Ui::badge( self::TYPES[ $type ] ?? $type, 'neutral' ),
			'Fuseau'  => Ui::text( Fmt::or_dash( $iv['timezone'] ?? 'UTC' ) ),
		);
		if ( '' !== (string) ( $iv['proposed_at'] ?? '' ) ) {
			$pairs['Créneau proposé'] = Ui::text( Fmt::datetime( $iv['proposed_at'] ) );
		}
		if ( '' !== (string) ( $iv['cancelled_at'] ?? '' ) ) {
			$pairs['Annulé le'] = Ui::text( Fmt::datetime( $iv['cancelled_at'] ) );
		}
		$out .= Ui::card_open( 'Rendez-vous', '', Ui::badge( $meta[0], $meta[1], true ) ) . Ui::kv( $pairs ) . Ui::card_close();

		$out .= Ui::card_open( 'Coordonnées', 'Information sensible.' );
		if ( ! $can_coords ) {
			$out .= Ui::protected_notice( 'Les coordonnées de l\'entretien ne sont accessibles qu\'avec la capacité d\'administration plateforme.' );
		} elseif ( empty( $iv['has_coordinates'] ) ) {
			$out .= Ui::help( 'Aucune coordonnée renseignée par l\'entreprise.' );
		} else {
			$out .= Ui::kv( $this->coords( is_array( $iv['coordinates'] ?? null ) ? $iv['coordinates'] : array(), $type ) );
		}
		$out .= Ui::card_close();

		$instructions = trim( (string) ( $iv['instructions'] ?? '' ) );
		if ( '' !== $instructions ) {
			$out .= Ui::card_open( 'Instructions au candidat' ) . Ui::excerpt( $instructions ) . Ui::card_close();
		}

		$out .= Ui::col_close() . Ui::col_open();

		$app_uuid = (string) ( $iv['application_uuid'] ?? '' );
		$out     .= Ui::card_open( 'Candidature liée', '', '', 'bo-card--aside' );
		$out     .= ( '' !== $app_uuid && Data::has( '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory' ) )
			? Ui::button( 'Ouvrir la candidature', $this->url( 'postelio-applications', array( 'view' => $app_uuid ) ), 'primary', true )
			: Ui::help( 'Aucune candidature accessible pour cet entretien.' );
		$out     .= Ui::card_close();

		$out .= Ui::card_open( 'Chronologie', '', '', 'bo-card--aside' ) . Ui::timeline( $this->history( (array) ( $iv['history'] ?? array() ) ) ) . Ui::card_close();
		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
	}

	/**
	 * Coordonnées du SEUL canal correspondant au type d'entretien. Clés conformes au contrat du
	 * module Entretiens (`InterviewService`) : location = address / address_complement /
	 * postal_code / city / contact / access_instructions ; video = meeting_url / provider ;
	 * phone = phone_number / who_calls.
	 *
	 * @param array<string,mixed> $coords
	 * @return array<string,string>
	 */
	private function coords( array $coords, string $type ): array {
		$group = is_array( $coords[ self::CHANNELS[ $type ] ?? '' ] ?? null ) ? $coords[ self::CHANNELS[ $type ] ] : array();
		if ( empty( array_filter( $group, static fn( $v ) => is_array( $v ) ? ! empty( $v ) : ( null !== $v && '' !== (string) $v ) ) ) ) {
			return array( 'Détails' => Ui::text( '—', false, true ) );
		}

		if ( 'onsite' === $type ) {
			$street = trim( (string) ( $group['address'] ?? '' ) . ' ' . (string) ( $group['address_complement'] ?? '' ) );
			$city   = trim( (string) ( $group['postal_code'] ?? '' ) . ' ' . (string) ( $group['city'] ?? '' ) );
			$pairs  = array(
				'Adresse' => Ui::text( Fmt::or_dash( $street ) ),
				'Ville'   => Ui::text( Fmt::or_dash( $city ) ),
			);
			if ( ! empty( $group['contact'] ) ) {
				$pairs['Contact sur place'] = Ui::text( (string) $group['contact'] );
			}
			if ( ! empty( $group['access_instructions'] ) ) {
				$pairs['Accès'] = Ui::text( (string) $group['access_instructions'] );
			}
			return $pairs;
		}

		if ( 'video' === $type ) {
			$pairs = array( 'Lien de connexion' => Ui::text( Fmt::or_dash( $group['meeting_url'] ?? '' ) ) );
			if ( ! empty( $group['provider'] ) ) {
				$pairs['Outil'] = Ui::text( (string) $group['provider'] );
			}
			return $pairs;
		}

		$who   = (string) ( $group['who_calls'] ?? '' );
		$pairs = array( 'Numéro' => Ui::text( Fmt::or_dash( $group['phone_number'] ?? '' ) ) );
		if ( '' !== $who ) {
			$pairs['Qui appelle'] = Ui::text( 'candidate_calls' === $who ? 'Le candidat appelle' : 'L\'entreprise appelle' );
		}
		return $pairs;
	}

	/** @param array<int,array<string,mixed>> $hist @return array<int,array<string,mixed>> */
	private function history( array $hist ): array {
		$roles = array( 'candidate' => 'candidat', 'recruiter' => 'recruteur', 'admin' => 'administrateur', 'system' => 'système' );
		$out   = array();
		foreach ( $hist as $h ) {
			$h     = (array) $h;
			$to    = (string) ( $h['to_status'] ?? '' );
			$label = isset( self::STATUSES[ $to ] ) ? self::STATUSES[ $to ][0] : Fmt::or_dash( $h['action'] ?? 'Événement' );
			$actor = (string) ( $h['actor_role'] ?? '' );
			$out[] = array(
				'label' => $label . ( '' !== $actor ? ' · ' . ( $roles[ $actor ] ?? $actor ) : '' ),
				'time'  => Fmt::datetime( $h['created_at'] ?? '' ),
				'done'  => true,
			);
		}
		return $out;
	}
}
