<?php
/**
 * Orchestrateur de synchronisation : authenticate → fetch → normalize → validate/sanitize
 * (dans le provider) → upsert → observabilité. Import par slices bornés (cap pages/offres
 * par run), reprise possible par offset. Réutilise Core\Jobs\Scheduler (aucun 2ᵉ cron).
 *
 * Politique de disparition (§17) :
 *  - CAS A (panne/429/timeout/partiel) → AUCUN retrait, run `partial`/`failed`.
 *  - CAS B (refresh COMPLET du slice réussi) → offres actives non revues = `removed` +
 *    anonymisation (licence Art. 7).
 *
 * @package Postelio\JobSources\Sync
 */

namespace Postelio\JobSources\Sync;

use Postelio\Core\Plugin as Core;
use Postelio\JobSources\Jobs\ExternalJobRepository;
use Postelio\JobSources\Jobs\SyncRunRepository;
use Postelio\JobSources\Sources\JobSourceRegistry;
use Postelio\JobSources\Sources\SyncQuery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SyncOrchestrator {

	private JobSourceRegistry $registry;
	private ExternalJobRepository $jobs;
	private SyncRunRepository $runs;

	public function __construct( JobSourceRegistry $registry, ExternalJobRepository $jobs, SyncRunRepository $runs ) {
		$this->registry = $registry;
		$this->jobs     = $jobs;
		$this->runs     = $runs;
	}

	/**
	 * Synchronise tous les slices configurés d'un provider. Retourne un résumé agrégé.
	 *
	 * @return array<string, mixed>
	 */
	public function run_provider( string $provider_key ): array {
		$provider = $this->registry->get( $provider_key );
		if ( null === $provider || ! $provider->is_available() ) {
			return array( 'skipped' => true, 'reason' => 'unavailable' );
		}
		$summary = array( 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'errors' => 0, 'slices' => 0 );
		foreach ( SliceConfig::slices( $provider_key ) as $slice ) {
			$r = $this->run_slice( $provider_key, $slice['key'], $slice['criteria'] );
			foreach ( array( 'created', 'updated', 'unchanged', 'removed', 'errors' ) as $k ) {
				$summary[ $k ] += (int) ( $r[ $k ] ?? 0 );
			}
			++$summary['slices'];
		}
		return $summary;
	}

	/**
	 * Synchronise UN slice. `$start_offset` permet une reprise (checkpoint).
	 *
	 * @param array<string, string> $criteria
	 * @return array<string, mixed>
	 */
	public function run_slice( string $provider_key, string $slice_key, array $criteria, int $start_offset = 0 ): array {
		$provider = $this->registry->get( $provider_key );
		if ( null === $provider || ! $provider->is_available() ) {
			return array( 'skipped' => true );
		}
		$run_id  = $this->runs->start( $provider_key, $slice_key );
		$started = current_time( 'mysql', true );
		$this->emit( 'job_source.sync_started', array( 'provider' => $provider_key, 'slice' => $slice_key ) );

		$attribution = $provider->get_attribution();
		$per_page    = SliceConfig::per_page();
		$max_pages   = SliceConfig::max_pages_per_run();
		$cap         = SliceConfig::offers_cap_per_run();

		$seen = array();
		$m    = array( 'pages' => 0, 'fetched' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'errors' => 0, 'last_error' => null, 'cursor' => (string) $start_offset );
		$offset   = max( 0, $start_offset );
		$complete = false;
		$failed   = false;

		for ( $page = 0; $page < $max_pages; $page++ ) {
			if ( $m['fetched'] >= $cap ) {
				break; // cap atteint → run partiel (pas de retrait)
			}
			try {
				$result = $provider->fetch_page( new SyncQuery( $slice_key, $criteria, $offset, $per_page ) );
			} catch ( \Throwable $e ) {
				$m['errors']++;
				$m['last_error'] = substr( $e->getMessage(), 0, 250 );
				$failed          = true;
				break; // CAS A : on ne conclut JAMAIS à une disparition
			}
			$m['pages']++;
			foreach ( $result->raw_offers as $raw ) {
				$m['fetched']++;
				$norm = $provider->normalize( is_array( $raw ) ? $raw : array() );
				if ( null === $norm ) {
					$m['errors']++;
					continue;
				}
				$up            = $this->jobs->upsert( $norm, $slice_key, $attribution );
				$seen[]        = $norm->external_id;
				$m[ $up['status'] ] = ( $m[ $up['status'] ] ?? 0 ) + 1;
				if ( 'created' === $up['status'] ) {
					$this->emit( 'external_job.created', array( 'provider' => $provider_key, 'job_uuid' => $up['uuid'] ) );
				} elseif ( 'updated' === $up['status'] ) {
					$this->emit( 'external_job.updated', array( 'provider' => $provider_key, 'job_uuid' => $up['uuid'] ) );
				}
			}
			$offset      += $per_page;
			$m['cursor']  = (string) $offset;
			if ( ! $result->has_more ) {
				$complete = true;
				break;
			}
		}

		// CAS B : refresh COMPLET réussi → retrait des offres non revues (anonymisation).
		if ( $complete && ! $failed ) {
			$removed       = $this->jobs->mark_removed_for_slice( $provider_key, $slice_key, $seen, $started );
			$m['removed']  = $removed;
			if ( $removed > 0 ) {
				$this->emit( 'external_job.removed', array( 'provider' => $provider_key, 'slice' => $slice_key, 'count' => $removed ) );
			}
		}

		$status = $failed ? 'failed' : ( $complete ? 'success' : 'partial' );
		$this->runs->finish( $run_id, $status, array(
			'sync_cursor' => $m['cursor'], 'pages' => $m['pages'], 'fetched' => $m['fetched'],
			'created_count' => $m['created'], 'updated_count' => $m['updated'], 'unchanged_count' => $m['unchanged'],
			'removed_count' => $m['removed'], 'error_count' => $m['errors'], 'last_error' => $m['last_error'],
		) );
		$this->emit( $failed ? 'job_source.sync_failed' : 'job_source.sync_completed', array( 'provider' => $provider_key, 'slice' => $slice_key, 'status' => $status ) );

		return array_merge( $m, array( 'status' => $status ) );
	}

	/** @param array<string, mixed> $payload */
	private function emit( string $event, array $payload ): void {
		if ( class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			Core::instance()->events()->emit( $event, array_merge( $payload, array( 'resource_type' => 'job_source' ) ) );
		}
	}
}
