<?php
/**
 * Plugin name: Postelio — transport e-mail LOCAL (développement)
 * Description: Branche wp_mail() sur un serveur SMTP de développement (ex. Mailpit) via `phpmailer_init`, UNIQUEMENT si les constantes POSTELIO_LOCAL_SMTP_* sont définies dans wp-config.php. Sans constante : inerte. Ne concerne pas la production.
 *
 * Installation locale : copier ce fichier dans wordpress/wp-content/mu-plugins/ (non versionné) puis
 * définir dans wp-config.php :
 *   define( 'POSTELIO_LOCAL_SMTP_HOST', '127.0.0.1' );
 *   define( 'POSTELIO_LOCAL_SMTP_PORT', 1025 );
 *   define( 'POSTELIO_LOCAL_MAIL_FROM', 'noreply@postelio.test' );        // optionnel
 *   define( 'POSTELIO_LOCAL_MAIL_FROM_NAME', 'Postelio (local)' );        // optionnel
 *
 * La logique métier (postelio-notifications → EmailDispatcher → WpMailProvider → wp_mail) ne change pas :
 * seul le transport sous-jacent de PHPMailer est configuré ici. Aucun secret, aucune authentification.
 *
 * @package Postelio\LocalTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'POSTELIO_LOCAL_SMTP_HOST' ) || '' === (string) POSTELIO_LOCAL_SMTP_HOST ) {
	return; // aucune configuration locale → wp_mail() garde son comportement par défaut
}

add_action(
	'phpmailer_init',
	static function ( $phpmailer ): void {
		$phpmailer->isSMTP();
		$phpmailer->Host        = (string) POSTELIO_LOCAL_SMTP_HOST;
		$phpmailer->Port        = defined( 'POSTELIO_LOCAL_SMTP_PORT' ) ? (int) POSTELIO_LOCAL_SMTP_PORT : 1025;
		$phpmailer->SMTPAuth    = false;
		$phpmailer->SMTPAutoTLS = false; // serveur de capture local, pas de TLS
		$phpmailer->SMTPSecure  = '';
		$phpmailer->Timeout     = 5;
	},
	5
);

if ( defined( 'POSTELIO_LOCAL_MAIL_FROM' ) && is_email( (string) POSTELIO_LOCAL_MAIL_FROM ) ) {
	// Remplace uniquement l'expéditeur par défaut de WordPress (wordpress@<host>), jamais un From explicite.
	add_filter( 'wp_mail_from', static function ( string $from ): string {
		return 0 === strpos( $from, 'wordpress@' ) ? (string) POSTELIO_LOCAL_MAIL_FROM : $from;
	} );
	add_filter( 'wp_mail_from_name', static function ( string $name ): string {
		return ( 'WordPress' === $name && defined( 'POSTELIO_LOCAL_MAIL_FROM_NAME' ) ) ? (string) POSTELIO_LOCAL_MAIL_FROM_NAME : $name;
	} );
}
