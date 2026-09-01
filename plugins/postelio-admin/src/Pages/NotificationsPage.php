<?php
/**
 * Notifications — OBSERVABILITÉ (pas une boîte mail, pas les notifications personnelles). État
 * de la file d'e-mails : en attente / en cours / envoyés / échoués / ignorés + prochaine
 * tentative. Aucun contenu privé, aucun destinataire exposé.
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Contracts;
use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NotificationsPage extends Page {

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_ADMIN;
	}

	protected function body(): string {
		if ( ! Contracts::module_active( 'notifications' ) || ! Contracts::has( '\\Postelio\\Notifications\\Api\\NotificationDirectory' ) ) {
			return Ui::toolbar( 'Notifications', 'Suivi des envois d\'e-mails.' ) . Ui::empty_state( 'Module indisponible', 'Le module Notifications n\'est pas actif.', '🔔' );
		}
		$out = Ui::toolbar( 'Notifications', 'Suivi des envois d\'e-mails (aucun contenu, aucun destinataire).' );

		$s = \Postelio\Notifications\Api\NotificationDirectory::delivery_stats();

		$out .= '<div class="pst-admin-grid">';
		$out .= Ui::stat( 'En attente', (string) $s['pending'] );
		$out .= Ui::stat( 'En cours', (string) $s['processing'] );
		$out .= Ui::stat( 'Envoyés', (string) $s['sent'] );
		$out .= Ui::stat( 'Échoués', (string) $s['failed'], '', $s['failed'] > 0 );
		$out .= Ui::stat( 'Ignorés', (string) $s['skipped'] );
		$out .= '</div>';

		$out .= '<div class="pst-admin-grid pst-admin-grid--2">';
		$out .= Ui::card_open( 'File d\'envoi' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Prochaine tentative</dt><dd>' . esc_html( $s['next_retry_at'] ? mysql2date( 'd/m/Y H:i', (string) $s['next_retry_at'] ) : '—' ) . '</dd>';
		$out .= '<dt>Dernier échec</dt><dd>' . esc_html( $s['last_failed_at'] ? mysql2date( 'd/m/Y H:i', (string) $s['last_failed_at'] ) : '—' ) . '</dd>';
		$out .= '</dl>' . Ui::card_close();

		$out .= Ui::card_open( 'Service e-mail' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Transport</dt><dd>' . esc_html( class_exists( '\\WPMailSMTP\\Core' ) ? 'Configuré par WP Mail SMTP' : 'Serveur (wp_mail)' ) . '</dd>';
		$out .= '<dt>Traitement</dt><dd>' . Ui::badge( 'Automatique et récurrent', 'info' ) . '</dd>';
		$out .= '</dl>';
		if ( $s['failed'] > 0 ) {
			$out .= Ui::alert( $s['failed'] . ' envoi(s) en échec définitif — vérifier la configuration e-mail / le provider.', 'warning' );
		} else {
			$out .= Ui::alert( 'Aucun envoi en échec.', 'success' );
		}
		$out .= Ui::card_close();
		$out .= '</div>';
		return $out;
	}
}
