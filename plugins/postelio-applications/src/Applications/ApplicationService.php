<?php
/**
 * Logique métier des candidatures.
 *
 * Réutilise les contrats publics : JobDirectory (candidatabilité + snapshot),
 * CompanyDirectory (appartenance recruteur), UserDirectory (compte candidat actif).
 * Aucune lecture directe des meta d'autres plugins.
 *
 * Stratégie d'accès : toute ressource hors périmètre de l'acteur renvoie 404
 * (non-divulgation) — connaître l'UUID ne donne aucun droit.
 *
 * @package Postelio\Applications\Applications
 */

namespace Postelio\Applications\Applications;

use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Core\ApiError;
use Postelio\Core\Plugin as Core;
use Postelio\Jobs\Api\JobDirectory;
use Postelio\Users\Api\UserDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApplicationService {

	private ApplicationRepository $apps;
	private HistoryRepository $history;
	private NoteRepository $notes;

	/** Événement spécifique par statut cible. */
	private const EVENT_BY_STATUS = array(
		ApplicationStateMachine::REVIEW      => 'application.reviewed',
		ApplicationStateMachine::SHORTLISTED => 'application.shortlisted',
		ApplicationStateMachine::INTERVIEW   => 'application.interview',
		ApplicationStateMachine::SELECTED    => 'application.selected',
		ApplicationStateMachine::REJECTED    => 'application.rejected',
		ApplicationStateMachine::WITHDRAWN   => 'application.withdrawn',
	);

	public function __construct( ApplicationRepository $apps, HistoryRepository $history, NoteRepository $notes ) {
		$this->apps    = $apps;
		$this->history = $history;
		$this->notes   = $notes;
	}

	public function repository(): ApplicationRepository {
		return $this->apps;
	}
	public function history(): HistoryRepository {
		return $this->history;
	}
	public function notes(): NoteRepository {
		return $this->notes;
	}

	/**
	 * Postuler à une offre.
	 *
	 * @param array<string, mixed> $input cv_reference?, message?, screening_answers?{id:val}
	 * @throws ApiError
	 */
	public function apply( int $candidate_id, string $job_uuid, array $input ): array {
		if ( ! UserDirectory::is_active( $candidate_id ) ) {
			throw ApiError::forbidden( 'Compte indisponible.' );
		}
		$job_id = JobDirectory::id_from_uuid( $job_uuid );
		if ( 0 === $job_id ) {
			throw ApiError::not_found( 'Offre introuvable.' );
		}
		if ( ! JobDirectory::is_candidateable( $job_id ) ) {
			throw new ApiError( 'invalid_transition', 'Cette offre n\'accepte pas (ou plus) de candidatures.' );
		}
		if ( $this->apps->exists_for_job_candidate( $job_id, $candidate_id ) ) {
			throw new ApiError( 'conflict', 'Vous avez déjà candidaté à cette offre.' );
		}

		$snap = JobDirectory::application_snapshot( $job_id );
		if ( null === $snap ) {
			throw ApiError::not_found( 'Offre introuvable.' );
		}

		// Réponses de présélection validées CONTRE le snapshot serveur.
		$screening = ScreeningValidator::validate(
			$snap['questions_preselection'],
			(array) ( $input['screening_answers'] ?? array() )
		);
		if ( ! empty( $screening['errors'] ) ) {
			throw ApiError::validation( $screening['errors'], 'Réponses de présélection incomplètes ou invalides.' );
		}

		$job_snapshot = array(
			'job_uuid'               => $snap['job_uuid'],
			'revision'               => $snap['revision'],
			'titre'                  => $snap['titre'],
			'company_uuid'           => $snap['company_uuid'],
			'company_name'           => $snap['company_name'],
			'questions_preselection' => $snap['questions_preselection'],
		);

		$id = $this->apps->insert(
			array(
				'candidate_user_id' => $candidate_id,
				'job_id'            => $job_id,
				'job_uuid'          => $snap['job_uuid'],
				'company_id'        => $snap['company_id'],
				'company_uuid'      => $snap['company_uuid'],
				'status'            => ApplicationStateMachine::NEW,
				'cv_reference'      => $this->resolve_cv_reference( $candidate_id, $input ),
				'job_revision'      => $snap['revision'],
				'job_snapshot'      => $job_snapshot,
				'screening_answers' => $screening['answers'],
				'candidate_message' => isset( $input['message'] ) ? sanitize_textarea_field( (string) $input['message'] ) : null,
			)
		);
		if ( 0 === $id ) {
			// Course concurrente : la contrainte unique a joué.
			throw new ApiError( 'conflict', 'Vous avez déjà candidaté à cette offre.' );
		}

		$this->history->add( $id, array( 'action' => 'created', 'to_status' => ApplicationStateMachine::NEW, 'actor_id' => $candidate_id, 'actor_role' => 'candidate' ) );
		$this->emit( 'application.created', $id, $candidate_id, (int) $snap['company_id'], (int) $job_id, array( 'job_uuid' => $snap['job_uuid'] ) );

		return $this->apps->get( $id );
	}

	/**
	 * Résout et VERROUILLE la référence de CV via le contrat postelio-files.
	 *
	 * Le fichier étant immuable, référencer son UUID suffit à garantir le « snapshot »
	 * (le CV vu par le recruteur reste celui du moment de la candidature). Si files
	 * n'est pas actif, on retombe sur une référence opaque (compatibilité).
	 *
	 * @param array<string, mixed> $input
	 * @throws ApiError validation_error
	 */
	private function resolve_cv_reference( int $candidate_id, array $input ): ?string {
		$uuid = (string) ( $input['cv_uuid'] ?? $input['cv_reference'] ?? '' );
		if ( '' === $uuid ) {
			return null; // CV facultatif en V1 (À VALIDER)
		}
		if ( class_exists( '\\Postelio\\Files\\Api\\FileCvContract' ) ) {
			if ( ! \Postelio\Files\Api\FileCvContract::usable_for_application( $uuid, $candidate_id ) ) {
				throw ApiError::validation( array( 'cv' => 'CV invalide : inexistant, non actif ou n\'appartenant pas à votre compte.' ) );
			}
			return $uuid;
		}
		return sanitize_text_field( $uuid ); // files inactif : référence opaque
	}

	/**
	 * Changement de statut par un recruteur de l'entreprise.
	 *
	 * @param array<string, mixed> $input to (statut), reason?, position?
	 * @return array<string, mixed>
	 */
	public function change_status( int $recruiter_id, string $app_uuid, array $input ): array {
		$app = $this->recruiter_scope_or_fail( $recruiter_id, $app_uuid );
		$to  = (string) ( $input['to'] ?? $input['status'] ?? '' );

		if ( ! in_array( $to, ApplicationStateMachine::RECRUITER_TARGETS, true ) ) {
			throw ApiError::validation( array( 'to' => 'Statut cible invalide pour un recruteur.' ) );
		}
		if ( ! ApplicationStateMachine::can_transition( $app['status'], $to ) ) {
			throw new ApiError( 'invalid_transition', 'Transition « ' . $app['status'] . ' → ' . $to . ' » non autorisée.' );
		}

		$extra = array();
		if ( array_key_exists( 'position', $input ) ) {
			$extra['sort_order'] = (int) $input['position'];
		}
		$this->apps->update_status( (int) $app['id'], $to, $extra );

		// Le motif de refus est INTERNE (jamais exposé au candidat → metadata d'historique
		// lue uniquement en vue recruteur).
		$meta = array();
		if ( ApplicationStateMachine::REJECTED === $to && ! empty( $input['reason'] ) ) {
			$meta['reason'] = sanitize_text_field( (string) $input['reason'] );
		}
		$this->history->add( (int) $app['id'], array(
			'action'      => 'status_changed',
			'from_status' => $app['status'],
			'to_status'   => $to,
			'actor_id'    => $recruiter_id,
			'actor_role'  => 'recruiter',
			'metadata'    => $meta,
		) );

		$this->emit( 'application.status_changed', (int) $app['id'], (int) $app['candidate_user_id'], (int) $app['company_id'], (int) $app['job_id'], array( 'from' => $app['status'], 'to' => $to ) );
		if ( isset( self::EVENT_BY_STATUS[ $to ] ) ) {
			$this->emit( self::EVENT_BY_STATUS[ $to ], (int) $app['id'], (int) $app['candidate_user_id'], (int) $app['company_id'], (int) $app['job_id'], array( 'from' => $app['status'] ) );
		}

		return $this->apps->get( (int) $app['id'] );
	}

	/**
	 * Retrait par le candidat propriétaire.
	 *
	 * @return array<string, mixed>
	 */
	public function withdraw( int $candidate_id, string $app_uuid ): array {
		$app = $this->candidate_scope_or_fail( $candidate_id, $app_uuid );
		if ( ! ApplicationStateMachine::can_transition( $app['status'], ApplicationStateMachine::WITHDRAWN ) ) {
			throw new ApiError( 'invalid_transition', 'Candidature non retirable (statut : ' . $app['status'] . ').' );
		}
		$this->apps->update_status( (int) $app['id'], ApplicationStateMachine::WITHDRAWN, array( 'withdrawn_at' => current_time( 'mysql', true ) ) );
		$this->history->add( (int) $app['id'], array( 'action' => 'withdrawn', 'from_status' => $app['status'], 'to_status' => ApplicationStateMachine::WITHDRAWN, 'actor_id' => $candidate_id, 'actor_role' => 'candidate' ) );
		$this->emit( 'application.status_changed', (int) $app['id'], $candidate_id, (int) $app['company_id'], (int) $app['job_id'], array( 'from' => $app['status'], 'to' => ApplicationStateMachine::WITHDRAWN ) );
		$this->emit( 'application.withdrawn', (int) $app['id'], $candidate_id, (int) $app['company_id'], (int) $app['job_id'], array() );
		return $this->apps->get( (int) $app['id'] );
	}

	/**
	 * Ajoute une note interne (recruteur de l'entreprise).
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function add_note( int $recruiter_id, string $app_uuid, string $body ): array {
		$app  = $this->recruiter_scope_or_fail( $recruiter_id, $app_uuid );
		$body = trim( wp_kses_post( $body ) );
		if ( '' === $body ) {
			throw ApiError::validation( array( 'body' => 'Note vide.' ) );
		}
		$this->notes->add( (int) $app['id'], $recruiter_id, $body );
		$this->history->add( (int) $app['id'], array( 'action' => 'note_added', 'actor_id' => $recruiter_id, 'actor_role' => 'recruiter' ) );
		return $this->notes->list_for_application( (int) $app['id'] );
	}

	// --- Portées / gardes --------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	public function candidate_scope_or_fail( int $candidate_id, string $app_uuid ): array {
		$app = $this->apps->get_by_uuid( $app_uuid );
		if ( null === $app || (int) $app['candidate_user_id'] !== $candidate_id ) {
			throw ApiError::not_found(); // non-divulgation
		}
		return $app;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function recruiter_scope_or_fail( int $recruiter_id, string $app_uuid ): array {
		$app      = $this->apps->get_by_uuid( $app_uuid );
		$is_admin = function_exists( 'current_user_can' ) && current_user_can( 'pst_manage_all_jobs' );
		if ( null === $app || ( ! $is_admin && ! CompanyDirectory::is_member( (int) $app['company_id'], $recruiter_id ) ) ) {
			throw ApiError::not_found(); // non-divulgation (UUID connu ≠ accès)
		}
		return $app;
	}

	private function emit( string $event, int $app_id, int $candidate_id, int $company_id, int $job_id, array $audit ): void {
		// Enrichissement ADDITIF (D1 Lot 09) : on expose les UUID publics nécessaires aux
		// consommateurs (postelio-notifications) sans qu'ils lisent la table applications.
		$app             = $this->apps->get( $app_id );
		$application_uuid = is_array( $app ) ? ( $app['public_uuid'] ?? null ) : null;
		$job_uuid         = is_array( $app ) ? ( $app['job_uuid'] ?? null ) : ( $audit['job_uuid'] ?? null );

		Core::instance()->events()->emit(
			$event,
			array(
				'application_id'   => $app_id,
				'application_uuid' => $application_uuid,
				'candidate_id'     => $candidate_id,
				'company_id'       => $company_id,
				'job_id'           => $job_id,
				'job_uuid'         => $job_uuid,
				'resource_type'    => 'application',
				'resource_id'      => (string) $app_id,
				'audit'            => $audit,
			)
		);
	}
}
