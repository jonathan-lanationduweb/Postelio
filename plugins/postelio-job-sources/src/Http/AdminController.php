<?php
/**
 * Endpoint d'observabilité admin (non public) :
 *   GET /job-sources/health   → état par provider (activé, auth OK, dernière sync, offres
 *                               actives, dernier échec). Aucun secret exposé.
 *
 * @package Postelio\JobSources\Http
 */

namespace Postelio\JobSources\Http;

use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\JobSources\Jobs\ExternalJobRepository;
use Postelio\JobSources\Jobs\SyncRunRepository;
use Postelio\JobSources\Sources\JobSourceRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminController extends Controller {

	private JobSourceRegistry $registry;
	private ExternalJobRepository $jobs;
	private SyncRunRepository $runs;

	public function __construct( JobSourceRegistry $registry, ExternalJobRepository $jobs, SyncRunRepository $runs ) {
		$this->registry = $registry;
		$this->jobs     = $jobs;
		$this->runs     = $runs;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace(),
			'/job-sources/health',
			array( 'methods' => 'GET', 'permission_callback' => Guard::require_cap( 'pst_manage_platform' ), 'callback' => $this->guarded( array( $this, 'health' ) ) )
		);
	}

	public function health(): \WP_REST_Response {
		$out       = array();
		$by_source = $this->jobs->count_active_by_source();
		$providers = $this->registry->providers();
		foreach ( $providers as $key => $provider ) {
			$last     = $this->runs->latest( $key );
			$last_ok  = $this->runs->latest( $key, 'success' );
			$out[]    = array(
				'key'                => $key,
				'label'              => $provider->get_name(),
				'available'          => $this->registry->is_source_available( (string) $key ),
				'active_offers'      => (int) ( $by_source[ $key ] ?? 0 ),
				'last_run_status'    => $last['status'] ?? null,
				'last_run_at'        => $last['finished_at'] ?? ( $last['started_at'] ?? null ),
				'last_success_at'    => $last_ok['finished_at'] ?? null,
				'last_error'         => $last['last_error'] ?? null, // court, jamais de secret
			);
		}
		// Sources ORPHELINES : offres actives en base dont la clé n'a aucun provider enregistré.
		// Jamais publiques (allowlist), jamais supprimées ici : signalées pour l'admin (additif).
		$orphaned = array();
		foreach ( $by_source as $key => $n ) {
			if ( ! isset( $providers[ $key ] ) ) {
				$orphaned[] = array( 'source_key' => (string) $key, 'active_offers' => (int) $n );
			}
		}
		return $this->ok( array(
			'providers'              => $out,
			'orphaned_sources'       => $orphaned,
			'orphaned_sources_count' => count( $orphaned ),
		) );
	}
}
