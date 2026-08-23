<?php
/**
 * Contrat d'un fournisseur de modération de contenu. V1 : AUCUN provider externe branché
 * (LocalRuleEngine seul). L'interface existe pour permettre plus tard OpenAI Moderation /
 * provider spécialisé / Safe Browsing sans refonte. Retourne le même format que le moteur
 * local : { risk_level, reason_codes }.
 *
 * @package Postelio\Moderation\Rules
 */

namespace Postelio\Moderation\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_MODERATION_TESTING' ) ) {
		exit;
	}
}

interface ModerationProvider {

	public function name(): string;

	public function is_available(): bool;

	/**
	 * @return array{risk_level:string, reason_codes:array<int,string>}
	 */
	public function moderate_text( string $text, string $resource_type ): array;
}
