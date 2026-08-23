<?php
/**
 * Contrat d'une source d'offres externe. Chaque provider (France Travail, futurs
 * partenaires) l'implémente. Le reste du plugin ne manipule JAMAIS le payload brut du
 * provider : il passe par `normalize()` → NormalizedExternalJob.
 *
 * @package Postelio\JobSources\Sources
 */

namespace Postelio\JobSources\Sources;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_JOBSOURCES_TESTING' ) ) {
		exit;
	}
}

interface JobSourceProvider {

	/** Clé stable (ex. `france_travail`). */
	public function get_key(): string;

	/** Libellé affichable (ex. `France Travail`). */
	public function get_name(): string;

	/** La source est-elle activée + configurée (secrets présents) ? */
	public function is_available(): bool;

	/**
	 * Données d'attribution obligatoires (source, licence, logo…) — centralisées ici.
	 *
	 * @return array<string, mixed>
	 */
	public function get_attribution(): array;

	/** Le provider supporte-t-il un fetch incrémental (fenêtre de dates) ? */
	public function supports_incremental(): bool;

	/**
	 * Récupère une page d'offres BRUTES pour un slice donné.
	 *
	 * @param SyncQuery $query
	 * @return PageResult
	 */
	public function fetch_page( SyncQuery $query ): PageResult;

	/**
	 * Récupère une offre brute par son identifiant externe (ou null).
	 *
	 * @return array<string, mixed>|null
	 */
	public function fetch_offer( string $external_id ): ?array;

	/**
	 * Normalise un payload brut provider en NormalizedExternalJob (ou null si invalide).
	 *
	 * @param array<string, mixed> $raw
	 */
	public function normalize( array $raw ): ?NormalizedExternalJob;
}
