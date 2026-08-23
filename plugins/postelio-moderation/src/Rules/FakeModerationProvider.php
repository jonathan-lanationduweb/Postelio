<?php
/**
 * Provider FACTICE pour les tests (aucun réseau). Réponses scriptables par mot-clé.
 *
 * @package Postelio\Moderation\Rules
 */

namespace Postelio\Moderation\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_MODERATION_TESTING' ) ) {
		exit;
	}
}

final class FakeModerationProvider implements ModerationProvider {

	public bool $available = true;
	/** @var array{risk_level:string,reason_codes:array<int,string>}|null */
	public ?array $forced = null;

	public function name(): string {
		return 'fake';
	}
	public function is_available(): bool {
		return $this->available;
	}
	public function moderate_text( string $text, string $resource_type ): array {
		if ( null !== $this->forced ) {
			return $this->forced;
		}
		return array( 'risk_level' => 'low', 'reason_codes' => array() );
	}
}
