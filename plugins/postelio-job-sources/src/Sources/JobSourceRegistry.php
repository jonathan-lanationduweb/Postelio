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
	if ( ! defined( 'POSTELIO_JOBSOURCES_TESTING' ) ) {
		exit;
	}
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

	/**
	 * SOURCE DE VÉRITÉ de la disponibilité publique d'une source (règle V1) : une offre externe
	 * n'est publique que si son provider est ENREGISTRÉ dans ce registre ET disponible
	 * (activé + configuré). Une clé inconnue — ligne orpheline en base, provider retiré, résidu
	 * de test — est donc TOUJOURS indisponible. La clé vient de la base : elle n'est jamais
	 * utilisée pour instancier une classe, seulement comparée à l'allowlist des providers.
	 * Le filtre `postelio/job_sources/source_available` permet à un module métier de FERMER une
	 * source disponible ; il ne peut jamais ouvrir une source inconnue ou indisponible.
	 */
	public function is_source_available( string $source_key ): bool {
		$provider = $this->get( $source_key );
		if ( null === $provider || ! $provider->is_available() ) {
			return false;
		}
		return (bool) apply_filters( 'postelio/job_sources/source_available', true, $source_key, $provider );
	}

	/**
	 * ALLOWLIST des clés de sources publiquement disponibles — seule liste admise pour filtrer la
	 * recherche publique (vide → aucune offre externe).
	 *
	 * @return string[]
	 */
	public function available_source_keys(): array {
		$out = array();
		foreach ( array_keys( $this->providers() ) as $key ) {
			if ( $this->is_source_available( (string) $key ) ) {
				$out[] = (string) $key;
			}
		}
		return $out;
	}

	/**
	 * Clés des sources ENREGISTRÉES mais indisponibles (observabilité uniquement). Ne sert plus à
	 * filtrer la recherche : une denylist ignore, par construction, les sources inconnues.
	 *
	 * @return string[]
	 */
	public function disabled_source_keys(): array {
		$out = array();
		foreach ( array_keys( $this->providers() ) as $key ) {
			if ( ! $this->is_source_available( (string) $key ) ) {
				$out[] = (string) $key;
			}
		}
		return $out;
	}
}
