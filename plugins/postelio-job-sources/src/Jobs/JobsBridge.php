<?php
/**
 * Pont vers postelio-jobs via ses filtres publics (aucune dépendance circulaire) :
 *  - `postelio/jobs/search_provider` → enveloppe le provider natif dans le Composite ;
 *  - `postelio/jobs/present_external` → présente une ligne externe (JobPresenter délègue) ;
 *  - `postelio/jobs/resolve_external` → résout un UUID externe (JobDirectory/JobController).
 *
 * @package Postelio\JobSources\Jobs
 */

namespace Postelio\JobSources\Jobs;

use Postelio\JobSources\Search\CompositeJobSearchProvider;
use Postelio\JobSources\Sources\JobSourceRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobsBridge {

	private ExternalJobRepository $repo;
	private JobSourceRegistry $registry;

	public function __construct( ExternalJobRepository $repo, JobSourceRegistry $registry ) {
		$this->repo     = $repo;
		$this->registry = $registry;
	}

	public function attach(): void {
		add_filter( 'postelio/jobs/search_provider', array( $this, 'wrap_search' ), 10, 1 );
		add_filter( 'postelio/jobs/present_external', array( $this, 'present_external' ), 10, 2 );
		add_filter( 'postelio/jobs/resolve_external', array( $this, 'resolve_external' ), 10, 2 );
	}

	/** @param mixed $native */
	public function wrap_search( $native ) {
		if ( $native instanceof \Postelio\Jobs\Search\JobSearchProvider && class_exists( CompositeJobSearchProvider::class ) ) {
			return new CompositeJobSearchProvider( $native, $this->repo, $this->registry );
		}
		return $native;
	}

	/**
	 * @param mixed                $default
	 * @param array<string, mixed> $row
	 * @return mixed
	 */
	public function present_external( $default, $row ) {
		if ( is_array( $row ) && 'external' === ( $row['source_type'] ?? '' ) ) {
			return ExternalJobPresenter::public_view( $row );
		}
		return $default;
	}

	/**
	 * @param mixed  $default
	 * @param string $uuid
	 * @return array<string, mixed>|null
	 */
	public function resolve_external( $default, $uuid ) {
		$row = $this->repo->get_by_uuid( (string) $uuid );
		if ( null === $row ) {
			return $default;
		}
		$provider         = $this->registry->get( (string) $row['source_key'] );
		$source_available = null !== $provider && $provider->is_available();
		return array(
			'found'            => true,
			'source_type'      => 'external',
			'source_key'       => (string) $row['source_key'],
			'application_mode' => (string) $row['application_mode'],
			'sync_status'      => (string) $row['sync_status'],
			'local_visibility' => (string) $row['local_visibility'],
			'source_available' => $source_available, // source désactivée/non configurée → indisponible public
			'apply_url'        => (string) ( $row['external_apply_url'] ?: $row['external_url'] ),
			'public_view'      => ExternalJobPresenter::public_view( $row ),
		);
	}
}
