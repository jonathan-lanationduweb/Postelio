<?php
/**
 * Résultat d'un fetch_page : offres brutes + info de pagination (total source, s'il reste
 * des pages) permettant à l'orchestrateur de continuer/reprendre.
 *
 * @package Postelio\JobSources\Sources
 */

namespace Postelio\JobSources\Sources;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_JOBSOURCES_TESTING' ) ) {
		exit;
	}
}

final class PageResult {

	/** @var array<int, array<string,mixed>> Offres BRUTES du provider. */
	public array $raw_offers;
	public int $total;
	public bool $has_more;

	/**
	 * @param array<int, array<string,mixed>> $raw_offers
	 */
	public function __construct( array $raw_offers, int $total, bool $has_more ) {
		$this->raw_offers = $raw_offers;
		$this->total      = $total;
		$this->has_more   = $has_more;
	}
}
