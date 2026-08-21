<?php
/**
 * Tests unitaires postelio-applications SANS dépendance (ni PHPUnit ni WordPress).
 *
 *   php plugins/postelio-applications/tests/run-unit.php
 *
 * Couvre la machine à états + la validation des réponses de présélection.
 *
 * @package Postelio\Applications\Tests
 */

declare( strict_types=1 );
define( 'POSTELIO_CORE_TESTING', true );

require_once dirname( __DIR__ ) . '/src/Applications/ApplicationStateMachine.php';
require_once dirname( __DIR__ ) . '/src/Applications/ScreeningValidator.php';
use Postelio\Applications\Applications\ApplicationStateMachine as SM;
use Postelio\Applications\Applications\ScreeningValidator as SV;

$tests = 0; $failed = array();
function check( string $l, bool $c ): void { global $tests,$failed; ++$tests; echo ($c?'  [ok]   ':'  [FAIL] ').$l."\n"; if(!$c)$failed[]=$l; }

echo "== ApplicationStateMachine ==\n";
check( '7 états', count( SM::statuses() ) === 7 );
check( 'new actif', SM::is_active( 'new' ) );
check( 'selected terminal', SM::is_terminal( 'selected' ) );
check( 'rejected terminal', SM::is_terminal( 'rejected' ) );
check( 'withdrawn terminal', SM::is_terminal( 'withdrawn' ) );

echo "== Transitions autorisées ==\n";
check( 'new → review', SM::can_transition( 'new', 'review' ) );
check( 'review → shortlisted', SM::can_transition( 'review', 'shortlisted' ) );
check( 'shortlisted → interview', SM::can_transition( 'shortlisted', 'interview' ) );
check( 'interview → selected', SM::can_transition( 'interview', 'selected' ) );
check( 'review → rejected', SM::can_transition( 'review', 'rejected' ) );
check( 'new → withdrawn', SM::can_transition( 'new', 'withdrawn' ) );
check( 'interview → shortlisted (retour arrière actif)', SM::can_transition( 'interview', 'shortlisted' ) );

echo "== Transitions interdites ==\n";
check( 'selected → review INTERDIT', ! SM::can_transition( 'selected', 'review' ) );
check( 'rejected → new INTERDIT', ! SM::can_transition( 'rejected', 'new' ) );
check( 'withdrawn → review INTERDIT', ! SM::can_transition( 'withdrawn', 'review' ) );
check( 'review → new INTERDIT (new jamais cible)', ! SM::can_transition( 'review', 'new' ) );
check( 'withdrawn PAS une cible recruteur', ! in_array( 'withdrawn', SM::RECRUITER_TARGETS, true ) );
check( 'new PAS une cible recruteur', ! in_array( 'new', SM::RECRUITER_TARGETS, true ) );

echo "== ScreeningValidator ==\n";
$questions = array(
	array( 'id' => 'permis', 'label' => 'Permis B ?', 'type' => 'oui_non', 'required' => true ),
	array( 'id' => 'exp', 'label' => 'Années d\'exp ?', 'type' => 'nombre', 'required' => true ),
	array( 'id' => 'note', 'label' => 'Message', 'type' => 'texte', 'required' => false ),
);
$ok = SV::validate( $questions, array( 'permis' => 'oui', 'exp' => '5' ) );
check( 'réponses valides => 0 erreur', empty( $ok['errors'] ) );
check( 'oui_non normalisé en bool true', $ok['answers'][0]['answer'] === true );
check( 'nombre normalisé', $ok['answers'][1]['answer'] === 5 );
check( 'label snapshotté', $ok['answers'][0]['question_label'] === 'Permis B ?' );
check( 'optionnelle absente ignorée (2 réponses)', count( $ok['answers'] ) === 2 );

$miss = SV::validate( $questions, array( 'permis' => 'oui' ) );
check( 'obligatoire manquante => erreur', isset( $miss['errors']['exp'] ) );

$bad = SV::validate( $questions, array( 'permis' => 'peut-être', 'exp' => 'abc' ) );
check( 'oui_non invalide => erreur', isset( $bad['errors']['permis'] ) );
check( 'nombre invalide => erreur', isset( $bad['errors']['exp'] ) );

// Le candidat ne peut PAS injecter une question hors snapshot.
$inject = SV::validate( $questions, array( 'permis' => 'non', 'exp' => 2, 'question_pirate' => 'x' ) );
$ids = array_column( $inject['answers'], 'question_id' );
check( 'question hors snapshot ignorée', ! in_array( 'question_pirate', $ids, true ) );
check( 'oui_non "non" => false', $inject['answers'][0]['answer'] === false );

echo "\n";
if ( empty( $failed ) ) { echo "RÉSULTAT : {$tests} assertions, 0 échec. OK\n"; exit( 0 ); }
echo 'RÉSULTAT : '.count($failed)." échec(s) sur {$tests}.\n"; exit( 1 );
