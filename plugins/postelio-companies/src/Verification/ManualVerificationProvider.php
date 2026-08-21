<?php
/**
 * Provider par défaut : aucune API externe. Toute demande passe en revue manuelle
 * (décision administrateur). Choix de sûreté V1 (integrations.md).
 *
 * @package Postelio\Companies\Verification
 */

namespace Postelio\Companies\Verification;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class ManualVerificationProvider implements VerificationProvider {

	public function name(): string {
		return 'manual';
	}

	/**
	 * @param array<string, mixed> $legal_declared
	 * @return array{outcome:string, legal?:array<string,mixed>, motif?:string}
	 */
	public function check( array $legal_declared ): array {
		return array( 'outcome' => 'manual_review' );
	}
}
