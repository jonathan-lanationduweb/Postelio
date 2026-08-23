<?php
/**
 * Registre des providers de sources. Par défaut : France Travail. Filtrable
 * (`postelio/job_sources/providers`) pour injecter un FakeJobSourceProvider en test.
 *
 * @package Postelio\JobSources\Sources
 */

namespace Postelio\JobSources\Sources;

use Postelio\JobSources\Sources\FranceTravail\FranceTravailProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobSourceRegistry {

	/** @return array<string, JobSourceProvider> */
	public function providers(): array {
		$default  = array( FranceTravailProvider::KEY => new FranceTravailProvider() );
		$provided = apply_filters( 'postelio/job_sources/providers', $default );
		$out      = array();
		foreach ( (array) $provided as $p ) {
			if ( $p instanceof JobSourceProvider ) {
				$out[ $p->get_key() ] = $p;
			}
		}
		return $out;
	}

	public function get( string $key ): ?JobSourceProvider {
		return $this->providers()[ $key ] ?? null;
	}

	/** Clés des sources indisponibles (désactivées / non configurées) → exclues de la recherche. */
	public function disabled_source_keys(): array {
		$out = array();
		foreach ( $this->providers() as $key => $p ) {
			if ( ! $p->is_available() ) {
				$out[] = $key;
			}
		}
		return $out;
	}
}
