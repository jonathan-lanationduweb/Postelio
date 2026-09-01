<?php
/**
 * Dispatcher d'alertes : sélectionne les recherches ÉCHUES (next_run_at atteint) par lots bornés
 * et les fait traiter par le moteur. Ne scanne jamais toutes les recherches (sélection indexée
 * `due_batch`). Se replanifie tant qu'il reste des lots (évite un cron géant).
 *
 * Compte suspendu/inactif (§17) : aucune exécution, aucune notification — mais la planification
 * avance quand même (next_run_at recalculé) pour ne pas re-sélectionner la ligne à chaque tick ;
 * les données sont conservées et l'alerte reprend à la réactivation.
 *
 * @package Postelio\Alerts\Alerts
 */

namespace Postelio\Alerts\Alerts;

use Postelio\Alerts\Searches\SavedSearchRepository;
use Postelio\Alerts\Time\ParisSchedule;
use Postelio\Core\Jobs\Scheduler;
use Postelio\Users\Api\UserDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AlertDispatcher {

	public const HOOK_DISPATCH = 'alerts_dispatch';
	public const HOOK_DRAIN    = 'alerts_drain';

	private SavedSearchRepository $searches;
	private MatchingService $matching;
	private DeliveryRepository $deliveries;
	private Scheduler $scheduler;

	public function __construct( SavedSearchRepository $searches, MatchingService $matching, DeliveryRepository $deliveries, Scheduler $scheduler ) {
		$this->searches   = $searches;
		$this->matching   = $matching;
		$this->deliveries = $deliveries;
		$this->scheduler  = $scheduler;
	}

	private function batch_size(): int {
		return max( 1, (int) apply_filters( 'postelio/alerts/dispatch_batch', 150 ) );
	}
	/** Rétention des deliveries (≈13 mois par défaut). */
	private function retention_days(): int {
		return max( 1, (int) apply_filters( 'postelio/alerts/deliveries_retention_days', 396 ) );
	}

	/**
	 * Ancre quotidienne (07h30 Europe/Paris) : (1) se replanifie pour le prochain 07h30 —
	 * recalcul DST-correct ; (2) lance le drain ; (3) purge de rétention bornée.
	 */
	public function dispatch(): void {
		// (1) Replanification de l'ancre du lendemain.
		$next_ts = strtotime( ParisSchedule::next_daily( time() ) . ' UTC' );
		if ( $next_ts ) {
			$this->scheduler->schedule( self::HOOK_DISPATCH, (int) $next_ts );
		}
		// (2) Drain des recherches échues (daily ET weekly dont next_run_at est atteint).
		$this->scheduler->enqueue( self::HOOK_DRAIN );
		// (3) Purge de rétention (bornée par lot).
		$before = gmdate( 'Y-m-d H:i:s', time() - $this->retention_days() * DAY_IN_SECONDS );
		$this->deliveries->purge_before( $before );
	}

	/** Traite un lot de recherches échues ; se replanifie si le lot était plein. */
	public function drain(): void {
		$now   = current_time( 'mysql', true );
		$batch = $this->searches->due_batch( $now, $this->batch_size() );
		foreach ( $batch as $row ) {
			$candidate = (int) $row['candidate_user_id'];
			if ( UserDirectory::exists( $candidate ) && UserDirectory::is_active( $candidate ) ) {
				$this->matching->run( $row, 'cron' );
			} else {
				// Suspendu/inactif : pas de run, on avance seulement la planification.
				$next = ParisSchedule::next_run( (string) $row['alert_frequency'], time(), (string) ( $row['timezone'] ?: ParisSchedule::TIMEZONE ) );
				$this->searches->update( (int) $row['id'], array( 'next_run_at' => $next ) );
			}
		}
		if ( count( $batch ) >= $this->batch_size() ) {
			$this->scheduler->enqueue( self::HOOK_DRAIN ); // d'autres lots restent
		}
	}
}
