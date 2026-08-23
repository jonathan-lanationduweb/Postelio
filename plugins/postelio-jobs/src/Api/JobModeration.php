<?php
/**
 * Contrat public de modération des offres NATIVES (consommé par postelio-moderation).
 * Délègue à `JobService::admin_transition` (suspend/republish) — jamais d'UPDATE direct de
 * `pst_status` par un autre plugin. La (ré)publication reste soumise aux règles existantes
 * (entreprise vérifiée, D1).
 *
 * @package Postelio\Jobs\Api
 */

namespace Postelio\Jobs\Api;

use Postelio\Jobs\Jobs\JobRepository;
use Postelio\Jobs\Jobs\JobService;
use Postelio\Jobs\Jobs\JobStateMachine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobModeration {

	public static function suspend( int $actor_id, string $job_uuid ): bool {
		return self::transition( $actor_id, $job_uuid, JobStateMachine::SUSPENDED );
	}

	public static function unsuspend( int $actor_id, string $job_uuid ): bool {
		return self::transition( $actor_id, $job_uuid, JobStateMachine::PUBLISHED );
	}

	private static function transition( int $actor_id, string $job_uuid, string $decision ): bool {
		$id = JobDirectory::id_from_uuid( $job_uuid );
		if ( $id <= 0 ) {
			return false;
		}
		( new JobService( new JobRepository() ) )->admin_transition( $actor_id, $id, $decision );
		return true;
	}
}
