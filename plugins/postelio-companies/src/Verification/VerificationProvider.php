<?php
/**
 * Contrat d'un fournisseur de vérification d'entreprise (Sirene/RNE plus tard).
 *
 * AUCUN provider réel n'est branché dans ce lot (integrations.md). Le défaut renvoie
 * `manual_review` : la décision revient à un administrateur.
 *
 * @package Postelio\Companies\Verification
 */

namespace Postelio\Companies\Verification;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

interface VerificationProvider {

	/** Identifiant du provider (ex. "manual", "sirene"). */
	public function name(): string;

	/**
	 * Évalue une identité légale déclarée.
	 *
	 * @param array<string, mixed> $legal_declared
	 * @return array{outcome:string, legal?:array<string,mixed>, motif?:string}
	 *         outcome ∈ verified | manual_review | rejected.
	 */
	public function check( array $legal_declared ): array;
}
