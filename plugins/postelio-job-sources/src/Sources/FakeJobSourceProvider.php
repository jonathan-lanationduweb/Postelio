<?php
/**
 * Provider FACTICE pour les tests (aucun appel réseau). Réutilise le mapping réel de
 * FranceTravailProvider::normalize() sur des fixtures au format France Travail, afin de
 * tester la normalisation, l'upsert, la recherche et le redirect sans dépendre de l'API.
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

final class FakeJobSourceProvider implements JobSourceProvider {

	/** @var array<int, array<string,mixed>> Offres brutes (format FT) servies par la source. */
	public array $offers = array();
	public bool $available = true;
	public bool $throw_on_fetch = false;
	public string $throw_message = 'simulated_error';

	private FranceTravailProvider $mapper;

	public function __construct() {
		$this->mapper = new FranceTravailProvider();
	}

	public function get_key(): string {
		return FranceTravailProvider::KEY; // s'insère sous la même source pour un test réaliste
	}
	public function get_name(): string {
		return 'France Travail (fake)';
	}
	public function is_available(): bool {
		return $this->available;
	}
	public function supports_incremental(): bool {
		return true;
	}
	/** @return array<string, mixed> */
	public function get_attribution(): array {
		return $this->mapper->get_attribution();
	}

	public function fetch_page( SyncQuery $query ): PageResult {
		if ( $this->throw_on_fetch ) {
			throw new \RuntimeException( $this->throw_message );
		}
		$slice = array_slice( $this->offers, $query->offset, $query->limit );
		$total = count( $this->offers );
		return new PageResult( $slice, $total, ( $query->offset + count( $slice ) ) < $total );
	}

	/** @return array<string, mixed>|null */
	public function fetch_offer( string $external_id ): ?array {
		foreach ( $this->offers as $o ) {
			if ( (string) ( $o['id'] ?? '' ) === $external_id ) {
				return $o;
			}
		}
		return null;
	}

	public function normalize( array $raw ): ?NormalizedExternalJob {
		return $this->mapper->normalize( $raw );
	}
}
