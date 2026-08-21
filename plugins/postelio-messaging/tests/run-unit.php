<?php
/**
 * Tests unitaires postelio-messaging SANS dépendance (ni PHPUnit ni WordPress).
 *
 *   php plugins/postelio-messaging/tests/run-unit.php
 *
 * @package Postelio\Messaging\Tests
 */

declare( strict_types=1 );
define( 'POSTELIO_CORE_TESTING', true );

if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v, ...$a ) { return $v; } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $s ) { return trim( wp_strip_all_tags_shim( (string) $s ) ); }
}
function wp_strip_all_tags_shim( $s ) { return preg_replace( '/<[^>]*>/', '', $s ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }

require_once dirname( __DIR__ ) . '/src/Conversations/ConversationStateMachine.php';
require_once dirname( __DIR__ ) . '/src/Conversations/MessageNormalizer.php';
use Postelio\Messaging\Conversations\ConversationStateMachine as SM;
use Postelio\Messaging\Conversations\MessageNormalizer as MN;

$tests = 0; $failed = array();
function check( string $l, bool $c ): void { global $tests,$failed; ++$tests; echo ($c?'  [ok]   ':'  [FAIL] ').$l."\n"; if(!$c)$failed[]=$l; }

echo "== ConversationStateMachine ==\n";
check( '3 statuts', count( SM::statuses() ) === 3 );
check( 'active autorise l\'envoi', SM::can_send( 'active' ) );
check( 'closed interdit l\'envoi', ! SM::can_send( 'closed' ) );
check( 'archived interdit l\'envoi', ! SM::can_send( 'archived' ) );
check( 'active → closed', SM::can_transition( 'active', 'closed' ) );
check( 'active → archived', SM::can_transition( 'active', 'archived' ) );
check( 'closed → active (réouverture)', SM::can_transition( 'closed', 'active' ) );
check( 'active → active INTERDIT', ! SM::can_transition( 'active', 'active' ) );

echo "== MessageNormalizer ==\n";
$ok = MN::normalize( '  Bonjour, êtes-vous disponible ?  ' );
check( 'texte normal ok', $ok['ok'] === true );
check( 'texte trimé', $ok['value'] === 'Bonjour, êtes-vous disponible ?' );
check( 'accents/unicode conservés', str_contains( $ok['value'], 'êtes' ) );
$emoji = MN::normalize( 'Merci 🙂 à bientôt' );
check( 'emoji conservé', str_contains( $emoji['value'], '🙂' ) );

$empty = MN::normalize( '   ' );
check( 'message vide refusé', $empty['ok'] === false );

echo "== XSS (contenu neutralisé) ==\n";
$xss = MN::normalize( '<script>alert(1)</script>Coucou' );
check( 'script retiré (ok)', $xss['ok'] === true );
check( 'pas de balise <script> dans la valeur', ! str_contains( $xss['value'], '<script>' ) && ! str_contains( $xss['value'], '</script>' ) );
check( 'texte utile conservé', str_contains( $xss['value'], 'Coucou' ) );
$img = MN::normalize( '<img src=x onerror=alert(1)>' );
check( 'balise seule => vide => refusée', $img['ok'] === false );
$link = MN::normalize( '<a href="javascript:alert(1)">clique</a>' );
check( 'lien HTML retiré, texte gardé', $link['ok'] === true && ! str_contains( $link['value'], '<a' ) && str_contains( $link['value'], 'clique' ) );

echo "== Longueur max ==\n";
$long = MN::normalize( str_repeat( 'a', 6000 ) );
check( 'au-delà de 5000 refusé', $long['ok'] === false );
$edge = MN::normalize( str_repeat( 'a', 5000 ) );
check( 'exactement 5000 accepté', $edge['ok'] === true );

echo "\n";
if ( empty( $failed ) ) { echo "RÉSULTAT : {$tests} assertions, 0 échec. OK\n"; exit( 0 ); }
echo 'RÉSULTAT : '.count($failed)." échec(s) sur {$tests}.\n"; exit( 1 );
