<?php
/**
 * Entretiens : supervision plateforme (liste par statut + détail avec chronologie). Lecture seule
 * via `Interviews\Api\InterviewAdminDirectory`. CONFIDENTIALITÉ : les coordonnées (adresse, lien
 * visio, téléphone) ne figurent JAMAIS dans la liste ; en détail, elles ne sont demandées au
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

	protected function capability(): string {
		return Menu::CAP_ADMIN;
	}

	protected function slug(): string {
		return 'postelio-interviews';
	}

	protected function index(): string {
		if ( ! Data::has( self::DIR ) ) {
			return $this->module_missing( 'Entretiens', 'Entretiens' );
		}
		$tab    = $this->current( 'tab', 'all' );
		$counts = (array) call_user_func( array( self::DIR, 'counts' ) );

		$filters = array();
		if ( 'all' !== $tab && isset( self::STATUSES[ $tab ] ) ) {
			$filters['status'] = $tab;
		}
		$res = (array) call_user_func( array( self::DIR, 'list' ), $filters, $this->paged(), static::PER_PAGE );

		$out  = Ui::page_header( 'Entretiens', 'Entretiens proposés aux candidats par les entreprises.' );
		$out .= $this->status_tabs( array_map( static fn( $m ) => $m[0], self::STATUSES ), $counts, $tab, 'Tous' );

		$rows = array();
		foreach ( (array) $res['items'] as $iv ) {
			$rows[] = $this->row( (array) $iv );
		}
		$out .= Ui::table( array( 'Candidat', 'Entreprise', 'Créneau', 'Mode', 'Statut', 'Actions' ), $rows, 'Aucun entretien ne correspond.' );
		$out .= $this->pagination( (int) $res['total'], array( 'tab' => $tab ) );
		return $out;
	}

	/** @param array<string,mixed> $iv @return array<int,string> */
	private function row( array $iv ): array {
		$st   = (string) $iv['status'];
		$meta = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		return array(
			Ui::entity( (string) $iv['candidate'], (string) $iv['job_title'] ),
			Ui::entity( Fmt::or_dash( $iv['company'] ), '', '', true ),
			Ui::text( Fmt::datetime( $iv['scheduled_at'] ?? '' ), false, true ),
			Ui::badge( self::TYPES[ (string) $iv['type'] ] ?? (string) $iv['type'], 'neutral' ),
			Ui::badge( $meta[0], $meta[1], true ),
			$this->view_link( (string) $iv['uuid'] ),
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

		$out  = Ui::page_header( 'Entretien · ' . (string) $iv['candidate'], (string) $iv['company'] . ' · ' . (string) $iv['job_title'], Ui::badge( $meta[0], $meta[1], true ) . $this->back_link(), 'Postelio · Entretien' );
		$out .= Ui::cols_open() . Ui::col_open();

		$pairs = array(
			'Mode'    => Ui::badge( self::TYPES[ $type ] ?? $type, 'neutral' ),
			'Créneau' => Ui::text( Fmt::datetime( $iv['scheduled_at'] ?? '' ), true ),
			'Fuseau'  => Ui::text( Fmt::or_dash( $iv['timezone'] ?? 'UTC' ) ),
		);
		if ( '' !== (string) ( $iv['proposed_at'] ?? '' ) ) {
			$pairs['Créneau proposé'] = Ui::text( Fmt::datetime( $iv['proposed_at'] ) );
		}
		if ( '' !== (string) ( $iv['cancelled_at'] ?? '' ) ) {
			$pairs['Annulé le'] = Ui::text( Fmt::datetime( $iv['cancelled_at'] ) );
		}
		$out .= Ui::card_open( 'Rendez-vous' ) . Ui::kv( $pairs ) . Ui::card_close();

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
		$out     .= Ui::card_open( 'Candidature liée' );
		$out     .= ( '' !== $app_uuid && Data::has( '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory' ) )
			? Ui::button( 'Ouvrir la candidature', $this->url( 'postelio-applications', array( 'view' => $app_uuid ) ), 'primary', true )
			: Ui::help( 'Aucune candidature accessible pour cet entretien.' );
		$out     .= Ui::card_close();

		$out .= Ui::card_open( 'Chronologie' ) . Ui::timeline( $this->history( (array) ( $iv['history'] ?? array() ) ) ) . Ui::card_close();
		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
	}

	/** @param array<string,mixed> $coords @return array<string,string> */
	private function coords( array $coords, string $type ): array {
		$location = is_array( $coords['location'] ?? null ) ? $coords['location'] : array();
		$video    = is_array( $coords['video'] ?? null ) ? $coords['video'] : array();
		$phone    = is_array( $coords['phone'] ?? null ) ? $coords['phone'] : array();

		if ( 'onsite' === $type && ! empty( $location ) ) {
			$address = (string) ( $location['address'] ?? ( $location['adresse'] ?? implode( ', ', array_filter( array_map( 'strval', $location ) ) ) ) );
			return array( 'Adresse' => Ui::text( Fmt::or_dash( $address ) ) );
		}
		if ( 'video' === $type && ! empty( $video ) ) {
			$pairs = array( 'Lien de connexion' => Ui::text( Fmt::or_dash( $video['url'] ?? ( $video['link'] ?? '' ) ) ) );
			if ( ! empty( $video['provider'] ) ) {
				$pairs['Outil'] = Ui::text( (string) $video['provider'] );
			}
			return $pairs;
		}
		if ( 'phone' === $type && ! empty( $phone ) ) {
			return array( 'Téléphone' => Ui::text( Fmt::or_dash( $phone['number'] ?? ( $phone['phone'] ?? '' ) ) ) );
		}
		return array( 'Détails' => Ui::text( '—', false, true ) );
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
