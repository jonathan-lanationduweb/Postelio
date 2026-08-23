<?php
/**
 * Requête d'évaluation préventive soumise par un domaine (messaging, jobs, skills…).
 * Ne transporte que le nécessaire (texte + contexte minimal) — jamais de CV, e-mail
 * complet, candidature entière, etc. (RGPD / privacy).
 *
 * @package Postelio\Moderation\Domain
 */

namespace Postelio\Moderation\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_MODERATION_TESTING' ) ) {
		exit;
	}
}

final class EvaluationRequest {

	public string $resource_type;
	public string $text;
	public int $actor_id;
	public ?string $resource_uuid;
	/** @var array<string, mixed> */
	public array $context;

	/**
	 * @param array<string, mixed> $context
	 */
	public function __construct( string $resource_type, string $text, int $actor_id = 0, ?string $resource_uuid = null, array $context = array() ) {
		$this->resource_type = $resource_type;
		$this->text          = $text;
		$this->actor_id      = $actor_id;
		$this->resource_uuid = $resource_uuid;
		$this->context       = $context;
	}

	/** @param array<string, mixed> $a */
	public static function from_array( array $a ): self {
		return new self(
			(string) ( $a['resource_type'] ?? '' ),
			(string) ( $a['text'] ?? '' ),
			(int) ( $a['actor_id'] ?? 0 ),
			isset( $a['resource_uuid'] ) ? (string) $a['resource_uuid'] : null,
			isset( $a['context'] ) && is_array( $a['context'] ) ? $a['context'] : array()
		);
	}
}
