<?php
/**
 * Service des cases : ouverture/rattachement (1 active par ressource), transitions
 * contrôlées (CaseStateMachine), assignation, décision (avec action métier déléguée),
 * notes internes. Historique append-only via CaseEventRepository. Émet les événements
 * moderation (jamais de doublon avec job.suspended/company.suspended).
 *
 * @package Postelio\Moderation\Cases
 */

namespace Postelio\Moderation\Cases;

use Postelio\Core\ApiError;
use Postelio\Core\Plugin as Core;
use Postelio\Moderation\Actions\ModerationActions;
use Postelio\Moderation\Reports\ReasonCodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CaseService {

	private CaseRepository $cases;
	private CaseEventRepository $events;
	private ModerationActions $actions;

	public function __construct( CaseRepository $cases, CaseEventRepository $events, ModerationActions $actions ) {
		$this->cases   = $cases;
		$this->events  = $events;
		$this->actions = $actions;
	}

	public function cases(): CaseRepository {
		return $this->cases;
	}
	public function events(): CaseEventRepository {
		return $this->events;
	}

	/**
	 * Ouvre (ou rattache à) LA case active d'une ressource. Retourne l'id de case.
	 *
	 * @param array<int,string> $reason_codes
	 */
	public function open_or_attach( string $resource_type, string $resource_uuid, string $priority, string $risk_level, string $origin, bool $is_report, array $reason_codes = array(), ?int $actor = null ): int {
		$existing = $this->cases->active_for_resource( $resource_type, $resource_uuid );
		if ( null !== $existing ) {
			$id = (int) $existing['id'];
			$this->cases->bump_priority_if_higher( $id, $priority );
			if ( $is_report ) {
				$this->cases->increment_reports( $id );
			}
			$this->events->add( $id, array( 'actor_user_id' => $actor, 'event' => $is_report ? 'report_linked' : 'auto_flag', 'reason_codes' => $reason_codes ) );
			return $id;
		}
		$id = $this->cases->insert( array(
			'resource_type' => $resource_type,
			'resource_uuid' => $resource_uuid,
			'priority'      => $priority,
			'risk_level'    => $risk_level,
			'origin'        => $origin,
			'reports_count' => $is_report ? 1 : 0,
		) );
		$this->events->add( $id, array( 'actor_user_id' => $actor, 'event' => 'case_opened', 'to_state' => CaseStateMachine::OPEN, 'reason_codes' => $reason_codes ) );
		$this->emit( 'moderation.case_opened', $id, $resource_type, $resource_uuid );
		return $id;
	}

	/** @throws ApiError */
	public function assign( string $case_uuid, int $moderator_id ): array {
		$case = $this->require_case( $case_uuid );
		if ( CaseStateMachine::OPEN === $case['status'] ) {
			$this->transition( (int) $case['id'], CaseStateMachine::OPEN, CaseStateMachine::IN_REVIEW, $moderator_id, 'review_started' );
			$this->emit( 'moderation.review_started', (int) $case['id'], (string) $case['resource_type'], (string) $case['resource_uuid'] );
		}
		$this->cases->assign( (int) $case['id'], $moderator_id );
		$this->events->add( (int) $case['id'], array( 'actor_user_id' => $moderator_id, 'actor_role' => 'moderator', 'event' => 'assigned' ) );
		return $this->cases->get( (int) $case['id'] );
	}

	public function note( string $case_uuid, int $moderator_id, string $note ): array {
		$case = $this->require_case( $case_uuid );
		$this->events->add( (int) $case['id'], array( 'actor_user_id' => $moderator_id, 'actor_role' => 'moderator', 'event' => 'note', 'note' => sanitize_textarea_field( $note ) ) );
		return $this->cases->get( (int) $case['id'] );
	}

	/**
	 * Décision d'un modérateur/admin : exécute l'action métier (déléguée au domaine
	 * propriétaire) puis journalise et fait évoluer l'état de la case.
	 *
	 * @param array<int,string> $reason_codes
	 * @throws ApiError
	 */
	public function decide( string $case_uuid, int $actor_id, string $action, array $reason_codes = array(), string $note = '', bool $resolve = true ): array {
		$case = $this->require_case( $case_uuid );
		$id   = (int) $case['id'];
		if ( CaseStateMachine::is_terminal( (string) $case['status'] ) ) {
			throw new ApiError( 'invalid_transition', 'Cette case est déjà clôturée.' );
		}
		// Passe en revue si elle était juste ouverte.
		if ( CaseStateMachine::OPEN === $case['status'] ) {
			$this->transition( $id, CaseStateMachine::OPEN, CaseStateMachine::IN_REVIEW, $actor_id, 'review_started' );
			$case['status'] = CaseStateMachine::IN_REVIEW;
		}

		// M5 : la ressource effective est DÉRIVÉE de la case côté serveur (jamais un UUID
		// arbitraire du client). Exécution DÉLÉGUÉE au domaine propriétaire.
		list( $res_type, $res_uuid ) = CaseTargetResolver::resolve( $case, $action );
		$this->actions->execute( $action, $res_type, $res_uuid, $actor_id, $reason_codes );

		$this->events->add( $id, array(
			'actor_user_id' => $actor_id, 'actor_role' => $this->actor_role( $actor_id ), 'event' => 'decision',
			'decision' => $resolve ? 'resolved' : 'in_review', 'action' => $action, 'reason_codes' => $reason_codes,
			'note' => '' !== $note ? sanitize_textarea_field( $note ) : null,
			'policy_version' => (string) apply_filters( 'postelio/moderation/policy_version', '1' ),
		) );
		$this->emit_decision( $id, (string) $case['public_uuid'], $action, (string) $case['resource_type'], (string) $case['resource_uuid'], $res_type, $res_uuid );

		// Transition finale.
		if ( 'escalate' === $action ) {
			$this->transition( $id, (string) $case['status'], CaseStateMachine::ESCALATED, $actor_id, 'state_change' );
		} elseif ( $resolve ) {
			$to = ( 'no_action' === $action || 'dismiss' === $action ) ? CaseStateMachine::DISMISSED : CaseStateMachine::RESOLVED;
			$this->transition( $id, (string) $case['status'], $to, $actor_id, 'state_change' );
		}
		return $this->cases->get( $id );
	}

	/** @throws ApiError */
	private function transition( int $id, string $from, string $to, int $actor, string $event ): void {
		if ( ! CaseStateMachine::can_transition( $from, $to ) ) {
			throw new ApiError( 'invalid_transition', sprintf( 'Transition %s → %s non autorisée.', $from, $to ) );
		}
		$this->cases->set_status( $id, $to );
		$this->events->add( $id, array( 'actor_user_id' => $actor, 'actor_role' => $this->actor_role( $actor ), 'event' => $event, 'from_state' => $from, 'to_state' => $to ) );
	}

	/** @throws ApiError */
	private function require_case( string $uuid ): array {
		$case = $this->cases->get_by_uuid( $uuid );
		if ( null === $case ) {
			throw ApiError::not_found();
		}
		return $case;
	}

	private function actor_role( int $actor ): string {
		return ( function_exists( 'current_user_can' ) && current_user_can( 'pst_manage_platform' ) ) ? 'admin' : 'moderator';
	}

	private function emit( string $event, int $case_id, string $resource_type, string $resource_uuid ): void {
		Core::instance()->events()->emit( $event, array( 'case_id' => $case_id, 'resource_type' => $resource_type, 'resource_uuid' => $resource_uuid, 'audit_resource_type' => 'moderation_case' ) );
	}

	/**
	 * Émet `moderation.decision_made` en distinguant la ressource de la case (contexte) de
	 * la ressource RÉELLEMENT traitée (dérivée) — jamais un champ client non validé (M5).
	 */
	private function emit_decision( int $case_id, string $case_uuid, string $action, string $case_res_type, string $case_res_uuid, string $acted_type, string $acted_uuid ): void {
		Core::instance()->events()->emit( 'moderation.decision_made', array(
			'case_id'             => $case_id,
			'resource_type'       => $case_res_type,
			'resource_uuid'       => $case_res_uuid,
			'audit_resource_type' => 'moderation_case',
			// Journalisé (metadata d'audit) : la cible RÉELLEMENT traitée, dérivée du case.
			'audit'               => array(
				'case_uuid'     => $case_uuid,
				'action'        => $action,
				'resource_type' => $acted_type,
				'resource_uuid' => $acted_uuid,
			),
		) );
	}
}
