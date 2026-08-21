<?php
/**
 * Message e-mail transactionnel (objet valeur immuable). Construit par TemplateRegistry,
 * envoyé par un EmailProvider. Ne contient jamais de donnée sensible interne.
 *
 * @package Postelio\Notifications\Email
 */

namespace Postelio\Notifications\Email;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_NOTIFICATIONS_TESTING' ) ) {
		exit;
	}
}

final class EmailMessage {

	public string $to;
	public string $to_name;
	public string $subject;
	public string $preheader;
	public string $body_text;
	public string $cta_label;
	public string $cta_url;

	/** @var array<string,string> */
	public array $meta;

	/**
	 * @param array<string,string> $meta
	 */
	public function __construct( string $to, string $subject, string $body_text, string $to_name = '', string $preheader = '', string $cta_label = '', string $cta_url = '', array $meta = array() ) {
		$this->to        = $to;
		$this->to_name   = $to_name;
		$this->subject   = $subject;
		$this->preheader = $preheader;
		$this->body_text = $body_text;
		$this->cta_label = $cta_label;
		$this->cta_url   = $cta_url;
		$this->meta      = $meta;
	}

	public function valid(): bool {
		return '' !== $this->to && false !== strpos( $this->to, '@' ) && '' !== $this->subject;
	}
}
