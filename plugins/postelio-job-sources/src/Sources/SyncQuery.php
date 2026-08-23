<?php
/**
 * Décrit un « slice » de synchronisation (sous-ensemble borné d'offres) + sa position de
 * pagination. La pagination France Travail plafonnant à ~3150 résultats/requête, l'import
 * se fait par slices configurables (département, ROME, fenêtre de dates…).
 *
 * @package Postelio\JobSources\Sources
 */

namespace Postelio\JobSources\Sources;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_JOBSOURCES_TESTING' ) ) {
		exit;
	}
}

final class SyncQuery {

	public string $slice_key;
	/** @var array<string, string> Critères source (ex. departement, codeROME, publieeDepuis…). */
	public array $criteria;
	public int $offset;
	public int $limit;

	/**
	 * @param array<string, string> $criteria
	 */
	public function __construct( string $slice_key, array $criteria, int $offset = 0, int $limit = 50 ) {
		$this->slice_key = $slice_key;
		$this->criteria  = $criteria;
		$this->offset    = max( 0, $offset );
		$this->limit     = max( 1, min( 150, $limit ) ); // FT : 150 max/appel
	}
}
