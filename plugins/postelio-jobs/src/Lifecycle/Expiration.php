<?php
/**
 * Expiration automatique des offres (tâche cron via l'abstraction du core).
 *
 *  - `published` dont l'échéance est dans ≤ 7 jours → `expiring` (job.expiring) ;
 *  - `published`/`expiring` dont l'échéance est dépassée → `expired` (job.expired).
 *
 * @package Postelio\Jobs\Lifecycle
 */

namespace Postelio\Jobs\Lifecycle;

use Postelio\Core\Plugin as Core;
use Postelio\Jobs\Jobs\JobRepository;
use Postelio\Jobs\Jobs\JobStateMachine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Expiration {

	public const CRON = 'jobs_expiration';

	private JobRepository $jobs;

	public function __construct( JobRepository $jobs ) {
		$this->jobs = $jobs;
	}

	/**
	 * Exécute une passe d'expiration. Retourne les compteurs.
	 *
	 * @return array{expired:int, expiring:int}
	 */
	public function run(): array {
		$today = current_time( 'Y-m-d' );
		$soon  = gmdate( 'Y-m-d', strtotime( $today . ' +' . \Postelio\Jobs\Jobs\JobService::EXPIRING_DAYS . ' days' ) );

		$expired = 0;
		foreach ( array( JobStateMachine::PUBLISHED, JobStateMachine::EXPIRING ) as $st ) {
			foreach ( $this->jobs->ids_by_status_expiring_before( $st, $today ) as $id ) {
				if ( JobStateMachine::can_transition( $st, JobStateMachine::EXPIRED ) ) {
					$this->jobs->set_status( (int) $id, JobStateMachine::EXPIRED );
					$this->emit( 'job.expired', (int) $id );
					++$expired;
				}
			}
		}

		$expiring = 0;
		foreach ( $this->jobs->ids_by_status_expiring_before( JobStateMachine::PUBLISHED, $soon ) as $id ) {
			// (les échéances déjà dépassées ont été traitées ci-dessus)
			$this->jobs->set_status( (int) $id, JobStateMachine::EXPIRING );
			$this->emit( 'job.expiring', (int) $id );
			++$expiring;
		}

		return array( 'expired' => $expired, 'expiring' => $expiring );
	}

	private function emit( string $event, int $job_id ): void {
		$company_id = (int) get_post_meta( $job_id, JobRepository::META_COMPANY_ID, true );
		Core::instance()->events()->emit(
			$event,
			array(
				'job_id'        => $job_id,
				'company_id'    => $company_id,
				'resource_type' => 'job',
				'resource_id'   => (string) $job_id,
			)
		);
	}
}
