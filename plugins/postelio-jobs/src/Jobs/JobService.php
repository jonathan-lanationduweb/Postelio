<?php
/**
 * Logique métier des offres : brouillon, édition, publication (conditionnée à une
 * entreprise vérifiée — D1), cycle de vie (fill/archive/suspend), duplication.
 *
 * Réutilise les contrats publics de postelio-companies :
 *   - CompanyDirectory  : appartenance recruteur ↔ entreprise, identités ;
 *   - CompanyVerification::can_publish_jobs() : règle D1 (jamais de lecture interne).
 *
 * @package Postelio\Jobs\Jobs
 */

namespace Postelio\Jobs\Jobs;

use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Companies\Api\CompanyVerification;
use Postelio\Core\ApiError;
use Postelio\Core\Plugin as Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobService {

	/** Durée de publication par défaut (jours) et fenêtre « bientôt expiré ». */
	public const PUBLISH_DAYS  = 30;
	public const EXPIRING_DAYS = 7;

	private JobRepository $jobs;

	public function __construct( JobRepository $jobs ) {
		$this->jobs = $jobs;
	}

	/**
	 * Crée une offre en BROUILLON pour l'entreprise du recruteur courant.
	 * Autorisé même si l'entreprise n'est pas vérifiée (D1).
	 *
	 * @param array<string, mixed> $input
	 * @throws ApiError
	 */
	public function create( int $actor_id, array $input ): int {
		$company_id = CompanyDirectory::company_of_user( $actor_id );
		if ( 0 === $company_id ) {
			throw new ApiError( 'conflict', 'Aucune entreprise rattachée : créez d\'abord votre entreprise.' );
		}
		$titre = sanitize_text_field( (string) ( $input['titre'] ?? '' ) );
		if ( '' === $titre ) {
			throw ApiError::validation( array( 'titre' => 'Intitulé requis.' ) );
		}
		$company = array(
			'id'   => $company_id,
			'uuid' => (string) CompanyDirectory::uuid_of( $company_id ),
			'nom'  => (string) CompanyDirectory::name_of( $company_id ),
		);
		$description = wp_kses_post( (string) ( $input['description'] ?? '' ) );
		$id          = $this->jobs->create( $company, $actor_id, $titre, $description, $this->clean( $input ) );
		if ( 0 === $id ) {
			throw new ApiError( 'server_error', 'Création de l\'offre impossible.' );
		}
		$this->emit( 'job.created', $id, $company_id, array( 'status' => JobStateMachine::DRAFT ) );
		return $id;
	}

	/**
	 * Édition d'une offre (hors états terminaux).
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function update( int $actor_id, int $job_id, array $input ): array {
		$job = $this->owned_or_fail( $actor_id, $job_id );
		if ( in_array( $job['status'], array( JobStateMachine::ARCHIVED, JobStateMachine::SUSPENDED ), true ) ) {
			throw new ApiError( 'invalid_transition', 'Offre non modifiable dans l\'état « ' . $job['status'] . ' ».' );
		}
		if ( array_key_exists( 'titre', $input ) || array_key_exists( 'description', $input ) ) {
			$this->jobs->update_title_description(
				$job_id,
				array_key_exists( 'titre', $input ) ? sanitize_text_field( (string) $input['titre'] ) : null,
				array_key_exists( 'description', $input ) ? wp_kses_post( (string) $input['description'] ) : null
			);
		}
		$this->jobs->write_fields( $job_id, $this->clean( $input ) );
		$this->emit( 'job.updated', $job_id, (int) $job['company']['id'] );
		return $this->jobs->get( $job_id );
	}

	/**
	 * Publication publique : exige une entreprise `verified` (D1).
	 *
	 * @return array<string, mixed>
	 */
	public function publish( int $actor_id, int $job_id ): array {
		$job     = $this->owned_or_fail( $actor_id, $job_id );
		$company = (int) $job['company']['id'];

		$this->guard_transition( $job['status'], JobStateMachine::PUBLISHED );

		if ( ! CompanyVerification::can_publish_jobs( $company ) ) {
			throw ApiError::forbidden( 'Publication impossible : l\'entreprise doit être vérifiée.' );
		}

		$today = current_time( 'Y-m-d' );
		$exp   = gmdate( 'Y-m-d', strtotime( $today . ' +' . self::PUBLISH_DAYS . ' days' ) );
		$this->jobs->set_status( $job_id, JobStateMachine::PUBLISHED, array( 'date_publication' => $today, 'date_expiration' => $exp ) );
		$this->emit( 'job.published', $job_id, $company, array( 'date_expiration' => $exp ) );
		return $this->jobs->get( $job_id );
	}

	/** Marque l'offre pourvue. @return array<string, mixed> */
	public function fill( int $actor_id, int $job_id ): array {
		$job = $this->owned_or_fail( $actor_id, $job_id );
		$this->guard_transition( $job['status'], JobStateMachine::FILLED );
		$this->jobs->set_status( $job_id, JobStateMachine::FILLED );
		$this->emit( 'job.filled', $job_id, (int) $job['company']['id'] );
		return $this->jobs->get( $job_id );
	}

	/** Archive l'offre. @return array<string, mixed> */
	public function archive( int $actor_id, int $job_id ): array {
		$job = $this->owned_or_fail( $actor_id, $job_id );
		$this->guard_transition( $job['status'], JobStateMachine::ARCHIVED );
		$this->jobs->set_status( $job_id, JobStateMachine::ARCHIVED );
		$this->emit( 'job.archived', $job_id, (int) $job['company']['id'] );
		return $this->jobs->get( $job_id );
	}

	/**
	 * Duplique une offre en un NOUVEAU brouillon.
	 *
	 * @return array<string, mixed>
	 */
	public function duplicate( int $actor_id, int $job_id ): array {
		$job     = $this->owned_or_fail( $actor_id, $job_id );
		$company = array(
			'id'   => (int) $job['company']['id'],
			'uuid' => (string) $job['company']['uuid'],
			'nom'  => (string) $job['company']['nom'],
		);
		$fields = array();
		foreach ( array( 'ville', 'departement', 'contrat', 'teletravail', 'categorie', 'niveau_etude', 'experience', 'salaire_annuel', 'alternance', 'stage', 'debutant' ) as $k ) {
			if ( isset( $job[ $k ] ) ) {
				$fields[ $k ] = $job[ $k ];
			}
		}
		$fields = array_merge( $fields, is_array( $job['detail'] ) ? $job['detail'] : array() );

		$new_id = $this->jobs->create( $company, $actor_id, $job['titre'] . ' (copie)', (string) $job['description'], $fields );
		if ( 0 === $new_id ) {
			throw new ApiError( 'server_error', 'Duplication impossible.' );
		}
		$this->emit( 'job.created', $new_id, $company['id'], array( 'status' => JobStateMachine::DRAFT, 'duplicated_from' => $job['uuid'] ) );
		return $this->jobs->get( $new_id );
	}

	/**
	 * Décision admin : suspend | published (réactivation). @return array<string,mixed>
	 */
	public function admin_transition( int $admin_id, int $job_id, string $decision ): array {
		$job = $this->jobs->get( $job_id );
		if ( null === $job ) {
			throw ApiError::not_found();
		}
		if ( ! in_array( $decision, array( JobStateMachine::SUSPENDED, JobStateMachine::PUBLISHED ), true ) ) {
			throw ApiError::validation( array( 'decision' => 'Décision admin invalide.' ) );
		}
		$this->guard_transition( $job['status'], $decision );
		if ( JobStateMachine::PUBLISHED === $decision && ! CompanyVerification::can_publish_jobs( (int) $job['company']['id'] ) ) {
			throw ApiError::forbidden( 'Entreprise non vérifiée.' );
		}
		$this->jobs->set_status( $job_id, $decision );
		$this->emit( JobStateMachine::SUSPENDED === $decision ? 'job.suspended' : 'job.published', $job_id, (int) $job['company']['id'], array( 'by' => 'admin' ) );
		return $this->jobs->get( $job_id );
	}

	// --- Helpers -----------------------------------------------------------

	/** @return array<string, mixed> */
	private function owned_or_fail( int $actor_id, int $job_id ): array {
		$job = $this->jobs->get( $job_id );
		if ( null === $job ) {
			throw ApiError::not_found();
		}
		$company = (int) $job['company']['id'];
		$is_admin = function_exists( 'current_user_can' ) && current_user_can( 'pst_manage_all_jobs' );
		if ( ! $is_admin && ! CompanyDirectory::is_member( $company, $actor_id ) ) {
			throw ApiError::forbidden( 'Vous ne gérez pas cette offre.' );
		}
		return $job;
	}

	private function guard_transition( string $from, string $to ): void {
		if ( ! JobStateMachine::can_transition( $from, $to ) ) {
			throw new ApiError( 'invalid_transition', 'Transition « ' . $from . ' → ' . $to . ' » non autorisée.' );
		}
	}

	/**
	 * Nettoie/normalise les champs entrants (whitelist).
	 *
	 * @param array<string, mixed> $in
	 * @return array<string, mixed>
	 */
	private function clean( array $in ): array {
		$out = array();
		foreach ( array( 'ville', 'departement', 'contrat', 'teletravail', 'categorie', 'niveau_etude', 'experience', 'duree', 'temps_travail', 'salaire', 'resume', 'categorie_label', 'niveau_etude_label', 'experience_label', 'email_reception' ) as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$out[ $k ] = sanitize_text_field( (string) $in[ $k ] );
			}
		}
		if ( array_key_exists( 'salaire_annuel', $in ) ) {
			$out['salaire_annuel'] = (int) $in['salaire_annuel'];
		}
		foreach ( array( 'alternance', 'stage', 'debutant' ) as $flag ) {
			if ( array_key_exists( $flag, $in ) ) {
				$out[ $flag ] = (bool) $in[ $flag ];
			}
		}
		foreach ( array( 'missions', 'profil', 'competences', 'avantages', 'questions_preselection', 'processus' ) as $list ) {
			if ( array_key_exists( $list, $in ) && is_array( $in[ $list ] ) ) {
				$out[ $list ] = array_values( array_map( 'sanitize_text_field', array_map( 'strval', $in[ $list ] ) ) );
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $audit
	 */
	private function emit( string $event, int $job_id, int $company_id, array $audit = array() ): void {
		Core::instance()->events()->emit(
			$event,
			array(
				'job_id'        => $job_id,
				'company_id'    => $company_id,
				'resource_type' => 'job',
				'resource_id'   => (string) $job_id,
				'audit'         => $audit,
			)
		);
	}
}
