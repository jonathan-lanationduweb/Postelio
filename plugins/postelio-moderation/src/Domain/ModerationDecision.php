<?php
/**
 * Décision de modération canonique : allowed | review_required | blocked + risk + reasons.
 * Le domaine appelant ne lit que `allowed`/`blocked`. Message utilisateur GÉNÉRIQUE (jamais
 * les règles de détection).
 *
 * @package Postelio\Moderation\Domain
 */

namespace Postelio\Moderation\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_MODERATION_TESTING' ) ) {
		exit;
	}
}

final class ModerationDecision {

	public const ALLOWED         = 'allowed';
	public const REVIEW_REQUIRED = 'review_required';
	public const BLOCKED         = 'blocked';

	public string $decision;
	public string $risk_level;
	/** @var array<int,string> */
	public array $reason_codes;
	public bool $requires_review;
	public string $policy_version;

	/** @param array<int,string> $reason_codes */
	public function __construct( string $decision, string $risk_level, array $reason_codes, bool $requires_review, string $policy_version ) {
		$this->decision        = $decision;
		$this->risk_level      = $risk_level;
		$this->reason_codes    = $reason_codes;
		$this->requires_review = $requires_review;
		$this->policy_version  = $policy_version;
	}

	public function allowed(): bool {
		return self::BLOCKED !== $this->decision;
	}
	public function blocked(): bool {
		return self::BLOCKED === $this->decision;
	}

	/** @return array<string, mixed> Format loose pour le franchissement de frontière (filtre). */
	public function to_array(): array {
		return array(
			'decision'        => $this->decision,
			'allowed'         => $this->allowed(),
			'blocked'         => $this->blocked(),
			'risk_level'      => $this->risk_level,
			'reason_codes'    => $this->reason_codes,
			'requires_review' => $this->requires_review,
			'policy_version'  => $this->policy_version,
			// Message générique destiné à l'utilisateur (aucune règle exposée).
			'message'         => $this->blocked()
				? 'Ce contenu ne respecte pas les règles de la plateforme et n\'a pas pu être publié.'
				: '',
		);
	}
}
