<?php
/**
 * Résultat d'un envoi par un EmailProvider.
 *
 * @package Postelio\Notifications\Email
 */

namespace Postelio\Notifications\Email;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_NOTIFICATIONS_TESTING' ) ) {
		exit;
	}
}

final class DeliveryResult {

	public bool $ok;
	public string $provider_message_id;
	public string $error;

	private function __construct( bool $ok, string $provider_message_id, string $error ) {
		$this->ok                  = $ok;
		$this->provider_message_id = $provider_message_id;
		$this->error               = $error;
	}

	public static function success( string $provider_message_id = '' ): self {
		return new self( true, $provider_message_id, '' );
	}

	public static function failure( string $error ): self {
		return new self( false, '', $error );
	}
}
