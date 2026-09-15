<?php
/**
 * Candidatures : supervision plateforme (pipeline compact + liste enrichie + détail type mini-CRM).
 * Lecture SEULE via `Applications\Api\ApplicationAdminDirectory` ; aucun changement de statut depuis
 * le back-office (le workflow appartient à l'entreprise). CONFIDENTIALITÉ : les notes recruteur ne
 * sont jamais exposées ici, et le CV n'apparaît que comme référence (aucun contenu, aucun
 * téléchargement).
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

final class ApplicationsScreen extends ListScreen {

	private const DIR = '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory';

	/** @var array<string,array{0:string,1:string}> */
	private const STATUSES = array(
		'new'         => array( 'Nouvelle', 'info' ),
		'review'      => array( 'À examiner', 'warning' ),
		'shortlisted' => array( 'Présélection', 'info' ),
		'interview'   => array( 'Entretien', 'success' ),
		'selected'    => array( 'Retenue', 'success' ),
		'rejected'    => array( 'Refusée', 'error' ),
		'withdrawn'   => array( 'Retirée', 'neutral' ),
	);

	/** Étapes affichées dans le pipeline (ordre du parcours). */
	private const PIPELINE = array( 'new', 'review', 'shortlisted', 'interview', 'selected' );

	protected function capability(): string {
		return Menu::CAP_ADMIN;
	}

	protected function eyebrow(): string {
		return 'Postelio · Activité';
	}

	protected function slug(): string {
		return 'postelio-applications';
	}

	protected function index(): string {
		if ( ! Data::has( self::DIR ) ) {
			return $this->module_missing( 'Candidatures', 'Candidatures' );
		}
		$tab    = $this->current( 'tab', 'all' );
		$counts = (array) call_user_func( array( self::DIR, 'counts' ) );

		$keep    = array();
		$company = (int) $this->current( 'company_id', '0' );
		$job     = $this->current( 'job_uuid' );
		$filters = array();
		if ( 'all' !== $tab && isset( self::STATUSES[ $tab ] ) ) {
			$filters['status'] = $tab;
		}
		if ( $company > 0 ) {
			$filters['company_id'] = $company;
			$keep['company_id']    = $company;
		}
		if ( '' !== $job ) {
			$filters['job_uuid'] = $job;
			$keep['job_uuid']    = $job;
		}
		$res = (array) call_user_func( array( self::DIR, 'list' ), $filters, $this->paged(), static::PER_PAGE );

		$out = $this->header( 'Candidatures', 'Parcours des candidatures, de la réception à la décision.' );

		// Pipeline : étapes réelles, cliquables (filtre).
		$steps = array();
		foreach ( self::PIPELINE as $st ) {
			$steps[] = array(
				'label'  => self::STATUSES[ $st ][0],
				'count'  => (int) ( $counts[ $st ] ?? 0 ),
				'url'    => $this->url( $this->slug(), array_merge( array( 'tab' => $st ), $keep ) ),
				'active' => $st === $tab,
			);
		}
		$out .= Ui::pipeline( $steps );

		$right = '';
		if ( ! empty( $keep ) ) {
			$right = Ui::badge( 'Filtre actif', 'accent', true ) . Ui::button( 'Réinitialiser', $this->url( $this->slug(), array( 'tab' => $tab ) ), 'ghost', true );
		}
		$out .= $this->toolbar( $this->status_tabs( array_map( static fn( $m ) => $m[0], self::STATUSES ), $counts, $tab, 'Toutes', $keep ), $right );

		$rows = array();
		foreach ( (array) $res['items'] as $a ) {
			$rows[] = $this->row( (array) $a );
		}
		$out .= Ui::table( array( 'Candidat', 'Offre', 'Statut', 'Reçue', 'Entretien', '' ), $rows, 'Aucune candidature ne correspond', 'Modifiez l\'étape ou le filtre pour élargir la liste.' );
		$out .= $this->pagination( (int) $res['total'], array_merge( array( 'tab' => $tab ), $keep ) );
		return $out;
	}

	/** @param array<string,mixed> $a @return array<int,string> */
	private function row( array $a ): array {
		$st      = (string) $a['status'];
		$meta    = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		$company = (string) $a['company'];
		return array(
			Ui::entity( (string) $a['candidate'], '' ),
			Ui::meta( (string) $a['job_title'], '' !== $company ? $company : '—' ),
			Ui::badge( $meta[0], $meta[1], true ),
			Ui::text( Fmt::date( (string) $a['created_at'] ), false, true ),
			! empty( $a['has_interview'] ) ? Ui::badge( 'Planifié', 'success', true ) : Ui::text( '—', false, true ),
			$this->view_link( (string) $a['uuid'] ),
		);
	}

	protected function detail( string $uuid ): string {
		if ( ! Data::has( self::DIR ) ) {
			return $this->module_missing( 'Candidatures', 'Candidatures' );
		}
		$a = call_user_func( array( self::DIR, 'detail' ), $uuid );
		if ( ! is_array( $a ) ) {
			return $this->not_found( 'Candidature', 'Cette candidature n\'existe pas.' );
		}
		$st   = (string) $a['status'];
		$meta = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		$snap = is_array( $a['job_snapshot'] ?? null ) ? $a['job_snapshot'] : array();

		$out  = $this->header( (string) $a['candidate'], 'Candidature reçue le ' . Fmt::datetime( $a['created_at'] ?? '' ), $this->back_link(), 'Postelio · Candidature' );
		$out .= Ui::cols_open() . Ui::col_open();

		// Colonne principale : dossier.
		$out .= Ui::card_open( (string) $a['job_title'], Fmt::or_dash( $a['company'] ), Ui::badge( $meta[0], $meta[1], true ), '', 'Offre au moment de la candidature' );
		$out .= Ui::kv( array(
			'Contrat'  => Ui::text( Fmt::or_dash( $snap['contrat'] ?? ( $snap['type_contrat'] ?? '' ) ) ),
			'Ville'    => Ui::text( Fmt::or_dash( $snap['ville'] ?? '' ) ),
			'Origine'  => Ui::text( '' !== (string) ( $a['source'] ?? '' ) ? (string) $a['source'] : 'Candidature directe' ),
		) );
		$out .= Ui::help( 'Version figée : reflète l\'offre telle que le candidat l\'a vue.' );
		if ( '' !== (string) ( $a['withdrawn_at'] ?? '' ) ) {
			$out .= Ui::alert( 'Candidature retirée par le candidat le ' . Fmt::datetime( $a['withdrawn_at'] ) . '.', 'info' );
		}
		$out .= Ui::details( 'Détails techniques', Ui::kv( array(
			'Révision de l\'offre' => Ui::text( (string) (int) ( $a['job_revision'] ?? 0 ) ),
			'Référence publique'   => Ui::text( $uuid, false, true ),
		), true ) ) . Ui::card_close();

		$msg  = trim( (string) ( $a['message'] ?? '' ) );
		$out .= Ui::card_open( 'Message du candidat' ) . ( '' !== $msg ? Ui::excerpt( $msg ) : Ui::help( 'Aucun message joint.' ) ) . Ui::card_close();

		$answers = is_array( $a['answers'] ?? null ) ? $a['answers'] : array();
		$out    .= Ui::card_open( 'Réponses de présélection' );
		if ( empty( $answers ) ) {
			$out .= Ui::help( 'Cette offre ne comportait aucune question de présélection.' );
		} else {
			$pairs = array();
			foreach ( $answers as $k => $v ) {
				$question           = is_array( $v ) ? (string) ( $v['question'] ?? $k ) : (string) $k;
				$answer             = is_array( $v ) ? (string) ( $v['answer'] ?? ( $v['value'] ?? '' ) ) : (string) $v;
				$pairs[ $question ] = Ui::text( Fmt::or_dash( $answer ) );
			}
			$out .= Ui::kv( $pairs );
		}
		$out .= Ui::card_close();

		$out .= Ui::col_close() . Ui::col_open();

		// Colonne latérale : état, pièces, historique.
		$out .= Ui::card_open( 'État', '', '', 'bo-card--aside' ) . Ui::kv( array(
			'Étape'      => Ui::badge( $meta[0], $meta[1], true ),
			'Entretien'  => ! empty( $a['has_interview'] ) ? Ui::badge( 'Planifié', 'success', true ) : Ui::text( '—', false, true ),
			'Décision'   => Ui::text( 'Réservée à l\'entreprise', false, true ),
		), true ) . Ui::card_close();

		$out .= Ui::card_open( 'Pièces', '', '', 'bo-card--aside' );
		if ( '' !== (string) ( $a['cv_reference'] ?? '' ) ) {
			$out .= '<div class="bo-chips">' . Ui::badge( 'CV transmis', 'info', true ) . '</div>';
			$out .= Ui::help( 'Fichier privé, consultable uniquement par l\'entreprise destinataire : aucun contenu ni lien de téléchargement ici.' );
		} else {
			$out .= Ui::help( 'Aucun CV rattaché à cette candidature.' );
		}
		$out .= Ui::protected_notice( 'Les notes recruteur sont confidentielles et réservées à l\'entreprise concernée.' );
		$out .= Ui::card_close();

		$out .= Ui::card_open( 'Historique', '', '', 'bo-card--aside' ) . Ui::timeline( $this->history( (array) ( $a['history'] ?? array() ) ) ) . Ui::card_close();
		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
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
