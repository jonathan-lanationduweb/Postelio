<?php
/**
 * Câblage de la planification des alertes SUR LE SCHEDULER DU CORE uniquement (aucun WP-Cron
 * artisanal). L'ancre quotidienne est un événement unique AUTO-REPLANIFIÉ à 07h30 Europe/Paris
 * (précision + DST garantis, contrairement à un intervalle « daily » ancré à l'activation), et
 * `ensure()` la ré-arme si elle manque (auto-réparation à chaque boot).
 *
 * @package Postelio\Alerts\Alerts
 */

namespace Postelio\Alerts\Alerts;

use Postelio\Alerts\Time\ParisSchedule;
use Postelio\Core\Jobs\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AlertScheduler {

	private Scheduler $scheduler;
	private AlertDispatcher $dispatcher;

	public function __construct( Scheduler $scheduler, AlertDispatcher $dispatcher ) {
		$this->scheduler  = $scheduler;
		$this->dispatcher = $dispatcher;
	}

	public function register(): void {
		$this->scheduler->on( AlertDispatcher::HOOK_DISPATCH, array( $this->dispatcher, 'dispatch' ) );
		$this->scheduler->on( AlertDispatcher::HOOK_DRAIN, array( $this->dispatcher, 'drain' ) );
		add_action( 'init', array( $this, 'ensure' ), 20 );
	}

	/** Arme l'ancre quotidienne si absente (idempotent). */
	public function ensure(): void {
		$hook = Scheduler::HOOK_PREFIX . AlertDispatcher::HOOK_DISPATCH;
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( $hook ) ) {
			$ts = strtotime( ParisSchedule::next_daily( time() ) . ' UTC' );
			if ( $ts ) {
				$this->scheduler->schedule( AlertDispatcher::HOOK_DISPATCH, (int) $ts );
			}
		}
	}

	/** Retire les tâches planifiées d'alertes (désactivation). */
	public static function clear( Scheduler $scheduler ): void {
		$scheduler->cancel( AlertDispatcher::HOOK_DISPATCH );
		$scheduler->cancel( AlertDispatcher::HOOK_DRAIN );
	}
}
