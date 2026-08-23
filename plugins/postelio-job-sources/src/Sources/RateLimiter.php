<?php
/**
 * Limiteur de débit + disjoncteur simple pour ne jamais marteler une source externe.
 * Respecte un débit maximal (défaut 8 req/s, sous la limite officielle FT de 10/s) et
 * ouvre un circuit après N échecs consécutifs (cooldown), avec repli sur le cache stale.
 *
 * @package Postelio\JobSources\Sources
 */

namespace Postelio\JobSources\Sources;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_JOBSOURCES_TESTING' ) ) {
		exit;
	}
}

final class RateLimiter {

	private string $key;
	private float $min_interval;
	private int $threshold;
	private int $cooldown;
	private float $last_call = 0.0;

	public function __construct( string $provider_key ) {
		$this->key          = 'pst_js_cb_' . $provider_key;
		$rate               = (int) apply_filters( 'postelio/job_sources/rate_per_sec', 8, $provider_key );
		$this->min_interval = $rate > 0 ? 1.0 / $rate : 0.125;
		$this->threshold    = (int) apply_filters( 'postelio/job_sources/circuit_threshold', 5, $provider_key );
		$this->cooldown     = (int) apply_filters( 'postelio/job_sources/circuit_cooldown', 300, $provider_key );
	}

	/** Le circuit est-il ouvert (trop d'échecs récents) ? */
	public function is_open(): bool {
		$state = get_transient( $this->key );
		return is_array( $state ) && (int) ( $state['fails'] ?? 0 ) >= $this->threshold;
	}

	/** Attend si nécessaire pour respecter le débit maximal (throttle). */
	public function throttle(): void {
		$now = microtime( true );
		if ( $this->last_call > 0.0 ) {
			$elapsed = $now - $this->last_call;
			if ( $elapsed < $this->min_interval ) {
				usleep( (int) ( ( $this->min_interval - $elapsed ) * 1_000_000 ) );
			}
		}
		$this->last_call = microtime( true );
	}

	public function record_success(): void {
		delete_transient( $this->key );
	}

	public function record_failure(): void {
		$state          = get_transient( $this->key );
		$fails          = is_array( $state ) ? (int) ( $state['fails'] ?? 0 ) + 1 : 1;
		set_transient( $this->key, array( 'fails' => $fails ), $this->cooldown );
	}
}
