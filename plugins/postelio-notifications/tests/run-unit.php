<?php
/**
 * Tests unitaires postelio-notifications (logique PURE, sans WordPress) :
 *   php plugins/postelio-notifications/tests/run-unit.php
 *
 * Couvre TemplateRegistry (interpolation, templates, CTA), EmailMessage, DeliveryResult.
 *
 * @package Postelio\Notifications\Tests
 */

define( 'POSTELIO_NOTIFICATIONS_TESTING', true );

$src = dirname( __DIR__ ) . '/src/Email/';
require_once $src . 'EmailMessage.php';
require_once $src . 'DeliveryResult.php';
require_once $src . 'TemplateRegistry.php';

use Postelio\Notifications\Email\DeliveryResult;
use Postelio\Notifications\Email\EmailMessage;
use Postelio\Notifications\Email\TemplateRegistry;

$tests = 0; $failed = array();
$check = static function ( string $label, bool $cond ) use ( &$tests, &$failed ): void {
	++$tests;
	echo ( $cond ? '  [ok]   ' : '  [FAIL] ' ) . $label . "\n";
	if ( ! $cond ) { $failed[] = $label; }
};

echo "== EmailMessage / DeliveryResult ==\n";
$m = new EmailMessage( 'a@b.co', 'Sujet', 'Corps' );
$check( 'message valide', $m->valid() );
$check( 'message sans @ invalide', ! ( new EmailMessage( 'invalide', 'S', 'C' ) )->valid() );
$check( 'message sans sujet invalide', ! ( new EmailMessage( 'a@b.co', '', 'C' ) )->valid() );
$check( 'DeliveryResult success', DeliveryResult::success( 'id1' )->ok === true && DeliveryResult::success( 'id1' )->provider_message_id === 'id1' );
$check( 'DeliveryResult failure', DeliveryResult::failure( 'boom' )->ok === false && DeliveryResult::failure( 'boom' )->error === 'boom' );

echo "== TemplateRegistry ==\n";
$check( 'template connu existe', TemplateRegistry::exists( 'interview_proposed' ) );
$check( 'template inconnu absent', ! TemplateRegistry::exists( 'nope' ) );
$check( 'render template inconnu => null', null === TemplateRegistry::render( 'nope', 'a@b.co', 'Léa', '', array() ) );

$msg = TemplateRegistry::render( 'application_received', 'lea@ex.co', 'Léa', 'https://x/y', array( 'job_title' => 'Développeur', 'company_name' => 'ACME' ) );
$check( 'render ok', $msg instanceof EmailMessage );
$check( 'sujet interpolé', false !== strpos( $msg->subject, 'Développeur' ) );
$check( 'corps interpolé (job + company)', false !== strpos( $msg->body_text, 'Développeur' ) && false !== strpos( $msg->body_text, 'ACME' ) );
$check( 'nom destinataire injecté', false !== strpos( $msg->body_text, 'Léa' ) );
$check( 'cta rempli', '' !== $msg->cta_label && $msg->cta_url === 'https://x/y' );
$check( 'template meta', ( $msg->meta['template'] ?? '' ) === 'application_received' );

$msg2 = TemplateRegistry::render( 'new_message', 'r@ex.co', '', '', array() );
$check( 'jeton inconnu => vide (pas de {})', false === strpos( $msg2->body_text, '{' ) );

echo "== Couverture templates V1 ==\n";
$required = array( 'application_received', 'new_application', 'application_selected', 'application_rejected', 'new_message', 'interview_proposed', 'interview_confirmed_proof', 'interview_declined', 'interview_rescheduled', 'interview_cancelled', 'interview_reminder', 'job_expiring', 'job_expired', 'job_suspended', 'company_verified', 'company_rejected', 'company_suspended' );
foreach ( $required as $tpl ) {
	$check( "template {$tpl} présent", TemplateRegistry::exists( $tpl ) );
}

echo "\n";
echo 'RÉSULTAT : ' . $tests . ' assertions, ' . count( $failed ) . " échec(s). " . ( empty( $failed ) ? "OK\n" : "ÉCHEC\n" );
exit( empty( $failed ) ? 0 : 1 );
