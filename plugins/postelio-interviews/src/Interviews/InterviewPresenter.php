<?php
/**
 * Présentation des entretiens pour l'API (UUID uniquement, jamais d'ID interne, jamais
 * d'audit ni de note recruteur). La vue candidat et la vue recruteur diffèrent : le
 * candidat voit l'entreprise + l'offre ; le recruteur voit le candidat.
 *
 * @package Postelio\Interviews\Interviews
 */

namespace Postelio\Interviews\Interviews;

use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Users\Api\UserDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class InterviewPresenter {

	/**
	 * @param array<string, mixed> $iv
	 * @return array<string, mixed>
	 */
	public static function view( array $iv, string $viewer_role ): array {
		$out = array(
			'uuid'             => (string) $iv['public_uuid'],
			'type'             => (string) $iv['type'],
			'status'           => (string) $iv['status'],
			'scheduled_at'     => self::iso( (string) $iv['scheduled_at'] ),
			'duration_minutes' => (int) $iv['duration_minutes'],
			'timezone'         => (string) $iv['timezone'],
			'instructions'     => $iv['instructions'] ?? null,
			'application_uuid' => (string) $iv['application_uuid'],
			'job_uuid'         => $iv['job_uuid'],
			'company'          => array(
				'uuid' => $iv['company_uuid'],
				'nom'  => CompanyDirectory::name_of( (int) $iv['company_id'] ),
			),
			'actions'          => self::actions( (string) $iv['status'], $viewer_role ),
		);

		// Données spécifiques au type.
		if ( InterviewValidator::TYPE_VIDEO === $iv['type'] ) {
			$out['video'] = $iv['video_data'];
		} elseif ( InterviewValidator::TYPE_ONSITE === $iv['type'] ) {
			$out['location'] = $iv['location_data'];
		} elseif ( InterviewValidator::TYPE_PHONE === $iv['type'] ) {
			$out['phone'] = $iv['phone_data'];
		}

		// Proposition de re-créneau en attente.
		if ( ! empty( $iv['proposed_scheduled_at'] ) ) {
			$out['proposed'] = array(
				'scheduled_at' => self::iso( (string) $iv['proposed_scheduled_at'] ),
				'message'      => $iv['proposed_message'] ?? null,
				'by'           => (int) ( $iv['proposed_by'] ?? 0 ) === (int) $iv['candidate_user_id'] ? 'candidate' : 'recruiter',
			);
		}

		// Le recruteur voit le candidat ; le candidat voit l'entreprise (déjà dans `company`).
		if ( InterviewService::ROLE_RECRUITER === $viewer_role ) {
			$cid                = (int) $iv['candidate_user_id'];
			$out['candidate']   = array(
				'display_name' => UserDirectory::display_name( $cid ),
				'profile_uuid' => UserDirectory::candidate_profile_uuid( $cid ),
			);
			$out['created_at']  = self::iso( (string) $iv['created_at'] );
		}

		return $out;
	}

	/**
	 * @param array<int, array<string,mixed>> $rows
	 * @return array<int, array<string,mixed>>
	 */
	public static function collection( array $rows, string $viewer_role ): array {
		return array_map(
			static fn( array $iv ): array => self::view( $iv, $viewer_role ),
			$rows
		);
	}

	/**
	 * @param array<int, array<string,mixed>> $rows
	 * @return array<int, array<string,mixed>>
	 */
	public static function history( array $rows ): array {
		return array_map(
			static function ( array $h ): array {
				return array(
					'action'     => (string) $h['action'],
					'actor_role' => (string) $h['actor_role'],
					'from'       => $h['from_status'] ?? null,
					'to'         => $h['to_status'] ?? null,
					'at'         => self::iso( (string) $h['created_at'] ),
				);
			},
			$rows
		);
	}

	/** Actions autorisées côté client selon le rôle et le statut. @return string[] */
	private static function actions( string $status, string $role ): array {
		$a = array();
		if ( InterviewService::ROLE_CANDIDATE === $role ) {
			if ( InterviewStateMachine::candidate_can_answer( $status ) ) {
				$a[] = 'confirm';
				$a[] = 'decline';
			}
			if ( InterviewStateMachine::candidate_can_reschedule( $status ) ) {
				$a[] = 'reschedule';
			}
			if ( InterviewStateMachine::candidate_can_cancel( $status ) ) {
				$a[] = 'cancel';
			}
		} elseif ( InterviewService::ROLE_RECRUITER === $role ) {
			if ( ! InterviewStateMachine::is_terminal( $status ) ) {
				$a[] = 'modify';
				$a[] = 'cancel';
			}
			if ( InterviewStateMachine::RESCHEDULE_REQUESTED === $status ) {
				$a[] = 'accept_reschedule';
			}
			if ( InterviewStateMachine::can_transition( $status, InterviewStateMachine::COMPLETED ) ) {
				$a[] = 'complete';
			}
		}
		return $a;
	}

	/** DATETIME UTC en base → ISO 8601 UTC (`...Z`). */
	private static function iso( string $mysql_utc ): ?string {
		if ( '' === $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return null;
		}
		return str_replace( ' ', 'T', $mysql_utc ) . 'Z';
	}
}
