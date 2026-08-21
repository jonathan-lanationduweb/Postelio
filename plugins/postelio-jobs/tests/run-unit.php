<?php
/**
 * Tests unitaires postelio-jobs SANS dépendance (ni PHPUnit ni WordPress).
 *
 *   php plugins/postelio-jobs/tests/run-unit.php
 *
 * Couvre la machine à états des offres (transitions autorisées/interdites,
 * visibilité publique). La rédaction du presenter (qui interroge companies) est
 * couverte par le smoke.
 *
 * @package Postelio\Jobs\Tests
 */

declare( strict_types=1 );
define( 'POSTELIO_CORE_TESTING', true );

require_once dirname( __DIR__ ) . '/src/Jobs/JobStateMachine.php';
use Postelio\Jobs\Jobs\JobStateMachine as SM;

$tests = 0; $failed = array();
function check( string $l, bool $c ): void { global $tests,$failed; ++$tests; echo ($c?'  [ok]   ':'  [FAIL] ').$l."\n"; if(!$c)$failed[]=$l; }

echo "== JobStateMachine ==\n";
check( '9 états', count( SM::statuses() ) === 9 );
check( 'draft connu', SM::is_status( 'draft' ) );
check( 'statut inconnu rejeté', ! SM::is_status( 'zzz' ) );

echo "== Transitions autorisées ==\n";
check( 'draft → published', SM::can_transition( 'draft', 'published' ) );
check( 'draft → archived', SM::can_transition( 'draft', 'archived' ) );
check( 'published → expiring', SM::can_transition( 'published', 'expiring' ) );
check( 'published → filled', SM::can_transition( 'published', 'filled' ) );
check( 'expiring → expired', SM::can_transition( 'expiring', 'expired' ) );
check( 'published → suspended', SM::can_transition( 'published', 'suspended' ) );
check( 'suspended → published', SM::can_transition( 'suspended', 'published' ) );
check( 'expired → renewed', SM::can_transition( 'expired', 'renewed' ) );
check( 'renewed → published', SM::can_transition( 'renewed', 'published' ) );
check( 'filled → archived', SM::can_transition( 'filled', 'archived' ) );

echo "== Transitions interdites ==\n";
check( 'draft → filled INTERDIT', ! SM::can_transition( 'draft', 'filled' ) );
check( 'draft → expired INTERDIT', ! SM::can_transition( 'draft', 'expired' ) );
check( 'archived → published INTERDIT (recréer via copie)', ! SM::can_transition( 'archived', 'published' ) );
check( 'published → renewed INTERDIT', ! SM::can_transition( 'published', 'renewed' ) );
check( 'filled → published INTERDIT', ! SM::can_transition( 'filled', 'published' ) );
check( 'expired → published direct INTERDIT', ! SM::can_transition( 'expired', 'published' ) );
check( 'vers statut inconnu INTERDIT', ! SM::can_transition( 'draft', 'zzz' ) );

echo "== Visibilité publique ==\n";
check( 'published public', SM::is_public( 'published' ) );
check( 'expiring public', SM::is_public( 'expiring' ) );
check( 'draft NON public', ! SM::is_public( 'draft' ) );
check( 'expired NON public', ! SM::is_public( 'expired' ) );
check( 'suspended NON public', ! SM::is_public( 'suspended' ) );
check( 'filled NON public', ! SM::is_public( 'filled' ) );

echo "\n";
if ( empty( $failed ) ) { echo "RÉSULTAT : {$tests} assertions, 0 échec. OK\n"; exit( 0 ); }
echo 'RÉSULTAT : '.count($failed)." échec(s) sur {$tests}.\n"; exit( 1 );
