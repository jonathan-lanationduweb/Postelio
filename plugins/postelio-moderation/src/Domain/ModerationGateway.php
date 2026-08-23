<?php
/**
 * Contrat ENTRANT stable : les domaines (messaging, jobs, skills…) appellent
 * `evaluate()` pour une modération préventive. Combine LocalRuleEngine (V1) + un éventuel
 * ModerationProvider (futur, via filtre). Ouvre/rattache une case si le risque ≥ medium.
 * Les domaines ne lisent JAMAIS les tables moderation ; ils lisent la décision retournée.
 *
 * @package Postelio\Moderation\Domain
 */

namespace Postelio\Moderation\Domain;

use Postelio\Moderation\Cases\CaseService;
use Postelio\Moderation\Reports\ReasonCodes;
use Postelio\Moderation\Rules\LocalRuleEngine;
use Postelio\Moderation\Rules\ModerationProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ModerationGateway {

	private LocalRuleEngine $engine;
	private CaseService $cases;

	public function __construct( LocalRuleEngine $engine, CaseService $cases ) {
		$this->engine = $engine;
		$this->cases  = $cases;
	}

	public function evaluate( EvaluationRequest $req ): ModerationDecision {
		$local = $this->engine->evaluate( $req->text, $req->resource_type );

		// Provider externe (futur) : on prend le risque le plus élevé. V1 : aucun branché.
		$provider = apply_filters( 'postelio/moderation/provider', null );
		if ( $provider instanceof ModerationProvider && $provider->is_available() ) {
			$ext   = $provider->moderate_text( $req->text, $req->resource_type );
			$local = $this->merge( $local, $ext );
		}

		$risk    = (string) $local['risk_level'];
		$reasons = (array) $local['reason_codes'];
		$blocked = in_array( $risk, array( LocalRuleEngine::HIGH, LocalRuleEngine::CRITICAL ), true );
		$review  = in_array( $risk, array( LocalRuleEngine::MEDIUM, LocalRuleEngine::HIGH, LocalRuleEngine::CRITICAL ), true );

		$decision = $blocked
			? ModerationDecision::BLOCKED
			: ( $review ? ModerationDecision::REVIEW_REQUIRED : ModerationDecision::ALLOWED );
		$policy   = (string) apply_filters( 'postelio/moderation/policy_version', '1' );

		// Ouverture/rattachement de case si review/blocked (préventif). Un message est
		// rattaché à la CONVERSATION (1 case active par ressource → pas de spam de cases).
		if ( $review ) {
			list( $case_type, $case_uuid ) = $this->case_resource( $req );
			if ( '' !== $case_uuid ) {
				$priority = $this->risk_to_priority( $risk );
				$this->cases->open_or_attach( $case_type, $case_uuid, $priority, $risk, 'auto', false, $reasons, $req->actor_id > 0 ? $req->actor_id : null );
			}
		}

		return new ModerationDecision( $decision, $risk, $reasons, $review, $policy );
	}

	/**
	 * @param array{risk_level:string,reason_codes:array<int,string>} $a
	 * @param array{risk_level:string,reason_codes:array<int,string>} $b
	 * @return array{risk_level:string,reason_codes:array<int,string>}
	 */
	private function merge( array $a, array $b ): array {
		$order = array( 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4 );
		$risk  = ( ( $order[ $b['risk_level'] ] ?? 0 ) > ( $order[ $a['risk_level'] ] ?? 0 ) ) ? $b['risk_level'] : $a['risk_level'];
		return array( 'risk_level' => $risk, 'reason_codes' => array_values( array_unique( array_merge( $a['reason_codes'], $b['reason_codes'] ) ) ) );
	}

	/** @return array{0:string,1:string} */
	private function case_resource( EvaluationRequest $req ): array {
		if ( 'message' === $req->resource_type ) {
			$uuid = (string) ( $req->context['conversation_uuid'] ?? $req->resource_uuid ?? '' );
			return array( 'conversation', $uuid );
		}
		return array( $req->resource_type, (string) ( $req->resource_uuid ?? '' ) );
	}

	private function risk_to_priority( string $risk ): string {
		return array(
			LocalRuleEngine::MEDIUM   => ReasonCodes::PRIORITY_MEDIUM,
			LocalRuleEngine::HIGH     => ReasonCodes::PRIORITY_HIGH,
			LocalRuleEngine::CRITICAL => ReasonCodes::PRIORITY_CRITICAL,
		)[ $risk ] ?? ReasonCodes::PRIORITY_MEDIUM;
	}
}
