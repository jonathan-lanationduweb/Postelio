<?php
/**
 * Tests unitaires SANS dépendance (ni PHPUnit ni WordPress) du domaine skills.
 *
 *   php plugins/postelio-skills/tests/run-unit.php
 *
 * Couvre la logique PURE : SkillStateMachine (transitions) et SkillSanitizer (titre/résumé
 * bornés, tags dédupliqués, normalisation des blocs `details`). La sanitization HTML/XSS
 * (`wp_kses`) est testée dans smoke.php (WordPress réel).
 *
 * @package Postelio\Skills\Tests
 */

declare( strict_types=1 );

define( 'POSTELIO_SKILLS_TESTING', true );

// --- Shims minimalistes ------------------------------------------------------
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $s ) ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $s ) {
		return trim( wp_strip_all_tags( (string) $s ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) {
		return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) {
		return abs( (int) $n );
	}
}

$src = dirname( __DIR__ ) . '/src/';
require_once $src . 'Skills/SkillStateMachine.php';
require_once $src . 'Skills/SkillSanitizer.php';

use Postelio\Skills\Skills\SkillSanitizer as San;
use Postelio\Skills\Skills\SkillStateMachine as SM;

$tests  = 0;
$failed = array();
function check( string $label, bool $cond ): void {
	global $tests, $failed;
	++$tests;
	echo $cond ? "  [ok]   {$label}\n" : "  [FAIL] {$label}\n";
	if ( ! $cond ) {
		$failed[] = $label;
	}
}

echo "== SkillStateMachine ==\n";
check( '3 statuts', count( SM::all() ) === 3 );
check( 'draft -> published', SM::can_transition( SM::DRAFT, SM::PUBLISHED ) );
check( 'draft -> archived', SM::can_transition( SM::DRAFT, SM::ARCHIVED ) );
check( 'published -> draft (édition bloquée)', SM::can_transition( SM::PUBLISHED, SM::DRAFT ) );
check( 'published -> archived', SM::can_transition( SM::PUBLISHED, SM::ARCHIVED ) );
check( 'archived -> draft', SM::can_transition( SM::ARCHIVED, SM::DRAFT ) );
check( 'archived -/-> published direct', ! SM::can_transition( SM::ARCHIVED, SM::PUBLISHED ) );
check( 'statut inconnu rejeté', ! SM::is_status( 'hidden' ) ); // hidden = drapeau, pas un statut

echo "== SkillSanitizer : titre / résumé ==\n";
check( 'titre borné à 160', mb_strlen( San::title( str_repeat( 'a', 500 ) ) ) === 160 );
check( 'titre strippe le HTML', false === strpos( San::title( 'Bonjour <b>gras</b>' ), '<' ) );
check( 'résumé borné à 500', mb_strlen( San::summary( str_repeat( 'b', 900 ) ) ) === 500 );

echo "== SkillSanitizer : tags ==\n";
$tags = San::tags( array( 'PHP', 'php', 'WordPress', '', '  ', 'MySQL' ) );
check( 'tags dédupliqués (casse)', count( $tags ) === 3 );
check( 'tags vides retirés', ! in_array( '', $tags, true ) );
$many = San::tags( array_map( static fn( $i ) => 'tag' . $i, range( 1, 40 ) ) );
check( 'tags bornés à 15', count( $many ) === 15 );

echo "== SkillSanitizer : details (blocs optionnels) ==\n";
$d = San::details( array(
	'metier'   => 'Boulanger <script>x</script>',
	'materiel' => array( 'Farine', 'Eau', '' ),
	'etapes'   => array( array( 'titre' => 'Pétrir', 'texte' => 'Mélanger', 'conseil' => 'Doucement' ), 'texte libre' ),
	'galerie'  => array( '12', 'x', 15 ),
	'inconnu'  => 'ignoré',
) );
check( 'metier nettoyé', false === strpos( (string) $d['metier'], '<' ) );
check( 'materiel filtré (2)', count( $d['materiel'] ) === 2 );
check( 'etapes normalisées (2)', count( $d['etapes'] ) === 2 && isset( $d['etapes'][0]['titre'], $d['etapes'][0]['texte'] ) );
check( 'galerie = IDs entiers', $d['galerie'] === array( 12, 15 ) );
check( 'clé inconnue ignorée', ! isset( $d['inconnu'] ) );

echo "\n";
if ( empty( $failed ) ) {
	echo "RÉSULTAT : {$tests} assertions, 0 échec. OK\n";
	exit( 0 );
}
echo 'RÉSULTAT : ' . count( $failed ) . " échec(s) sur {$tests} assertions.\n";
exit( 1 );
