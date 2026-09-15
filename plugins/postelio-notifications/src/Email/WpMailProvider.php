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

		// Capture de la CAUSE réelle d'un échec (PHPMailer via `wp_mail_failed`) pour que la file
		// journalise un motif exploitable ("Impossible d'instancier la fonction mail" = aucun
		// transport local) plutôt qu'un simple booléen. Jamais le corps ni le destinataire.
		$captured = null;
		$capture  = static function ( $error ) use ( &$captured ): void {
			if ( $error instanceof \WP_Error ) {
				$captured = $error;
			}
		};
		if ( function_exists( 'add_action' ) ) {
			add_action( 'wp_mail_failed', $capture );
		}
		$ok = function_exists( 'wp_mail' ) ? (bool) wp_mail( $to, $message->subject, $body, $headers ) : false;
		if ( function_exists( 'remove_action' ) ) {
			remove_action( 'wp_mail_failed', $capture );
		}

		if ( $ok ) {
			// wp_mail() = true signifie « remis au transport PHP/SMTP », PAS une preuve de livraison.
			return DeliveryResult::success( 'wpmail:' . substr( md5( $message->to . $message->subject ), 0, 12 ) );
		}
		$reason = 'wp_mail_returned_false';
		if ( $captured instanceof \WP_Error ) {
			$reason = 'wp_mail_failed: ' . self::reason_from_error( $captured );
		}
		return DeliveryResult::failure( $reason );
	}

	/** Motif court et sûr issu d'un WP_Error PHPMailer (message + code exception, sans données). */
	public static function reason_from_error( \WP_Error $error ): string {
		$msg  = trim( wp_strip_all_tags( (string) $error->get_error_message() ) );
		$data = $error->get_error_data();
		$code = is_array( $data ) && isset( $data['phpmailer_exception_code'] ) ? (int) $data['phpmailer_exception_code'] : 0;
		return substr( ( '' !== $msg ? $msg : 'erreur PHPMailer' ) . ( $code > 0 ? ' (code ' . $code . ')' : '' ), 0, 200 );
	}

	/**
	 * Description du transport RÉELLEMENT utilisé par wp_mail() sur ce serveur (aucun secret) :
	 * WP Mail SMTP, un SMTP branché via `phpmailer_init` (hôte:port lus sur l'objet PHPMailer tel que
	 * wp_mail() le configurerait — jamais d'identifiant ni de mot de passe), ou la fonction mail()
	 * de PHP (sendmail ou SMTP déclaré dans php.ini). Sert au diagnostic admin.
	 *
	 * @return array{label:string, detail:string, smtp_configured:bool, mailer:string, host:string, port:int, local:bool}
	 */
	public static function transport(): array {
		$base = array( 'mailer' => 'mail', 'host' => '', 'port' => 0, 'local' => false );
		if ( class_exists( '\\WPMailSMTP\\Core' ) ) {
			return array_merge( $base, array( 'label' => 'wp_mail via WP Mail SMTP', 'detail' => 'Transport géré par le plugin WP Mail SMTP.', 'smtp_configured' => true, 'mailer' => 'plugin' ) );
		}
		if ( function_exists( 'has_filter' ) && has_filter( 'phpmailer_init' ) ) {
			$cfg = self::inspect_phpmailer();
			if ( 'smtp' === $cfg['mailer'] && '' !== $cfg['host'] ) {
				$endpoint = $cfg['host'] . ( $cfg['port'] > 0 ? ':' . $cfg['port'] : '' );
				return array_merge( $base, $cfg, array(
					'label'           => 'wp_mail via SMTP ' . $endpoint,
					'detail'          => $cfg['local']
						? 'SMTP sur l\'hôte local (' . $endpoint . ') : serveur de capture de développement — les e-mails ne sont pas remis à de vrais destinataires.'
						: 'SMTP configuré par code (hook phpmailer_init) : ' . $endpoint . '.',
					'smtp_configured' => true,
				) );
			}
			return array_merge( $base, $cfg, array( 'label' => 'wp_mail via SMTP (phpmailer_init)', 'detail' => 'Un transport est branché par un plugin ou du code (hook phpmailer_init).', 'smtp_configured' => true ) );
		}
		$sendmail = (string) ini_get( 'sendmail_path' );
		if ( '' !== trim( $sendmail ) ) {
			return array_merge( $base, array( 'label' => 'wp_mail → PHP mail() (sendmail)', 'detail' => 'sendmail_path = ' . $sendmail, 'smtp_configured' => false, 'mailer' => 'sendmail' ) );
		}
		$host = (string) ini_get( 'SMTP' );
		$port = (string) ini_get( 'smtp_port' );
		return array_merge( $base, array(
			'label'           => 'wp_mail → PHP mail() (SMTP php.ini)',
			'detail'          => 'PHP tente un SMTP local : ' . ( '' !== $host ? $host : 'localhost' ) . ':' . ( '' !== $port ? $port : '25' ) . ' — aucun provider transactionnel ni SMTP Postelio configuré.',
			'smtp_configured' => false,
			'host'            => '' !== $host ? $host : 'localhost',
			'port'            => '' !== $port ? (int) $port : 25,
		) );
	}

	/**
	 * Lit la configuration que `phpmailer_init` appliquerait à PHPMailer (même chemin que wp_mail(),
	 * sans envoyer) : type de transport, hôte, port, hôte local ou non. AUCUN secret retourné
	 * (Username/Password ignorés). Renvoie un transport `mail` si PHPMailer est indisponible.
	 *
	 * @return array{mailer:string, host:string, port:int, local:bool}
	 */
	private static function inspect_phpmailer(): array {
		$none = array( 'mailer' => 'mail', 'host' => '', 'port' => 0, 'local' => false );
		if ( ! class_exists( '\\PHPMailer\\PHPMailer\\PHPMailer' ) ) {
			$lib = defined( 'ABSPATH' ) ? ABSPATH . WPINC . '/PHPMailer/' : '';
			if ( '' === $lib || ! is_readable( $lib . 'PHPMailer.php' ) ) {
				return $none;
			}
			require_once $lib . 'PHPMailer.php';
			require_once $lib . 'SMTP.php';
			require_once $lib . 'Exception.php';
		}
		try {
			$m = new \PHPMailer\PHPMailer\PHPMailer( true );
			do_action_ref_array( 'phpmailer_init', array( &$m ) );
		} catch ( \Throwable $e ) {
			return $none;
		}
		$mailer = strtolower( (string) $m->Mailer );
		$host   = 'smtp' === $mailer ? trim( (string) $m->Host ) : '';
		$local  = '' !== $host && in_array( strtolower( $host ), array( '127.0.0.1', 'localhost', '::1' ), true );
		return array( 'mailer' => $mailer, 'host' => $host, 'port' => 'smtp' === $mailer ? (int) $m->Port : 0, 'local' => $local );
	}
}
