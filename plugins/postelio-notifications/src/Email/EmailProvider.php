<?php
/**
 * Contrat d'un fournisseur d'e-mail. La logique métier n'appelle JAMAIS wp_mail()
 * directement : tout passe par Router → EmailDispatcher → file → EmailProvider.
 *
 * V1 développement : WpMailProvider. Production : provider transactionnel réel
 * (SMTP/Brevo/SES/Postmark…), non choisi — branché via le filtre
 * `postelio/notifications/email_provider` sans toucher au reste.
 *
 * @package Postelio\Notifications\Email
 */

namespace Postelio\Notifications\Email;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_NOTIFICATIONS_TESTING' ) ) {
		exit;
	}
}

interface EmailProvider {

	/** Identifiant court du provider (traçabilité). */
	public function name(): string;

	/** Envoie un e-mail. Ne lève pas : encapsule le résultat dans DeliveryResult. */
	public function send( EmailMessage $message ): DeliveryResult;
}
