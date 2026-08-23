<?php
/**
 * Tests unitaires SANS dépendance (ni PHPUnit ni WordPress) du domaine modération.
 *
 * Exécution :  php plugins/postelio-moderation/tests/run-unit.php
 *
 * Couvre la logique PURE : CaseStateMachine (transitions), ReasonCodes (catalogue +
 * politique par ressource + priorités), LocalRuleEngine (classification du risque),
 * ModerationDecision (allowed/blocked + message générique), EvaluationRequest.
 *
 * @package Postelio\Moderation\Tests
 */

declare( strict_types=1 );

define( 'POSTELIO_MODERATION_TESTING', true );

// --- Shims WordPress minimalistes ------------------------------------------
if ( ! function_exists( 'apply_filters' ) ) {
	// LocalRuleEngine lit blocklist_critical / watchlist via apply_filters : on renvoie
	// simplement la valeur par défaut fournie par l'appelant.
	function apply_filters( $hook, $value = null, ...$args ) {
		return $value;
	}
}

// --- Chargement des classes WP-free ----------------------------------------
$src = dirname( __DIR__ ) . '/src/';
require_once $src . 'Cases/CaseStateMachine.php';
require_once $src . 'Reports/ReasonCodes.php';
require_once $src . 'Rules/LocalRuleEngine.php';
require_once $src . 'Domain/ModerationDecision.php';
require_once $src . 'Domain/EvaluationRequest.php';

use Postelio\Moderation\Cases\CaseStateMachine as SM;
use Postelio\Moderation\Domain\EvaluationRequest;
use Postelio\Moderation\Domain\ModerationDecision;
use Postelio\Moderation\Reports\ReasonCodes as RC;
use Postelio\Moderation\Rules\LocalRuleEngine;

// --- Micro-framework d'assertions ------------------------------------------
$tests  = 0;
$failed = array();

function check( string $label, bool $cond ): void {
	global $tests, $failed;
	++$tests;
	if ( ! $cond ) {
		$failed[] = $label;
		echo "  [FAIL] {$label}\n";
	} else {
		echo "  [ok]   {$label}\n";
	}
}

echo "== CaseStateMachine ==\n";
check( '5 états', count( SM::all() ) === 5 );
check( 'open est actif', SM::is_active( SM::OPEN ) );
check( 'in_review est actif', SM::is_active( SM::IN_REVIEW ) );
check( 'escalated est actif', SM::is_active( SM::ESCALATED ) );
check( 'resolved non actif', ! SM::is_active( SM::RESOLVED ) );
check( 'dismissed non actif', ! SM::is_active( SM::DISMISSED ) );
check( 'resolved terminal', SM::is_terminal( SM::RESOLVED ) );
check( 'dismissed terminal', SM::is_terminal( SM::DISMISSED ) );
check( 'open non terminal', ! SM::is_terminal( SM::OPEN ) );
check( 'open -> in_review', SM::can_transition( SM::OPEN, SM::IN_REVIEW ) );
check( 'open -> escalated', SM::can_transition( SM::OPEN, SM::ESCALATED ) );
check( 'in_review -> resolved', SM::can_transition( SM::IN_REVIEW, SM::RESOLVED ) );
check( 'in_review -> dismissed', SM::can_transition( SM::IN_REVIEW, SM::DISMISSED ) );
check( 'escalated -> in_review', SM::can_transition( SM::ESCALATED, SM::IN_REVIEW ) );
check( 'open -/-> resolved', ! SM::can_transition( SM::OPEN, SM::RESOLVED ) );
check( 'resolved -/-> quoi que ce soit', ! SM::can_transition( SM::RESOLVED, SM::IN_REVIEW ) );
check( 'statut inconnu rejeté', ! SM::is_status( 'zzz' ) );

echo "== ReasonCodes ==\n";
check( 'message est signalable', RC::is_resource_type( 'message' ) );
check( 'external_job est signalable', RC::is_resource_type( 'external_job' ) );
check( 'type inconnu non signalable', ! RC::is_resource_type( 'nope' ) );
check( 'harassment valide pour message', RC::is_valid_for( 'message', 'harassment' ) );
check( 'expired_offer invalide pour message', ! RC::is_valid_for( 'message', 'expired_offer' ) );
check( 'expired_offer valide pour external_job', RC::is_valid_for( 'external_job', 'expired_offer' ) );
check( 'fraud => priorité haute', RC::priority_for( 'fraud' ) === RC::PRIORITY_HIGH );
check( 'off_platform_payment => haute', RC::priority_for( 'off_platform_payment' ) === RC::PRIORITY_HIGH );
check( 'expired_offer => basse', RC::priority_for( 'expired_offer' ) === RC::PRIORITY_LOW );
check( 'spam => moyenne (défaut)', RC::priority_for( 'spam' ) === RC::PRIORITY_MEDIUM );
check( 'rank high > medium', RC::rank( RC::PRIORITY_HIGH ) > RC::rank( RC::PRIORITY_MEDIUM ) );
check( 'rank critical max', RC::rank( RC::PRIORITY_CRITICAL ) === 4 );

