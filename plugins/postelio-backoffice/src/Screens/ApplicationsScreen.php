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
		$st      = (string) $a['status'];
		$meta    = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		$snap    = is_array( $a['job_snapshot'] ?? null ) ? $a['job_snapshot'] : array();
		$company = (string) ( $a['company'] ?? '' );
		$sources = array( 'web' => 'Site Postelio', 'app' => 'Application', 'mobile' => 'Application mobile', 'partner' => 'Partenaire', 'direct' => 'Candidature directe' );
		$source  = (string) ( $a['source'] ?? '' );

		$out  = $this->header( (string) $a['candidate'], 'Candidature reçue le ' . Fmt::datetime( $a['created_at'] ?? '' ) . ' pour ' . (string) $a['job_title'] . ( '' !== $company ? ' · ' . $company : '' ), $this->back_link(), 'Postelio · Candidature' );
		$out .= Ui::cols_open() . Ui::col_open();

		// --- Profil candidat ------------------------------------------------------
		$out .= Ui::card_open( 'Profil candidat' );
		$out .= Ui::identity( (string) $a['candidate'], 'Candidat · candidature reçue ' . Fmt::relative( $a['created_at'] ?? '' ), '', false, Ui::badge( $meta[0], $meta[1], true ) . ( ! empty( $a['has_interview'] ) ? Ui::badge( 'Entretien planifié', 'success', true ) : '' ) );
		if ( '' !== (string) ( $a['withdrawn_at'] ?? '' ) ) {
			$out .= Ui::alert( 'Candidature retirée par le candidat le ' . Fmt::datetime( $a['withdrawn_at'] ) . '.', 'info' );
		}
		$out .= Ui::card_close();

		// --- Dossier candidat : message + réponses de présélection ---------------
		$msg     = trim( (string) ( $a['message'] ?? '' ) );
		$answers = is_array( $a['answers'] ?? null ) ? $a['answers'] : array();
		$out    .= Ui::card_open( 'Dossier candidat', 'Ce que le candidat a transmis avec sa candidature.' );
		if ( '' === $msg && empty( $answers ) ) {
			$out .= Ui::empty_state( 'Dossier sans message', 'Le candidat n\'a joint ni message ni réponse de présélection.', '', 'file' );
		} else {
			if ( '' !== $msg ) {
				$out .= Ui::section_open( 'Message du candidat' ) . Ui::excerpt( $msg ) . Ui::section_close();
			}
			if ( ! empty( $answers ) ) {
				$pairs = array();
				foreach ( $answers as $k => $v ) {
					$question           = is_array( $v ) ? (string) ( $v['question'] ?? $k ) : (string) $k;
					$answer             = is_array( $v ) ? (string) ( $v['answer'] ?? ( $v['value'] ?? '' ) ) : (string) $v;
					$pairs[ $question ] = '' !== trim( $answer ) ? $answer : 'Sans réponse';
				}
				$out .= Ui::section_open( 'Réponses de présélection' ) . Ui::kv_present( $pairs ) . Ui::section_close();
			}
		}
		$out .= Ui::card_close();

		// --- CV / document : référence seulement, jamais le contenu -----------------
		$out .= Ui::card_open( 'CV et documents' );
		if ( '' !== (string) ( $a['cv_reference'] ?? '' ) ) {
			$out .= '<div class="bo-chips">' . Ui::badge( 'CV transmis', 'info', true ) . '</div>';
			$out .= Ui::help( 'Fichier privé, consultable uniquement par l\'entreprise destinataire : aucun contenu ni téléchargement depuis la supervision.' );
		} else {
			$out .= Ui::empty_state( 'Aucun CV rattaché', 'Le candidat n\'a pas joint de CV à cette candidature.', '', 'file' );
		}
		$out .= Ui::protected_notice( 'Les notes recruteur sont confidentielles et réservées à l\'entreprise concernée.' );
		$out .= Ui::card_close();

		// --- Historique réel -------------------------------------------------------
		$out .= Ui::card_open( 'Historique', 'Étapes réellement franchies.' ) . Ui::timeline( $this->history( (array) ( $a['history'] ?? array() ) ) ) . Ui::card_close();

		$out .= Ui::col_close() . Ui::col_open();

		// --- Colonne latérale : offre, entreprise, statut ----------------------------
		$out .= Ui::card_open( 'Offre', 'Telle que le candidat l\'a vue.', '', 'bo-card--aside' );
		$out .= Ui::entity( (string) $a['job_title'], $company, '', true );
		$offer = Ui::kv_present( array(
			'Contrat'      => (string) ( $snap['contrat'] ?? ( $snap['type_contrat'] ?? '' ) ),
			'Localisation' => (string) ( $snap['ville'] ?? '' ),
			'Salaire'      => (string) ( $snap['salaire'] ?? '' ),
			'Origine'      => $sources[ $source ] ?? ( '' !== $source ? ucfirst( $source ) : 'Candidature directe' ),
		), true );
		if ( '' !== $offer ) {
			$out .= '<div class="bo-section">' . $offer . '</div>';
		}
		$out .= Ui::card_close();

		if ( '' !== $company ) {
			$out .= Ui::card_open( 'Entreprise', '', '', 'bo-card--aside' ) . Ui::entity( $company, 'Entreprise destinataire', '', true ) . Ui::card_close();
		}

		$out .= Ui::card_open( 'Statut', '', '', 'bo-card--aside' ) . Ui::kv_present( array(
			'Étape'     => Ui::html( Ui::badge( $meta[0], $meta[1], true ) ),
			'Entretien' => ! empty( $a['has_interview'] ) ? Ui::html( Ui::badge( 'Planifié', 'success', true ) ) : '',
			'Décision'  => 'Réservée à l\'entreprise',
		), true ) . Ui::details( 'Détails techniques', Ui::kv( array(
			'Version de l\'offre' => Ui::text( (string) (int) ( $a['job_revision'] ?? 0 ), false, true ),
			'Référence'           => Ui::text( $uuid, false, true ),
		), true ) ) . Ui::card_close();

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
