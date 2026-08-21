<?php
/**
 * Contrat public STABLE des entretiens, destiné aux autres plugins (notifications,
 * e-mails, front). Ne renvoie jamais d'ID interne ni de données privées superflues.
 *
 * @package Postelio\Interviews\Api
 */

namespace Postelio\Interviews\Api;

use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Interviews\Interviews\InterviewHistoryRepository;
use Postelio\Interviews\Interviews\InterviewRepository;
use Postelio\Interviews\Interviews\InterviewStateMachine;
use Postelio\Users\Api\UserDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class InterviewDirectory {

	private static function repo(): InterviewRepository {
		return new InterviewRepository();
	}

	/**
	 * Contexte public d'un entretien (pour préparer notification / e-mail de preuve),
	 * ou null si inconnu. Contient tout le nécessaire à l'e-mail de confirmation :
	 * entreprise, offre, date UTC, fuseau, durée, type + coordonnées selon le type.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_context( string $interview_uuid ): ?array {
		$iv = self::repo()->get_by_uuid( $interview_uuid );
		if ( null === $iv ) {
			return null;
		}
		return array(
			'interview_uuid'   => (string) $iv['public_uuid'],
			'application_uuid' => (string) $iv['application_uuid'],
			'job_uuid'         => $iv['job_uuid'],
			'company_id'       => (int) $iv['company_id'],
			'company_uuid'     => $iv['company_uuid'],
			'company_name'     => CompanyDirectory::name_of( (int) $iv['company_id'] ),
			'candidate_user_id' => (int) $iv['candidate_user_id'],
			'candidate_name'   => UserDirectory::display_name( (int) $iv['candidate_user_id'] ),
			'type'             => (string) $iv['type'],
			'status'           => (string) $iv['status'],
			'scheduled_at'     => (string) $iv['scheduled_at'], // UTC
			'timezone'         => (string) $iv['timezone'],
			'duration_minutes' => (int) $iv['duration_minutes'],
			'location_data'    => $iv['location_data'],
			'video_data'       => $iv['video_data'],
			'phone_data'       => $iv['phone_data'],
		);
	}

	/** Un entretien actif (non terminal) existe-t-il pour cette candidature ? */
	public static function has_active_for_application( int $application_id ): bool {
		return self::repo()->has_active_for_application( $application_id );
	}

	/**
	 * Nombre d'entretiens « à venir » (proposed/confirmed) pour un utilisateur
	 * (candidat → les siens ; recruteur → ceux de son entreprise). Utile pour un badge.
	 */
	public static function upcoming_count( int $user_id ): int {
		$scope    = UserDirectory::is_candidate( $user_id ) ? 'candidate' : 'company';
		$owner_id = 'candidate' === $scope ? $user_id : CompanyDirectory::company_of_user( $user_id );
		if ( $owner_id <= 0 ) {
			return 0;
		}
		$total = 0;
		foreach ( array( InterviewStateMachine::PROPOSED, InterviewStateMachine::CONFIRMED, InterviewStateMachine::RESCHEDULE_REQUESTED ) as $st ) {
			$res    = self::repo()->list( $scope, $owner_id, array( 'status' => $st ), 1, 100 );
			$total += (int) $res['total'];
		}
		return $total;
	}

	/**
	 * Historique public (sans métadonnée sensible) d'un entretien.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function history( string $interview_uuid ): array {
		$iv = self::repo()->get_by_uuid( $interview_uuid );
		if ( null === $iv ) {
			return array();
		}
		return ( new InterviewHistoryRepository() )->list_for_interview( (int) $iv['id'] );
	}
}
