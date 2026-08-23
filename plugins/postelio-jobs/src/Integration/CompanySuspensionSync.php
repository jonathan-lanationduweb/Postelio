<?php
/**
 * Cascade de suspension : lorsqu'une entreprise est suspendue (`company.suspended`,
 * émis par postelio-companies via la modération), on retire de la diffusion ses offres
 * ACTIVES (statut métier `published` → `suspended`).
 *
 * Découplage : la modération ne boucle jamais sur les offres et n'écrit jamais la table
 * des jobs ; c'est le domaine Jobs qui réagit à l'événement et applique SA propre
 * transition d'état. Les brouillons et états terminaux ne sont pas touchés. Réversible :
 * la réactivation de l'entreprise relève d'une décision admin par offre (`/status`).
 *
 * @package Postelio\Jobs\Integration
 */

namespace Postelio\Jobs\Integration;

use Postelio\Core\Events;
use Postelio\Jobs\Cpt\JobPostType;
use Postelio\Jobs\Jobs\JobRepository;
use Postelio\Jobs\Jobs\JobStateMachine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CompanySuspensionSync {

	private Events $events;
	private JobRepository $jobs;

	public function __construct( Events $events, JobRepository $jobs ) {
		$this->events = $events;
		$this->jobs   = $jobs;
	}

	public function register(): void {
		$this->events->on( 'company.suspended', array( $this, 'on_company_suspended' ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function on_company_suspended( $payload = array() ): void {
		$payload    = is_array( $payload ) ? $payload : array();
		$company_id = (int) ( $payload['company_id'] ?? 0 );
		if ( $company_id <= 0 ) {
			return;
		}

		$ids = get_posts(
			array(
				'post_type'      => JobPostType::TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => JobRepository::META_COMPANY_ID, 'value' => $company_id, 'compare' => '=' ),
					array( 'key' => JobRepository::META_STATUS, 'value' => JobStateMachine::PUBLISHED, 'compare' => '=' ),
				),
			)
		);

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! JobStateMachine::can_transition( JobStateMachine::PUBLISHED, JobStateMachine::SUSPENDED ) ) {
				break;
			}
			$this->jobs->set_status( $id, JobStateMachine::SUSPENDED );
			// Événement métier Jobs (propriétaire de `job.suspended`). `notify => false` :
			// le recruteur est déjà informé par `company.suspended` — pas de doublon.
			$this->events->emit(
				'job.suspended',
				array(
					'job_id'        => $id,
					'company_id'    => $company_id,
					'resource_type' => 'job',
					'resource_id'   => (string) $id,
					'audit'         => array( 'by' => 'system', 'cascade' => 'company.suspended' ),
					'notify'        => false,
				)
			);
		}
	}
}
