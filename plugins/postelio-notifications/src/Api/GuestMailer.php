<?php
/**
 * Contrat public d'envoi d'e-mail à une adresse NON encore rattachée à un compte
 * (parcours guest). Destiné à postelio-applications.
 *
 * La logique métier n'appelle jamais `wp_mail()` directement : ce mailer rend le message
 * via `TemplateRegistry` et l'envoie via le même `EmailProvider` que le reste des e-mails
 * (WpMailProvider en dev, provider transactionnel en prod via le filtre
 * `postelio/notifications/email_provider`). Un e-mail guest n'ayant pas de `user_id`, il ne
 * passe pas par la file utilisateur (résolution du destinataire par compte) : il est rendu
 * puis envoyé directement au provider, à l'adresse fournie.
 *
 * @package Postelio\Notifications\Api
 */

namespace Postelio\Notifications\Api;

use Postelio\Notifications\Email\EmailProvider;
use Postelio\Notifications\Email\TemplateRegistry;
use Postelio\Notifications\Email\WpMailProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GuestMailer {

	/**
	 * Envoie un e-mail transactionnel guest. Retourne true si le provider a accepté l'envoi.
	 *
	 * @param array<string, string> $vars Variables de template (dont recipient_name).
	 */
	public static function send( string $email, string $template, string $cta_url, array $vars = array(), string $to_name = '' ): bool {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return false;
		}
		$message = TemplateRegistry::render( $template, $email, $to_name, $cta_url, $vars );
		if ( null === $message ) {
			return false;
		}
		$result = self::provider()->send( $message );
		return isset( $result->ok ) ? (bool) $result->ok : true;
	}

	private static function provider(): EmailProvider {
		$provider = apply_filters( 'postelio/notifications/email_provider', null );
		return $provider instanceof EmailProvider ? $provider : new WpMailProvider();
	}
}
