<?php
/**
 * Configuration des « slices » d'import (import progressif, périmètre configurable).
 * Aucun périmètre France Travail figé en dur : les slices proviennent d'un filtre/option.
 * Par défaut VIDE → aucune synchronisation réelle tant que non configuré (sécurité dev).
 *
 * Un slice : { key, criteria: { <params officiels FT> } }. Ex. criteria
 * `{ "departement": "69", "publieeDepuis": "7" }`.
 *
 * @package Postelio\JobSources\Sync
 */

namespace Postelio\JobSources\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SliceConfig {

	/** @return array<int, array{key:string, criteria:array<string,string>}> */
	public static function slices( string $provider_key ): array {
		$slices = apply_filters( 'postelio/job_sources/slices', array(), $provider_key );
		$out    = array();
		foreach ( (array) $slices as $s ) {
			if ( is_array( $s ) && ! empty( $s['key'] ) && isset( $s['criteria'] ) && is_array( $s['criteria'] ) ) {
				$out[] = array( 'key' => (string) $s['key'], 'criteria' => array_map( 'strval', $s['criteria'] ) );
			}
		}
		return $out;
	}

	public static function per_page(): int {
		return max( 1, min( 150, (int) apply_filters( 'postelio/job_sources/per_page', 50 ) ) );
	}
	public static function max_pages_per_run(): int {
		return max( 1, (int) apply_filters( 'postelio/job_sources/max_pages_per_run', 5 ) );
	}
	public static function offers_cap_per_run(): int {
		return max( 1, (int) apply_filters( 'postelio/job_sources/offers_cap_per_run', 500 ) );
	}
}
