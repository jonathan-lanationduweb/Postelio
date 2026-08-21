<?php
/**
 * Abstraction cron / file de tâches asynchrones au-dessus de WP-Cron.
 *
 * Fournit une API stable (`enqueue`, `schedule`, `recurring`, `cancel`) pour les
 * plugins métier. AUCUNE tâche métier n'est planifiée ici (transversal).
 *
 * @package Postelio\Core\Jobs
 */

namespace Postelio\Core\Jobs;

use Postelio\Core\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scheduler {

	/** Préfixe des hooks cron Postelio. */
	public const HOOK_PREFIX = 'postelio_job_';

	private Events $events;

	public function __construct( Events $events ) {
		$this->events = $events;
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedules' ) );
	}

	/**
	 * Ajoute des fréquences personnalisées (en plus de hourly/twicedaily/daily/weekly).
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules
	 * @return array<string, array{interval:int, display:string}>
	 */
	public static function register_schedules( array $schedules ): array {
		if ( ! isset( $schedules['postelio_15min'] ) ) {
			$schedules['postelio_15min'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Toutes les 15 minutes (Postelio)', 'postelio-core' ),
			);
		}
		return $schedules;
	}

	private static function hook( string $name ): string {
		$name = trim( $name );
		if ( 0 === strncmp( $name, self::HOOK_PREFIX, strlen( self::HOOK_PREFIX ) ) ) {
			return $name;
		}
		return self::HOOK_PREFIX . $name;
	}

	/**
	 * Planifie une tâche unique dès que possible (file d'attente légère).
	 *
	 * @param array<int, mixed> $args
	 */
	public function enqueue( string $name, array $args = array() ): void {
		$this->schedule( $name, time(), $args );
	}

	/**
	 * Planifie une tâche unique à un instant donné.
	 *
	 * @param array<int, mixed> $args
	 */
	public function schedule( string $name, int $timestamp, array $args = array() ): void {
		$hook = self::hook( $name );
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( $timestamp, $hook, $args );
		}
	}

	/**
	 * Planifie une tâche récurrente.
	 *
	 * @param array<int, mixed> $args
	 */
	public function recurring( string $name, string $recurrence, array $args = array() ): void {
		$hook = self::hook( $name );
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_event( time(), $recurrence, $hook, $args );
		}
	}

	/**
	 * Enregistre le gestionnaire d'une tâche.
	 *
	 * @param callable $callback
	 */
	public function on( string $name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_action( self::hook( $name ), $callback, $priority, $accepted_args );
	}

	/**
	 * @param array<int, mixed> $args
	 */
	public function cancel( string $name, array $args = array() ): void {
		wp_clear_scheduled_hook( self::hook( $name ), $args );
	}

	/**
	 * Retire toutes les tâches Postelio planifiées (appelé à la désactivation).
	 */
	public static function clear_all(): void {
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return;
		}
		foreach ( $crons as $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( array_keys( $hooks ) as $hook ) {
				if ( is_string( $hook ) && 0 === strncmp( $hook, self::HOOK_PREFIX, strlen( self::HOOK_PREFIX ) ) ) {
					wp_clear_scheduled_hook( $hook );
				}
			}
		}
	}
}
