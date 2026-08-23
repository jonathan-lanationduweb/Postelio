<?php
/**
 * Logique métier des entretiens.
 *
 * Contexte OBLIGATOIRE : un entretien est lié à une candidature valide (via
 * ApplicationDirectory) d'une offre de l'entreprise du recruteur (CompanyDirectory).
 * Aucun accès hors périmètre → 404 (non-divulgation), comme candidatures/messagerie.
 *
 * L'état de la candidature fait autorité côté postelio-applications ; le pipeline passe à
 * `interview` à la première proposition valide via ApplicationDirectory::move_to_interview
 * (best-effort — ne bloque pas la création de l'entretien).
 *
 * @package Postelio\Interviews\Interviews
 */

namespace Postelio\Interviews\Interviews;

use Postelio\Applications\Api\ApplicationDirectory;
use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Core\ApiError;
use Postelio\Core\Plugin as Core;
use Postelio\Jobs\Api\JobDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class InterviewService {

	public const ROLE_CANDIDATE = 'candidate';
	public const ROLE_RECRUITER = 'recruiter';
	public const ROLE_SYSTEM    = 'system';

	/** États d'offre interdisant une NOUVELLE proposition d'entretien (décision V1 §C). */
	private const JOB_STATES_BLOCKING_NEW = array( 'filled', 'archived', 'suspended' );

	private InterviewRepository $repo;
	private InterviewHistoryRepository $history;

	public function __construct( InterviewRepository $repo, InterviewHistoryRepository $history ) {
		$this->repo    = $repo;
		$this->history = $history;
	}

	public function repository(): InterviewRepository {
		return $this->repo;
	}
	public function history(): InterviewHistoryRepository {
		return $this->history;
	}

	// --- Accès / périmètre ---------------------------------------------------

	/** Rôle de l'utilisateur sur l'entretien, ou null si aucun accès. */
	public function access_role( int $user_id, array $iv ): ?string {
		if ( $user_id > 0 && (int) $iv['candidate_user_id'] === $user_id ) {
			return self::ROLE_CANDIDATE;
		}
		if ( CompanyDirectory::is_member( (int) $iv['company_id'], $user_id ) ) {
			return self::ROLE_RECRUITER;
		}
		return null;
	}

	/**
	 * @return array{interview: array<string,mixed>, role:string}
	 * @throws ApiError
	 */
	public function accessible_or_fail( int $user_id, string $uuid ): array {
		$iv = $this->repo->get_by_uuid( $uuid );
		if ( null === $iv ) {
			throw ApiError::not_found();
		}
		$role = $this->access_role( $user_id, $iv );
		if ( null === $role ) {
			throw ApiError::not_found(); // non-divulgation
		}
		return array( 'interview' => $iv, 'role' => $role );
	}

	// --- Proposition (recruteur) ---------------------------------------------

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 * @throws ApiError
	 */
	public function propose( int $recruiter_id, string $application_uuid, array $input ): array {
		// Compte suspendu : aucune écriture sensible (jetons déjà révoqués à la suspension).
		if ( class_exists( '\\Postelio\\Users\\Api\\UserDirectory' )
			&& ! \Postelio\Users\Api\UserDirectory::is_active( $recruiter_id ) ) {
			throw ApiError::forbidden( 'Action indisponible pour ce compte.' );
		}
		$ctx = ApplicationDirectory::context( $application_uuid );
		if ( null === $ctx || ! CompanyDirectory::is_member( (int) $ctx['company_id'], $recruiter_id ) ) {
			throw ApiError::not_found();
		}
		if ( ! ApplicationDirectory::is_schedulable( $application_uuid ) ) {
			throw new ApiError( 'conflict', 'La candidature n\'est pas dans un état permettant de planifier un entretien.' );
		}

		// §C — l'offre doit être « ouverte ». filled/archived/suspended bloquent une
		// NOUVELLE proposition ; published/expiring/expired restent autorisés tant que la
		// candidature est active. Statut lu via le contrat public de postelio-jobs.
		$job_status = JobDirectory::status( (int) $ctx['job_id'] );
		if ( null !== $job_status && in_array( $job_status, self::JOB_STATES_BLOCKING_NEW, true ) ) {
			throw new ApiError( 'conflict', 'L\'offre n\'est plus ouverte : impossible de proposer un nouvel entretien.' );
		}

		$app_id  = $this->application_internal_id( $application_uuid );
		$payload = $this->build_payload( $input, (int) $ctx['company_id'], null );

		// §B — plusieurs entretiens successifs autorisés ; on refuse seulement le doublon
		// actif strictement identique (même candidature + créneau + type).
		if ( $app_id > 0 && $this->repo->has_active_duplicate( $app_id, (string) $payload['scheduled_at'], (string) $payload['type'] ) ) {
			throw new ApiError( 'conflict', 'Un entretien identique (même créneau et même type) est déjà en cours pour cette candidature.' );
		}

		$id = $this->repo->insert( array_merge(
			$payload,
			array(
				'application_id'    => $app_id,
				'application_uuid'  => $application_uuid,
				'job_uuid'          => $ctx['job_uuid'] ?? null,
				'candidate_user_id' => (int) $ctx['candidate_user_id'],
				'company_id'        => (int) $ctx['company_id'],
				'company_uuid'      => $ctx['company_uuid'] ?? null,
				'created_by'        => $recruiter_id,
				'status'            => InterviewStateMachine::PROPOSED,
			)
		) );

		$iv = $this->repo->get( $id );
		$this->log( $iv, $recruiter_id, self::ROLE_RECRUITER, 'created', null, InterviewStateMachine::PROPOSED );

		// Pipeline candidature → interview (best-effort ; n'invalide pas l'entretien).
		try {
			ApplicationDirectory::move_to_interview( $application_uuid, $recruiter_id );
		} catch ( \Throwable $e ) {
			// Transition non applicable (déjà en interview, etc.) : ignoré volontairement.
		}

		$this->emit( 'interview.proposed', $iv, $recruiter_id );
		return $iv;
	}

	// --- Actions candidat ----------------------------------------------------

	public function confirm( int $candidate_id, string $uuid ): array {
		$iv = $this->candidate_interview_or_fail( $candidate_id, $uuid );
		if ( ! InterviewStateMachine::candidate_can_answer( (string) $iv['status'] ) ) {
			throw new ApiError( 'invalid_transition', 'Cet entretien ne peut pas être confirmé dans son état actuel.' );
		}
		$this->repo->update( (int) $iv['id'], array(
			'status'                => InterviewStateMachine::CONFIRMED,
			'candidate_response_at' => current_time( 'mysql', true ),
		) );
		$fresh = $this->repo->get( (int) $iv['id'] );
		$this->log( $fresh, $candidate_id, self::ROLE_CANDIDATE, 'confirmed', (string) $iv['status'], InterviewStateMachine::CONFIRMED );
		$this->emit( 'interview.confirmed', $fresh, $candidate_id );
		return $fresh;
	}

	public function decline( int $candidate_id, string $uuid, string $message = '' ): array {
		$iv = $this->candidate_interview_or_fail( $candidate_id, $uuid );
		if ( ! InterviewStateMachine::candidate_can_answer( (string) $iv['status'] ) ) {
			throw new ApiError( 'invalid_transition', 'Cet entretien ne peut pas être refusé dans son état actuel.' );
		}
		$this->repo->update( (int) $iv['id'], array(
			'status'                => InterviewStateMachine::DECLINED,
			'candidate_response_at' => current_time( 'mysql', true ),
		) );
		$fresh = $this->repo->get( (int) $iv['id'] );
		$meta  = '' !== trim( $message ) ? array( 'has_message' => true ) : array();
		$this->log( $fresh, $candidate_id, self::ROLE_CANDIDATE, 'declined', (string) $iv['status'], InterviewStateMachine::DECLINED, $meta );
		$this->emit( 'interview.declined', $fresh, $candidate_id );
		return $fresh;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function request_reschedule( int $candidate_id, string $uuid, array $input ): array {
		$iv = $this->candidate_interview_or_fail( $candidate_id, $uuid );
		if ( ! InterviewStateMachine::candidate_can_reschedule( (string) $iv['status'] ) ) {
			throw new ApiError( 'invalid_transition', 'Aucun autre créneau ne peut être proposé dans cet état.' );
		}
		$slot = InterviewValidator::validate_slot(
			(string) ( $input['scheduled_at'] ?? '' ),
			(string) $iv['timezone'],
			(int) $iv['duration_minutes'],
			time()
		);
		if ( ! $slot['ok'] ) {
			throw ApiError::validation( $slot['errors'] );
		}
		$message = isset( $input['message'] ) ? sanitize_textarea_field( (string) $input['message'] ) : '';
		$this->repo->update( (int) $iv['id'], array(
			'status'                => InterviewStateMachine::RESCHEDULE_REQUESTED,
			'proposed_scheduled_at' => $slot['scheduled_at'],
			'proposed_by'           => $candidate_id,
			'proposed_message'      => '' !== $message ? $message : null,
			'candidate_response_at' => current_time( 'mysql', true ),
		) );
		$fresh = $this->repo->get( (int) $iv['id'] );
		$this->log( $fresh, $candidate_id, self::ROLE_CANDIDATE, 'reschedule_requested', (string) $iv['status'], InterviewStateMachine::RESCHEDULE_REQUESTED, array( 'has_message' => '' !== $message ) );
		$this->emit( 'interview.reschedule_requested', $fresh, $candidate_id );
		return $fresh;
	}

	/**
	 * Annulation par le **candidat** d'un entretien déjà confirmé (ou en attente de
	 * re-créneau). Décision V1 : autorisée, ownership strict, e-mail vérifié requis (route).
	 * Aucun hard-delete : passage à `cancelled`, historique conservé, acteur = candidat.
	 */
	public function cancel_by_candidate( int $candidate_id, string $uuid, string $reason = '' ): array {
		$iv = $this->candidate_interview_or_fail( $candidate_id, $uuid );
		if ( ! InterviewStateMachine::candidate_can_cancel( (string) $iv['status'] ) ) {
			throw new ApiError( 'invalid_transition', 'Cet entretien ne peut pas être annulé dans son état actuel.' );
		}
		$this->repo->update( (int) $iv['id'], array(
			'status'                => InterviewStateMachine::CANCELLED,
			'cancelled_at'          => current_time( 'mysql', true ),
			'candidate_response_at' => current_time( 'mysql', true ),
		) );
		$fresh = $this->repo->get( (int) $iv['id'] );
		$this->log( $fresh, $candidate_id, self::ROLE_CANDIDATE, 'cancelled', (string) $iv['status'], InterviewStateMachine::CANCELLED, '' !== trim( $reason ) ? array( 'has_reason' => true, 'by' => 'candidate' ) : array( 'by' => 'candidate' ) );
		$this->emit( 'interview.cancelled', $fresh, $candidate_id );
		return $fresh;
	}

	// --- Actions recruteur ---------------------------------------------------

	/** Le recruteur accepte le créneau proposé par le candidat (reschedule_requested → confirmed). */
	public function accept_reschedule( int $recruiter_id, string $uuid ): array {
		$acc = $this->accessible_or_fail( $recruiter_id, $uuid );
		if ( self::ROLE_RECRUITER !== $acc['role'] ) {
			throw ApiError::forbidden();
		}
		$iv = $acc['interview'];
		if ( InterviewStateMachine::RESCHEDULE_REQUESTED !== (string) $iv['status'] || empty( $iv['proposed_scheduled_at'] ) ) {
			throw new ApiError( 'invalid_transition', 'Aucune proposition de créneau à accepter.' );
		}
		$this->repo->update( (int) $iv['id'], array(
			'status'                => InterviewStateMachine::CONFIRMED,
			'scheduled_at'          => (string) $iv['proposed_scheduled_at'],
			'proposed_scheduled_at' => null,
			'proposed_by'           => null,
			'proposed_message'      => null,
		) );
		$fresh = $this->repo->get( (int) $iv['id'] );
		$this->log( $fresh, $recruiter_id, self::ROLE_RECRUITER, 'rescheduled', InterviewStateMachine::RESCHEDULE_REQUESTED, InterviewStateMachine::CONFIRMED, array( 'accepted_candidate_slot' => true ) );
		$this->emit( 'interview.rescheduled', $fresh, $recruiter_id );
		return $fresh;
	}

	/**
	 * Modification par le recruteur (créneau, type, lieu, visio, instructions). Une
	 * modification **substantielle** (date/heure/type/lieu/lien) d'un entretien déjà
	 * confirmé le renvoie en `proposed` (nouvelle confirmation candidat requise).
	 *
	 * @param array<string, mixed> $input
	 */
	public function modify( int $recruiter_id, string $uuid, array $input ): array {
		$acc = $this->accessible_or_fail( $recruiter_id, $uuid );
		if ( self::ROLE_RECRUITER !== $acc['role'] ) {
			throw ApiError::forbidden();
		}
		$iv = $acc['interview'];
		if ( InterviewStateMachine::is_terminal( (string) $iv['status'] ) ) {
			throw new ApiError( 'invalid_transition', 'Un entretien terminé ne peut plus être modifié.' );
		}

		$payload     = $this->build_payload( $input, (int) $iv['company_id'], $iv );
		$substantial = $this->is_substantial_change( $iv, $payload );

		$fields = $payload;
		$prev   = (string) $iv['status'];
		$to     = $prev;
		if ( $substantial && in_array( $prev, array( InterviewStateMachine::CONFIRMED, InterviewStateMachine::RESCHEDULE_REQUESTED ), true ) ) {
			$to                             = InterviewStateMachine::PROPOSED;
			$fields['status']               = InterviewStateMachine::PROPOSED;
			$fields['candidate_response_at'] = null;
			$fields['proposed_scheduled_at'] = null;
			$fields['proposed_by']           = null;
			$fields['proposed_message']      = null;
		}
		$this->repo->update( (int) $iv['id'], $fields );
		$fresh = $this->repo->get( (int) $iv['id'] );

		$action = $substantial ? 'rescheduled' : 'modified';
		$this->log( $fresh, $recruiter_id, self::ROLE_RECRUITER, $action, $prev, $to, array( 'substantial' => $substantial ) );
		if ( $substantial ) {
			$this->emit( 'interview.rescheduled', $fresh, $recruiter_id );
		}
		return $fresh;
	}

	public function cancel( int $recruiter_id, string $uuid, string $reason = '' ): array {
		$acc = $this->accessible_or_fail( $recruiter_id, $uuid );
		if ( self::ROLE_RECRUITER !== $acc['role'] ) {
			throw ApiError::forbidden();
		}
		$iv = $acc['interview'];
		if ( InterviewStateMachine::is_terminal( (string) $iv['status'] ) ) {
			throw new ApiError( 'invalid_transition', 'Cet entretien est déjà terminé.' );
		}
		$this->repo->update( (int) $iv['id'], array(
			'status'       => InterviewStateMachine::CANCELLED,
			'cancelled_at' => current_time( 'mysql', true ),
		) );
		$fresh = $this->repo->get( (int) $iv['id'] );
		$this->log( $fresh, $recruiter_id, self::ROLE_RECRUITER, 'cancelled', (string) $iv['status'], InterviewStateMachine::CANCELLED, '' !== trim( $reason ) ? array( 'has_reason' => true ) : array() );
		$this->emit( 'interview.cancelled', $fresh, $recruiter_id );
		return $fresh;
	}

	public function complete( int $recruiter_id, string $uuid ): array {
		$acc = $this->accessible_or_fail( $recruiter_id, $uuid );
		$is_admin = function_exists( 'current_user_can' ) && current_user_can( 'pst_manage_all_jobs' );
		if ( self::ROLE_RECRUITER !== $acc['role'] && ! $is_admin ) {
			throw ApiError::forbidden();
		}
		$iv = $acc['interview'];
		if ( ! InterviewStateMachine::can_transition( (string) $iv['status'], InterviewStateMachine::COMPLETED ) ) {
			throw new ApiError( 'invalid_transition', 'Seul un entretien confirmé peut être marqué comme réalisé.' );
		}
		$this->repo->set_status( (int) $iv['id'], InterviewStateMachine::COMPLETED );
		$fresh = $this->repo->get( (int) $iv['id'] );
		$this->log( $fresh, $recruiter_id, self::ROLE_RECRUITER, 'completed', (string) $iv['status'], InterviewStateMachine::COMPLETED );
		$this->emit( 'interview.completed', $fresh, $recruiter_id );
		return $fresh;
	}

	// --- Listes --------------------------------------------------------------

	/**
	 * @param array<string, mixed> $filters
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	public function list_for_candidate( int $candidate_id, array $filters, int $page, int $per_page ): array {
		return $this->repo->list( 'candidate', $candidate_id, $filters, $page, $per_page );
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array{items: array<int, array<string,mixed>>, total:int}
	 */
	public function list_for_company( int $company_id, array $filters, int $page, int $per_page ): array {
		return $this->repo->list( 'company', $company_id, $filters, $page, $per_page );
	}

	// --- Helpers -------------------------------------------------------------

	/** @throws ApiError */
	private function candidate_interview_or_fail( int $candidate_id, string $uuid ): array {
		$acc = $this->accessible_or_fail( $candidate_id, $uuid );
		if ( self::ROLE_CANDIDATE !== $acc['role'] ) {
			throw ApiError::forbidden();
		}
		return $acc['interview'];
	}

	/**
	 * Valide et normalise le payload (créneau UTC, type, données spécifiques, instructions).
	 * En modification, `$existing` fournit les valeurs par défaut.
	 *
	 * @param array<string, mixed>      $input
	 * @param array<string, mixed>|null $existing
	 * @return array<string, mixed>
	 * @throws ApiError
	 */
	private function build_payload( array $input, int $company_id, ?array $existing ): array {
		$errors = array();

		$type = isset( $input['type'] ) ? (string) $input['type'] : (string) ( $existing['type'] ?? '' );
		if ( ! InterviewValidator::valid_type( $type ) ) {
			$errors['type'] = 'Type invalide (video, onsite ou phone).';
		}

		$tz = isset( $input['timezone'] ) && '' !== (string) $input['timezone']
			? (string) $input['timezone']
			: (string) ( $existing['timezone'] ?? $this->default_timezone() );

		$duration = isset( $input['duration_minutes'] )
			? (int) $input['duration_minutes']
			: (int) ( $existing['duration_minutes'] ?? 30 );

		// Créneau : requis à la création ; en modification, conservé si non fourni.
		$scheduled_at = null;
		$slot_given   = isset( $input['scheduled_at'] ) && '' !== (string) $input['scheduled_at'];
		if ( $slot_given || null === $existing ) {
			$slot = InterviewValidator::validate_slot( (string) ( $input['scheduled_at'] ?? '' ), $tz, $duration, time() );
			if ( ! $slot['ok'] ) {
				$errors = array_merge( $errors, $slot['errors'] );
			} else {
				$scheduled_at = $slot['scheduled_at'];
			}
		} else {
			$scheduled_at = (string) $existing['scheduled_at'];
			if ( ! InterviewValidator::valid_duration( $duration ) ) {
				$errors['duration_minutes'] = 'Durée hors bornes.';
			}
		}

		$out = array(
			'type'             => $type,
			'timezone'         => $tz,
			'duration_minutes' => $duration,
			'scheduled_at'     => (string) $scheduled_at,
			'instructions'     => isset( $input['instructions'] ) ? sanitize_textarea_field( (string) $input['instructions'] ) : ( $existing['instructions'] ?? null ),
			// On réinitialise les 3 colonnes spécifiques ; seule celle du type est remplie.
			'location_data'    => null,
			'video_data'       => null,
			'phone_data'       => null,
		);

		if ( InterviewValidator::TYPE_VIDEO === $type ) {
			$src = (array) ( $input['video_data'] ?? $existing['video_data'] ?? array() );
			$url = (string) ( $src['meeting_url'] ?? '' );
			if ( ! InterviewValidator::valid_meeting_url( $url ) ) {
				$errors['video_data.meeting_url'] = 'URL de visioconférence invalide (http/https requis).';
			}
			$out['video_data'] = array(
				'meeting_url' => esc_url_raw( $url ),
				'provider'    => isset( $src['provider'] ) ? sanitize_text_field( (string) $src['provider'] ) : null,
			);
		} elseif ( InterviewValidator::TYPE_ONSITE === $type ) {
			$src     = (array) ( $input['location_data'] ?? $existing['location_data'] ?? array() );
			$summary = CompanyDirectory::public_summary( $company_id );
			$city    = isset( $src['city'] ) && '' !== (string) $src['city']
				? sanitize_text_field( (string) $src['city'] )
				: (string) ( $summary['ville'] ?? '' ); // préremplissage entreprise
			$out['location_data'] = array(
				'address'             => isset( $src['address'] ) ? sanitize_text_field( (string) $src['address'] ) : null,
				'address_complement'  => isset( $src['address_complement'] ) ? sanitize_text_field( (string) $src['address_complement'] ) : null,
				'postal_code'         => isset( $src['postal_code'] ) ? sanitize_text_field( (string) $src['postal_code'] ) : null,
				'city'                => '' !== $city ? $city : null,
				'contact'             => isset( $src['contact'] ) ? sanitize_text_field( (string) $src['contact'] ) : null,
				'access_instructions' => isset( $src['access_instructions'] ) ? sanitize_textarea_field( (string) $src['access_instructions'] ) : null,
			);
			if ( null === $out['location_data']['address'] && null === $out['location_data']['city'] ) {
				$errors['location_data'] = 'Une adresse ou une ville est requise pour un entretien sur place.';
			}
		} elseif ( InterviewValidator::TYPE_PHONE === $type ) {
			$src    = (array) ( $input['phone_data'] ?? $existing['phone_data'] ?? array() );
			$number = isset( $src['phone_number'] ) ? preg_replace( '/[^0-9+ ().-]/', '', (string) $src['phone_number'] ) : '';
			$who    = (string) ( $src['who_calls'] ?? 'recruiter_calls' );
			if ( ! in_array( $who, array( 'recruiter_calls', 'candidate_calls' ), true ) ) {
				$who = 'recruiter_calls';
			}
			if ( '' === $number ) {
				$errors['phone_data.phone_number'] = 'Un numéro de téléphone est requis.';
			}
			$out['phone_data'] = array(
				'phone_number' => $number,
				'who_calls'    => $who,
			);
		}

		if ( $errors ) {
			throw ApiError::validation( $errors );
		}
		return $out;
	}

	/**
	 * Une modification est « substantielle » si elle change le type, le créneau ou les
	 * coordonnées (lieu/lien) — cas qui exige une nouvelle confirmation.
	 *
	 * @param array<string, mixed> $iv
	 * @param array<string, mixed> $payload
	 */
	private function is_substantial_change( array $iv, array $payload ): bool {
		if ( (string) $iv['type'] !== (string) $payload['type'] ) {
			return true;
		}
		if ( (string) $iv['scheduled_at'] !== (string) $payload['scheduled_at'] ) {
			return true;
		}
		$key = array( 'video' => 'video_data', 'onsite' => 'location_data', 'phone' => 'phone_data' )[ (string) $payload['type'] ] ?? null;
		if ( null !== $key ) {
			return wp_json_encode( $iv[ $key ] ?? null ) !== wp_json_encode( $payload[ $key ] ?? null );
		}
		return false;
	}

	private function default_timezone(): string {
		$tz = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : 'UTC';
		return InterviewValidator::valid_timezone( $tz ) ? $tz : 'UTC';
	}

	private function application_internal_id( string $application_uuid ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'postelio_applications';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE public_uuid = %s", $application_uuid ) );
	}

	/**
	 * @param array<string, mixed> $iv
	 * @param array<string, mixed> $meta
	 */
	private function log( array $iv, ?int $actor, string $actor_role, string $action, ?string $from, ?string $to, array $meta = array() ): void {
		$this->history->add( (int) $iv['id'], (string) $iv['public_uuid'], $actor, $actor_role, $action, $from, $to, $meta );
	}

	/**
	 * Émet un événement + audit minimal (jamais d'instructions/coordonnées privées).
	 *
	 * @param array<string, mixed> $iv
	 */
	private function emit( string $event, array $iv, int $actor_user_id = 0 ): void {
		Core::instance()->events()->emit(
			$event,
			array(
				'interview_uuid'    => (string) $iv['public_uuid'],
				'application_uuid'  => (string) $iv['application_uuid'],
				'candidate_user_id' => (int) $iv['candidate_user_id'],
				'company_id'        => (int) $iv['company_id'],
				'job_uuid'          => $iv['job_uuid'],
				'scheduled_at'      => (string) $iv['scheduled_at'],
				'type'              => (string) $iv['type'],
				'actor_user_id'     => $actor_user_id, // Lot 09 : permet d'exclure l'acteur du destinataire
				'resource_type'     => 'interview',
				'resource_id'       => (string) $iv['id'],
				'audit'             => array(
					'interview_uuid'   => (string) $iv['public_uuid'],
					'application_uuid' => (string) $iv['application_uuid'],
					'company_id'       => (int) $iv['company_id'],
					'type'             => (string) $iv['type'],
					'status'           => (string) $iv['status'],
				),
			)
		);
	}
}
