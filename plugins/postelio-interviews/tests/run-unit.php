<?php
/**
 * Tests unitaires postelio-interviews (logique PURE, sans WordPress) :
 *   php plugins/postelio-interviews/tests/run-unit.php
 *
 * Couvre InterviewStateMachine et InterviewValidator (types, fuseaux, dates UTC/DST,
 * durée, URL visio).
 *
 * @package Postelio\Interviews\Tests
 */

define( 'POSTELIO_INTERVIEWS_TESTING', true );

$src = dirname( __DIR__ ) . '/src/Interviews/';
require_once $src . 'InterviewStateMachine.php';
require_once $src . 'InterviewValidator.php';

use Postelio\Interviews\Interviews\InterviewStateMachine as SM;
use Postelio\Interviews\Interviews\InterviewValidator as V;

$tests = 0;
$failed = array();
$check = static function ( string $label, bool $cond ) use ( &$tests, &$failed ): void {
	++$tests;
	if ( ! $cond ) {
		$failed[] = $label;
		echo "  [FAIL] {$label}\n";
	} else {
		echo "  [ok]   {$label}\n";
	}
};

echo "== Machine à états ==\n";
$check( '6 statuts', count( SM::all() ) === 6 );
$check( 'proposed → confirmed', SM::can_transition( SM::PROPOSED, SM::CONFIRMED ) );
$check( 'proposed → declined', SM::can_transition( SM::PROPOSED, SM::DECLINED ) );
$check( 'proposed → reschedule_requested', SM::can_transition( SM::PROPOSED, SM::RESCHEDULE_REQUESTED ) );
$check( 'confirmed → completed', SM::can_transition( SM::CONFIRMED, SM::COMPLETED ) );
$check( 'confirmed → proposed (modif substantielle)', SM::can_transition( SM::CONFIRMED, SM::PROPOSED ) );
$check( 'reschedule_requested → confirmed', SM::can_transition( SM::RESCHEDULE_REQUESTED, SM::CONFIRMED ) );
$check( 'proposed !→ completed', ! SM::can_transition( SM::PROPOSED, SM::COMPLETED ) );
$check( 'declined terminal', SM::is_terminal( SM::DECLINED ) );
$check( 'cancelled terminal', SM::is_terminal( SM::CANCELLED ) );
$check( 'completed terminal', SM::is_terminal( SM::COMPLETED ) );
$check( 'proposed non terminal', ! SM::is_terminal( SM::PROPOSED ) );
$check( 'confirmed actif', SM::is_active( SM::CONFIRMED ) );
$check( 'declined non actif', ! SM::is_active( SM::DECLINED ) );
$check( 'candidat répond en proposed', SM::candidate_can_answer( SM::PROPOSED ) );
$check( 'candidat ne répond pas en confirmed', ! SM::candidate_can_answer( SM::CONFIRMED ) );
$check( 'candidat re-créneau depuis proposed', SM::candidate_can_reschedule( SM::PROPOSED ) );
$check( 'candidat re-créneau depuis confirmed', SM::candidate_can_reschedule( SM::CONFIRMED ) );
$check( 'candidat pas de re-créneau si annulé', ! SM::candidate_can_reschedule( SM::CANCELLED ) );

echo "== Types ==\n";
$check( 'video valide', V::valid_type( 'video' ) );
$check( 'onsite valide', V::valid_type( 'onsite' ) );
$check( 'phone valide', V::valid_type( 'phone' ) );
$check( 'type libre refusé', ! V::valid_type( 'carrier_pigeon' ) );

echo "== Durée ==\n";
$check( '15 min ok', V::valid_duration( 15 ) );
$check( '240 min ok', V::valid_duration( 240 ) );
$check( '0 refusé', ! V::valid_duration( 0 ) );
$check( 'négatif refusé', ! V::valid_duration( -30 ) );
$check( '5 refusé (trop court)', ! V::valid_duration( 5 ) );
$check( '600 refusé (absurde)', ! V::valid_duration( 600 ) );

echo "== Fuseaux ==\n";
$check( 'Europe/Paris valide', V::valid_timezone( 'Europe/Paris' ) );
$check( 'UTC valide', V::valid_timezone( 'UTC' ) );
$check( 'fuseau bidon refusé', ! V::valid_timezone( 'Mars/Olympus' ) );
$check( 'vide refusé', ! V::valid_timezone( '' ) );

echo "== URL visio ==\n";
$check( 'https ok', V::valid_meeting_url( 'https://meet.example.com/abc' ) );
$check( 'http ok', V::valid_meeting_url( 'http://meet.example.com/abc' ) );
$check( 'javascript: refusé', ! V::valid_meeting_url( 'javascript:alert(1)' ) );
$check( 'ftp refusé', ! V::valid_meeting_url( 'ftp://x/y' ) );
$check( 'sans hôte refusé', ! V::valid_meeting_url( 'https:///path' ) );
$check( 'texte simple refusé', ! V::valid_meeting_url( 'pas une url' ) );

echo "== Dates → UTC / DST ==\n";
$check( 'ISO avec Z conservé', V::to_utc( '2025-07-15T12:30:00Z', 'Europe/Paris' ) === '2025-07-15 12:30:00' );
$check( 'Paris hiver (+1) → UTC -1h', V::to_utc( '2025-01-15T14:30:00', 'Europe/Paris' ) === '2025-01-15 13:30:00' );
$check( 'Paris été (+2, DST) → UTC -2h', V::to_utc( '2025-07-15T14:30:00', 'Europe/Paris' ) === '2025-07-15 12:30:00' );
$check( 'offset explicite +02:00', V::to_utc( '2025-07-15T14:30:00+02:00', 'UTC' ) === '2025-07-15 12:30:00' );
$check( 'date invalide → null', null === V::to_utc( 'pas une date', 'UTC' ) );

echo "== validate_slot ==\n";
$now = strtotime( '2025-08-01 00:00:00 UTC' );
$future = V::validate_slot( '2025-08-02T10:00:00Z', 'UTC', 60, $now );
$check( 'créneau futur ok', $future['ok'] === true && $future['scheduled_at'] === '2025-08-02 10:00:00' );
$past = V::validate_slot( '2025-07-01T10:00:00Z', 'UTC', 60, $now );
$check( 'créneau passé refusé', $past['ok'] === false && isset( $past['errors']['scheduled_at'] ) );
$baddur = V::validate_slot( '2025-08-02T10:00:00Z', 'UTC', 3, $now );
$check( 'durée hors bornes refusée', $baddur['ok'] === false && isset( $baddur['errors']['duration_minutes'] ) );
$badtz = V::validate_slot( '2025-08-02T10:00:00Z', 'Nope/Nope', 60, $now );
$check( 'fuseau invalide refusé', $badtz['ok'] === false && isset( $badtz['errors']['timezone'] ) );

echo "\n";
echo 'RÉSULTAT : ' . $tests . ' assertions, ' . count( $failed ) . " échec(s). " . ( empty( $failed ) ? "OK\n" : "ÉCHEC\n" );
exit( empty( $failed ) ? 0 : 1 );
