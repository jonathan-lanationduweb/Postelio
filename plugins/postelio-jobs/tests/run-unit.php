<?php
/**
 * Tests unitaires postelio-jobs SANS dépendance (ni PHPUnit ni WordPress).
 *
 *   php plugins/postelio-jobs/tests/run-unit.php
 *
 * Couvre la machine à états V1 (sans pending/renewed) + la normalisation des
 * questions de présélection. Le presenter/lifecycle (WP) sont couverts par le smoke.
 *
 * @package Postelio\Jobs\Tests
 */

declare( strict_types=1 );
define( 'POSTELIO_CORE_TESTING', true );

// Shims WP pour la normalisation des questions.
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); } }

require_once dirname( __DIR__ ) . '/src/Jobs/JobStateMachine.php';
require_once dirname( __DIR__ ) . '/src/Jobs/JobService.php';
use Postelio\Jobs\Jobs\JobStateMachine as SM;
use Postelio\Jobs\Jobs\JobService;

$tests = 0; $failed = array();
function check( string $l, bool $c ): void { global $tests,$failed; ++$tests; echo ($c?'  [ok]   ':'  [FAIL] ').$l."\n"; if(!$c)$failed[]=$l; }

echo "== JobStateMachine V1 ==\n";
check( '7 états (V1)', count( SM::statuses() ) === 7 );
check( 'pending N\'existe PAS', ! SM::is_status( 'pending' ) );
check( 'renewed N\'est PAS un état', ! SM::is_status( 'renewed' ) );

echo "== Transitions autorisées ==\n";
check( 'draft → published', SM::can_transition( 'draft', 'published' ) );
check( 'draft → archived', SM::can_transition( 'draft', 'archived' ) );
check( 'published → expiring', SM::can_transition( 'published', 'expiring' ) );
check( 'published → expired', SM::can_transition( 'published', 'expired' ) );
check( 'published → filled', SM::can_transition( 'published', 'filled' ) );
check( 'published → suspended', SM::can_transition( 'published', 'suspended' ) );
check( 'expiring → expired', SM::can_transition( 'expiring', 'expired' ) );
check( 'expired → published (renouvellement)', SM::can_transition( 'expired', 'published' ) );
check( 'suspended → published (réactivation admin)', SM::can_transition( 'suspended', 'published' ) );
check( 'filled → archived', SM::can_transition( 'filled', 'archived' ) );

echo "== Transitions interdites ==\n";
check( 'draft → filled INTERDIT', ! SM::can_transition( 'draft', 'filled' ) );
check( 'draft → expired INTERDIT', ! SM::can_transition( 'draft', 'expired' ) );
check( 'draft → pending INTERDIT (retiré)', ! SM::can_transition( 'draft', 'pending' ) );
check( 'archived → published INTERDIT', ! SM::can_transition( 'archived', 'published' ) );
check( 'archived → tout INTERDIT', array() === SM::allowed_from( 'archived' ) );
check( 'filled → published INTERDIT', ! SM::can_transition( 'filled', 'published' ) );

echo "== Visibilité publique (chaque état) ==\n";
check( 'published PUBLIC', SM::is_public( 'published' ) );
check( 'expiring PUBLIC', SM::is_public( 'expiring' ) );
check( 'draft NON public', ! SM::is_public( 'draft' ) );
check( 'expired NON public', ! SM::is_public( 'expired' ) );
check( 'filled NON public', ! SM::is_public( 'filled' ) );
check( 'archived NON public', ! SM::is_public( 'archived' ) );
check( 'suspended NON public', ! SM::is_public( 'suspended' ) );

echo "== Normalisation questions de présélection ==\n";
$q = JobService::normalize_questions( array(
	array( 'label' => 'Avez-vous le permis ?', 'type' => 'oui_non', 'required' => true, 'critere' => 'indispensable' ),
	array( 'label' => 'Années d\'expérience ?', 'type' => 'nombre' ),
	array( 'label' => '', 'type' => 'texte' ),            // sans label -> ignorée
	array( 'label' => 'Type invalide', 'type' => 'zzz' ), // type invalide -> texte
	'Question en chaîne simple',                           // string -> label
) );
check( '3 questions valides conservées (label vide ignoré)', count( $q ) === 4 );
check( 'q1 id stable généré', ! empty( $q[0]['id'] ) );
check( 'q1 type oui_non', $q[0]['type'] === 'oui_non' );
check( 'q1 required=true', $q[0]['required'] === true );
check( 'q1 critere indispensable', $q[0]['critere'] === 'indispensable' );
check( 'q2 required défaut false', $q[1]['required'] === false );
check( 'q2 critere défaut null', $q[1]['critere'] === null );
check( 'type invalide -> texte', $q[2]['type'] === 'texte' );
check( 'string -> label + type texte', $q[3]['type'] === 'texte' && $q[3]['label'] === 'Question en chaîne simple' );
$ids = array_column( $q, 'id' );
check( 'ids uniques', count( $ids ) === count( array_unique( $ids ) ) );

echo "\n";
if ( empty( $failed ) ) { echo "RÉSULTAT : {$tests} assertions, 0 échec. OK\n"; exit( 0 ); }
echo 'RÉSULTAT : '.count($failed)." échec(s) sur {$tests}.\n"; exit( 1 );
