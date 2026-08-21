<?php
/**
 * Contrat public STABLE des candidatures, destiné aux futurs plugins
 * `postelio-interviews` et `postelio-messaging`.
 *
 * Ils NE DOIVENT PAS écrire la table applications directement : ils passent par ce
 * contrat pour vérifier l'appartenance, récupérer le contexte (candidat / entreprise
 * / offre) et faire évoluer le pipeline (ex. vers `interview`).
 *
 * @package Postelio\Applications\Api
 */

namespace Postelio\Applications\Api;

use Postelio\Applications\Applications\ApplicationRepository;
use Postelio\Applications\Applications\ApplicationService;
use Postelio\Applications\Applications\HistoryRepository;
use Postelio\Applications\Applications\NoteRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApplicationDirectory {

	private static function service(): ApplicationService {
		return new ApplicationService( new ApplicationRepository(), new HistoryRepository(), new NoteRepository() );
	}

	/**
	 * Contexte d'une candidature (identités), ou null si inconnue.
	 *
	 * @return array{app_uuid:string, candidate_user_id:int, company_id:int, company_uuid:string, job_id:int, job_uuid:string, status:string}|null
	 */
	public static function context( string $app_uuid ): ?array {
		$a = ( new ApplicationRepository() )->get_by_uuid( $app_uuid );
		if ( null === $a ) {
			return null;
		}
		$snap = is_array( $a['job_snapshot'] ?? null ) ? $a['job_snapshot'] : array();
		return array(
			'app_uuid'          => (string) $a['public_uuid'],
			'candidate_user_id' => (int) $a['candidate_user_id'],
			'company_id'        => (int) $a['company_id'],
			'company_uuid'      => (string) $a['company_uuid'],
			'job_id'            => (int) $a['job_id'],
			'job_uuid'          => (string) $a['job_uuid'],
			'job_title'         => isset( $snap['titre'] ) ? (string) $snap['titre'] : null,
			'company_name'      => isset( $snap['company_name'] ) ? (string) $snap['company_name'] : null,
			'status'            => (string) $a['status'],
		);
	}

	public static function belongs_to_company( string $app_uuid, int $company_id ): bool {
		$c = self::context( $app_uuid );
		return null !== $c && $c['company_id'] === $company_id;
	}

	/**
	 * La candidature est-elle dans un état permettant de planifier un entretien ?
	 * Vrai pour les états « actifs » du pipeline (new/review/shortlisted/interview) ;
	 * faux pour les états terminaux (selected/rejected/withdrawn). L'autorité de l'état
	 * reste dans postelio-applications (utilisé par postelio-interviews).
	 */
	public static function is_schedulable( string $app_uuid ): bool {
		$c = self::context( $app_uuid );
		return null !== $c && in_array( $c['status'], \Postelio\Applications\Applications\ApplicationStateMachine::ACTIVE, true );
	}

	/**
	 * Fait évoluer le pipeline vers `interview` (utilisé par postelio-interviews).
	 * Respecte permissions/transitions via le service ; n'écrit jamais en direct.
	 *
	 * @return array<string, mixed>
	 */
	public static function move_to_interview( string $app_uuid, int $recruiter_id ): array {
		return self::service()->change_status( $recruiter_id, $app_uuid, array( 'to' => 'interview' ) );
	}
}