echo "== LocalRuleEngine ==\n";
$e = new LocalRuleEngine();
$low = $e->evaluate( 'Bonjour, merci pour votre candidature, à bientôt.' );
check( 'texte neutre => low', $low['risk_level'] === LocalRuleEngine::LOW );
check( 'texte neutre => aucun code', $low['reason_codes'] === array() );

$mail = $e->evaluate( 'Contactez-moi directement : jean.dupont@example.com' );
check( 'email => medium', $mail['risk_level'] === LocalRuleEngine::MEDIUM );
check( 'email => contact_bypass', in_array( 'contact_bypass', $mail['reason_codes'], true ) );

$phone = $e->evaluate( 'Appelez le +33 6 12 34 56 78 pour discuter' );
check( 'téléphone => medium', $phone['risk_level'] === LocalRuleEngine::MEDIUM );

$pay = $e->evaluate( 'Envoyez un virement IBAN pour valider votre dossier' );
check( 'paiement hors plateforme => high', $pay['risk_level'] === LocalRuleEngine::HIGH );
check( 'paiement => off_platform_payment', in_array( 'off_platform_payment', $pay['reason_codes'], true ) );

$xss = $e->evaluate( 'Cliquez ici javascript:alert(1)' );
check( 'schéma dangereux => high', $xss['risk_level'] === LocalRuleEngine::HIGH );
check( 'schéma dangereux => malware_link', in_array( 'malware_link', $xss['reason_codes'], true ) );

$threat = $e->evaluate( 'je vais te tuer si tu ne réponds pas' );
check( 'menace explicite => critical', $threat['risk_level'] === LocalRuleEngine::CRITICAL );
check( 'menace => violence_threat', in_array( 'violence_threat', $threat['reason_codes'], true ) );

$spam = $e->evaluate( 'promo http://a.co http://b.co http://c.co http://d.co http://e.co' );
check( 'liens multiples => medium (spam)', $spam['risk_level'] === LocalRuleEngine::MEDIUM );
check( 'liens multiples => spam', in_array( 'spam', $spam['reason_codes'], true ) );

echo "== ModerationDecision ==\n";
$blocked = new ModerationDecision( ModerationDecision::BLOCKED, 'critical', array( 'violence_threat' ), false, '1' );
check( 'blocked->blocked()', $blocked->blocked() );
check( 'blocked !allowed()', ! $blocked->allowed() );
$ba = $blocked->to_array();
check( 'blocked to_array.blocked=true', $ba['blocked'] === true );
check( 'blocked expose un message générique', is_string( $ba['message'] ) && $ba['message'] !== '' );
check( 'message générique n\'expose aucun reason code', strpos( strtolower( $ba['message'] ), 'violence' ) === false );

$review = new ModerationDecision( ModerationDecision::REVIEW_REQUIRED, 'medium', array( 'contact_bypass' ), true, '1' );
check( 'review_required allowed()', $review->allowed() );
check( 'review_required !blocked()', ! $review->blocked() );
check( 'review to_array message vide', $review->to_array()['message'] === '' );

$allow = new ModerationDecision( ModerationDecision::ALLOWED, 'low', array(), false, '1' );
check( 'allowed allowed()', $allow->allowed() );

echo "== EvaluationRequest ==\n";
$req = EvaluationRequest::from_array( array( 'resource_type' => 'message', 'text' => 'x', 'actor_id' => '7', 'context' => array( 'conversation_uuid' => 'abc' ) ) );
check( 'from_array resource_type', $req->resource_type === 'message' );
check( 'from_array actor_id casté', $req->actor_id === 7 );
check( 'from_array resource_uuid null par défaut', $req->resource_uuid === null );
check( 'from_array context préservé', ( $req->context['conversation_uuid'] ?? '' ) === 'abc' );

// --- Bilan ------------------------------------------------------------------
echo "\n";
if ( empty( $failed ) ) {
	echo "RÉSULTAT : {$tests} assertions, 0 échec. OK\n";
	exit( 0 );
}
echo 'RÉSULTAT : ' . count( $failed ) . " échec(s) sur {$tests} assertions.\n";
exit( 1 );
