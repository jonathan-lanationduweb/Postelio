<?php
/**
 * Provider e-mail V1 (développement) : s'appuie sur wp_mail(). En production, un vrai
 * provider transactionnel devra le remplacer (délivrabilité, bounces, webhooks) via le
 * filtre `postelio/notifications/email_provider`.
 *
 * @package Postelio\Notifications\Email
 */

namespace Postelio\Notifications\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WpMailProvider implements EmailProvider {

	public function name(): string {
		return 'wp_mail';
	}

	public function send( EmailMessage $message ): DeliveryResult {
		if ( ! $message->valid() ) {
			return DeliveryResult::failure( 'invalid_message' );
		}

		$to      = '' !== $message->to_name ? sprintf( '%s <%s>', $message->to_name, $message->to ) : $message->to;
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$body = $message->body_text;
		if ( '' !== $message->cta_url ) {
			$body .= "\n\n" . ( '' !== $message->cta_label ? $message->cta_label : 'Ouvrir' ) . ' : ' . $message->cta_url;
		}

		$ok = function_exists( 'wp_mail' ) ? (bool) wp_mail( $to, $message->subject, $body, $headers ) : false;

		return $ok
			? DeliveryResult::success( 'wpmail:' . substr( md5( $message->to . $message->subject ), 0, 12 ) )
			: DeliveryResult::failure( 'wp_mail_returned_false' );
	}
}
