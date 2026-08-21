<?php
/**
 * Logique métier de la messagerie.
 *
 * Contexte OBLIGATOIRE : une conversation est liée à une candidature. Le recruteur ne
 * peut contacter un candidat que via une candidature d'une offre de SON entreprise
 * (ApplicationDirectory + CompanyDirectory). Aucun contact arbitraire par UUID.
 * Accès hors périmètre → 404 (non-divulgation).
 *
 * @package Postelio\Messaging\Conversations
 */

namespace Postelio\Messaging\Conversations;

use Postelio\Applications\Api\ApplicationDirectory;
use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Companies\Members\MembershipRepository;
use Postelio\Core\ApiError;
use Postelio\Core\Plugin as Core;
use Postelio\Users\Api\UserDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MessagingService {

	public const ROLE_CANDIDATE = 'candidate';
	public const ROLE_RECRUITER = 'recruiter';

	private ConversationRepository $conversations;
	private ParticipantRepository $participants;
	private MessageRepository $messages;

	public function __construct( ConversationRepository $conversations, ParticipantRepository $participants, MessageRepository $messages ) {
		$this->conversations = $conversations;
		$this->participants  = $participants;
		$this->messages      = $messages;
	}

	public function conversations(): ConversationRepository {
		return $this->conversations;
	}
	public function messages(): MessageRepository {
		return $this->messages;
	}
	public function participants(): ParticipantRepository {
		return $this->participants;
	}

	/**
	 * Ouvre (ou crée) la conversation d'une candidature — action recruteur.
	 *
	 * @return array<string, mixed>
	 * @throws ApiError
	 */
	public function open_for_application( int $recruiter_id, string $application_uuid ): array {
		$ctx = ApplicationDirectory::context( $application_uuid );
		if ( null === $ctx ) {
			throw ApiError::not_found();
		}
		if ( ! CompanyDirectory::is_member( (int) $ctx['company_id'], $recruiter_id ) ) {
			throw ApiError::not_found(); // non-divulgation
		}

		// app_id interne non fourni par le contexte public : on le retrouve via l'UUID.
		$existing = $this->find_by_application_uuid( $application_uuid );
		if ( null !== $existing ) {
			$this->participants->ensure( (int) $existing['id'], $recruiter_id, self::ROLE_RECRUITER );
			return $existing;
		}

		$id = $this->conversations->insert( array(
			'application_id'    => $this->application_internal_id( $ctx ),
			'application_uuid'  => $application_uuid,
			'job_uuid'          => $ctx['job_uuid'] ?? null,
			'company_id'        => (int) $ctx['company_id'],
			'company_uuid'      => $ctx['company_uuid'] ?? null,
			'company_name'      => $ctx['company_name'] ?? null,
			'subject'           => $ctx['job_title'] ?? null,
			'candidate_user_id' => (int) $ctx['candidate_user_id'],
		) );
		if ( 0 === $id ) {
			// Course concurrente : la conversation a été créée entre-temps.
			$existing = $this->find_by_application_uuid( $application_uuid );
			if ( null !== $existing ) {
				$this->participants->ensure( (int) $existing['id'], $recruiter_id, self::ROLE_RECRUITER );
				return $existing;
			}
			throw new ApiError( 'server_error', 'Création de la conversation impossible.' );
		}

		$this->participants->ensure( $id, (int) $ctx['candidate_user_id'], self::ROLE_CANDIDATE );
		$this->participants->ensure( $id, $recruiter_id, self::ROLE_RECRUITER );
		$this->emit( 'conversation.created', $id, array( 'application_uuid' => $application_uuid, 'company_id' => (int) $ctx['company_id'] ) );
		return $this->conversations->get( $id );
	}

	/**
	 * Rôle de l'utilisateur sur la conversation, ou null si aucun accès.
	 */
	public function access_role( int $user_id, array $conversation ): ?string {
		if ( $user_id > 0 && (int) $conversation['candidate_user_id'] === $user_id ) {
			return self::ROLE_CANDIDATE;
		}
		if ( CompanyDirectory::is_member( (int) $conversation['company_id'], $user_id ) ) {
			return self::ROLE_RECRUITER;
		}
		return null;
	}

	/**
	 * Récupère une conversation accessible (sinon 404). Assure la ligne participant.
	 *
	 * @return array{conversation: array<string,mixed>, role:string}
	 */
	public function accessible_or_fail( int $user_id, string $uuid ): array {
		$c = $this->conversations->get_by_uuid( $uuid );
		if ( null === $c ) {
			throw ApiError::not_found();
		}
		$role = $this->access_role( $user_id, $c );
		if ( null === $role ) {
			throw ApiError::not_found();
		}
		$this->participants->ensure( (int) $c['id'], $user_id, $role );
		return array( 'conversation' => $c, 'role' => $role );
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public function list_for_user( int $user_id ): array {
		if ( UserDirectory::is_candidate( $user_id ) ) {
			return $this->conversations->list_for_candidate( $user_id );
		}
		$company = CompanyDirectory::company_of_user( $user_id );
		return $company > 0 ? $this->conversations->list_for_company( $company ) : array();
	}

	/**
	 * Envoie un message. @return array<string, mixed>
	 * @throws ApiError
	 */
	public function send( int $user_id, string $uuid, string $raw_body ): array {
		$acc  = $this->accessible_or_fail( $user_id, $uuid );
		$c    = $acc['conversation'];
		$role = $acc['role'];

		if ( ! ConversationStateMachine::can_send( (string) $c['status'] ) ) {
			throw new ApiError( 'invalid_transition', 'Conversation fermée : envoi impossible.' );
		}
		$this->rate_limit_or_fail( $user_id );

		$norm = MessageNormalizer::normalize( $raw_body );
		if ( ! $norm['ok'] ) {
			throw ApiError::validation( array( 'body' => $norm['error'] ) );
		}

		$now = current_time( 'mysql', true );
		$mid = $this->messages->insert( array(
			'conversation_id' => (int) $c['id'],
			'sender_user_id'  => $user_id,
			'sender_role'     => $role,
			'body'            => $norm['value'],
		) );
		$this->conversations->touch_last_message( (int) $c['id'], $now );
		$this->participants->mark_read( (int) $c['id'], $user_id, $role, $now, $mid ); // l'expéditeur a « lu » son propre message

		$recipient = self::ROLE_RECRUITER === $role ? (int) $c['candidate_user_id'] : 0; // 0 = côté entreprise
		$this->emit(
			'message.created',
			(int) $c['id'],
			array(
				'message_uuid'      => $this->messages->get( $mid )['public_uuid'],
				'conversation_uuid' => $c['public_uuid'],
				'sender_user_id'    => $user_id,
				'sender_role'       => $role,
				'recipient_user_id' => $recipient,
				'recipient_role'    => self::ROLE_RECRUITER === $role ? self::ROLE_CANDIDATE : self::ROLE_RECRUITER,
				'company_id'        => (int) $c['company_id'],
				'job_uuid'          => $c['job_uuid'],
			)
		);
		return $this->messages->get( $mid );
	}

	/**
	 * Marque la conversation lue pour l'utilisateur courant. @return int unread après.
	 */
	public function mark_read( int $user_id, string $uuid ): int {
		$acc = $this->accessible_or_fail( $user_id, $uuid );
		$c   = $acc['conversation'];
		$this->participants->mark_read( (int) $c['id'], $user_id, $acc['role'], current_time( 'mysql', true ), $this->messages->max_id( (int) $c['id'] ) );
		$this->emit( 'conversation.read', (int) $c['id'], array( 'user_id' => $user_id, 'conversation_uuid' => $c['public_uuid'] ) );
		return $this->unread_for( $user_id, $c );
	}

	/**
	 * Ferme la conversation manuellement. Réservé au **propriétaire (owner)** de
	 * l'entreprise ou à un modérateur/admin — jamais à un simple recruteur membre
	 * ni au candidat (décision V1 Lot 07). @return array<string,mixed>
	 */
	public function close( int $user_id, string $uuid ): array {
		$acc = $this->accessible_or_fail( $user_id, $uuid );
		$c   = $acc['conversation'];

		$is_admin = function_exists( 'current_user_can' ) && current_user_can( 'pst_moderate_content' );
		$is_owner = self::ROLE_RECRUITER === $acc['role']
			&& MembershipRepository::ROLE_OWNER === CompanyDirectory::role_of( (int) $c['company_id'], $user_id );
		if ( ! $is_admin && ! $is_owner ) {
			throw ApiError::forbidden( 'Fermeture réservée au propriétaire de l\'entreprise.' );
		}

		$this->conversations->set_status( (int) $c['id'], ConversationStateMachine::CLOSED );
		$this->emit( 'conversation.closed', (int) $c['id'], array( 'conversation_uuid' => $c['public_uuid'], 'reason' => 'manual' ) );
		return $this->conversations->get( (int) $c['id'] );
	}

	/**
	 * Fermeture **automatique système** de la conversation liée à une candidature
	 * devenue terminale (`withdrawn`/`rejected`). Aucune vérification de capability :
	 * déclenchée par un événement applicatif, pas par un utilisateur. La conversation
	 * reste consultable (lecture seule) ; l'historique est conservé.
	 */
	public function auto_close_for_application( int $application_id, string $reason ): void {
		if ( $application_id <= 0 ) {
			return;
		}
		$c = $this->conversations->get_by_application( $application_id );
		if ( null === $c ) {
			return;
		}
		$status = (string) $c['status'];
		if ( ConversationStateMachine::CLOSED === $status || ConversationStateMachine::ARCHIVED === $status ) {
			return; // déjà fermée/archivée : idempotent
		}
		$this->conversations->set_status( (int) $c['id'], ConversationStateMachine::CLOSED );
		$this->emit( 'conversation.closed', (int) $c['id'], array( 'conversation_uuid' => $c['public_uuid'], 'reason' => $reason ) );
	}

	/**
	 * Messages non lus pour un utilisateur dans une conversation.
	 *
	 * @param array<string,mixed> $c
	 */
	public function unread_for( int $user_id, array $c ): int {
		$since_id = $this->participants->last_read_message_id( (int) $c['id'], $user_id );
		return $this->messages->count_unread_for( (int) $c['id'], $user_id, $since_id );
	}

	/** Total non lus sur toutes les conversations accessibles. */
	public function unread_total( int $user_id ): int {
		$total = 0;
		foreach ( $this->list_for_user( $user_id ) as $c ) {
			$total += $this->unread_for( $user_id, $c );
		}
		return $total;
	}

	// --- Helpers -----------------------------------------------------------

	/** @param array<string,mixed> $ctx */
	private function application_internal_id( array $ctx ): int {
		// ApplicationDirectory::context ne renvoie pas l'id interne ; on le retrouve
		// via une requête sur la table applications (relation interne durable).
		global $wpdb;
		$table = $wpdb->prefix . 'postelio_applications';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE public_uuid = %s", (string) $ctx['app_uuid'] ) );
	}

	/** @return array<string,mixed>|null */
	private function find_by_application_uuid( string $application_uuid ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'postelio_applications';
		$aid   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE public_uuid = %s", $application_uuid ) );
		return $aid > 0 ? $this->conversations->get_by_application( $aid ) : null;
	}

	private function rate_limit_or_fail( int $user_id ): void {
		$max = (int) apply_filters( 'postelio/messaging/rate_limit_per_min', 20 );
		if ( $max <= 0 ) {
			return;
		}
		$key   = 'pst_msg_rl_' . $user_id;
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			throw new ApiError( 'rate_limited', 'Trop de messages envoyés, réessayez dans un instant.' );
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
	}

	/**
	 * @param array<string, mixed> $audit
	 */
	private function emit( string $event, int $conversation_id, array $audit ): void {
		Core::instance()->events()->emit(
			$event,
			array_merge(
				array(
					'conversation_id' => $conversation_id,
					'resource_type'   => 'conversation',
					'resource_id'     => (string) $conversation_id,
				),
				array( 'audit' => $this->audit_safe( $audit ) ),
				$audit
			)
		);
	}

	/**
	 * Ne conserve dans l'audit QUE des métadonnées non sensibles (jamais de body).
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function audit_safe( array $payload ): array {
		$keep = array( 'conversation_uuid', 'application_uuid', 'company_id', 'sender_role', 'recipient_role', 'reason' );
		$out  = array();
		foreach ( $keep as $k ) {
			if ( isset( $payload[ $k ] ) ) {
				$out[ $k ] = $payload[ $k ];
			}
		}
		return $out;
	}
}
