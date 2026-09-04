<?php
/**
 * Notifications — OBSERVABILITÉ (pas une boîte mail, pas les notifications personnelles). État
 * de la file d'e-mails (en attente / en cours / envoyés / échoués / ignorés), santé du SERVICE
 * e-mail (transport réellement actif, état, dernier test) et vue compacte des échecs définitifs
 * (destinataires MASQUÉS, jamais de contenu). Tout est lu via le contrat public du module
 * Notifications ; l'e-mail de test passe par le même provider que les notifications.
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

	private const DIR = '\\Postelio\\Notifications\\Api\\NotificationDirectory';

	/** Libellés lisibles des templates e-mail (type de notification), sans exposer le contenu. */
	private const TEMPLATE_LABELS = array(
		'application_received'      => 'Accusé de candidature',
		'new_application'           => 'Nouvelle candidature',
		'application_selected'      => 'Candidature retenue',
		'application_rejected'      => 'Candidature non retenue',
		'new_message'               => 'Nouveau message',
		'interview_proposed'        => 'Entretien proposé',
		'interview_confirmed'       => 'Entretien confirmé',
		'interview_confirmed_proof' => 'Confirmation d\'entretien (candidat)',
		'interview_declined'        => 'Entretien décliné',
		'interview_rescheduled'     => 'Entretien reprogrammé',
		'interview_cancelled'       => 'Entretien annulé',
		'interview_reminder'        => 'Rappel d\'entretien',
		'company_verified'          => 'Entreprise vérifiée',
		'company_rejected'          => 'Entreprise rejetée',
		'company_suspended'         => 'Entreprise suspendue',
		'job_expiring'              => 'Offre bientôt expirée',
		'job_expired'               => 'Offre expirée',
		'job_suspended'             => 'Offre suspendue',
	);

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_ADMIN;
	}

	protected function body(): string {
		if ( ! Contracts::module_active( 'notifications' ) || ! Contracts::has( self::DIR ) ) {
			return Ui::toolbar( 'Notifications', 'Suivi des envois d\'e-mails.' ) . Ui::empty_state( 'Module indisponible', 'Le module Notifications n\'est pas actif.', '🔔' );
		}

		$s    = (array) call_user_func( array( self::DIR, 'delivery_stats' ) );
		$view = $this->current( 'view' );

		if ( 'failures' === $view ) {
			$back = '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-notifications' ) ) . '">← Vue d\'ensemble</a>';
			return Ui::toolbar( 'E-mails en échec', 'Livraisons abandonnées après toutes les tentatives (destinataires masqués, aucun contenu).', $back )
				. $this->failures( $s );
		}

		$out  = Ui::toolbar( 'Notifications', 'Suivi des envois d\'e-mails (aucun contenu, destinataires masqués).' );
		$out .= '<div class="pst-admin-grid">';
		$out .= Ui::stat( 'En attente', (string) $s['pending'] );
		$out .= Ui::stat( 'En cours', (string) $s['processing'] );
		$out .= Ui::stat( 'Envoyés', (string) $s['sent'] );
		$out .= Ui::stat( 'Échoués', (string) $s['failed'], '', $s['failed'] > 0 );
		$out .= Ui::stat( 'Ignorés', (string) $s['skipped'] );
		$out .= '</div>';

		$out .= '<div class="pst-admin-grid pst-admin-grid--2">';
		$out .= Ui::card_open( 'File d\'envoi' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Prochaine tentative</dt><dd>' . esc_html( $s['next_retry_at'] ? get_date_from_gmt( (string) $s['next_retry_at'], 'd/m/Y H:i' ) : '—' ) . '</dd>';
		$out .= '<dt>Dernier échec</dt><dd>' . esc_html( $s['last_failed_at'] ? get_date_from_gmt( (string) $s['last_failed_at'], 'd/m/Y H:i' ) : '—' ) . '</dd>';
		$out .= '<dt>Passage du worker</dt><dd>' . esc_html( $this->next_worker_run() ) . '</dd>';
		$out .= '</dl>' . Ui::card_close();
		$out .= $this->service_card( $s );
		$out .= '</div>';
		return $out;
	}

	/** Carte « Service e-mail » : transport réel, traitement, état, dernier test, échecs + actions. */
	private function service_card( array $s ): string {
		$has_diag = method_exists( self::DIR, 'transport' ) && method_exists( self::DIR, 'last_test' );
		$t        = $has_diag
			? (array) call_user_func( array( self::DIR, 'transport' ) )
			: array( 'label' => 'wp_mail', 'detail' => '', 'smtp_configured' => false, 'is_wp_mail' => true );
		$test     = $has_diag ? call_user_func( array( self::DIR, 'last_test' ) ) : null;
		$failed   = (int) ( $s['failed'] ?? 0 );

		// État lisible : le dernier TEST prime (preuve concrète), sinon les échecs, sinon le transport.
		if ( is_array( $test ) && ! empty( $test['ok'] ) ) {
			$state = array( 'Opérationnel', 'success' );
		} elseif ( is_array( $test ) ) {
			$state = array( 'Configuration requise', 'warning' );
		} elseif ( $failed > 0 ) {
			$state = array( 'Incident', 'error' );
		} elseif ( empty( $t['smtp_configured'] ) ) {
			$state = array( 'Non vérifié', 'neutral' );
		} else {
			$state = array( 'Opérationnel', 'success' );
		}

		$out  = Ui::card_open( 'Service e-mail' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Transport</dt><dd>' . esc_html( (string) $t['label'] )
			. ( '' !== (string) ( $t['detail'] ?? '' ) ? '<br><small class="pst-help">' . esc_html( (string) $t['detail'] ) . '</small>' : '' ) . '</dd>';
		$out .= '<dt>Traitement</dt><dd>' . Ui::badge( 'Automatique', 'info' ) . '</dd>';
		$out .= '<dt>État</dt><dd>' . Ui::badge( $state[0], $state[1], true ) . '</dd>';
		if ( is_array( $test ) ) {
			$when = get_date_from_gmt( (string) $test['at'], 'd/m/Y H:i' );
			$out .= '<dt>Dernier test</dt><dd>' . esc_html( $when . ' → ' . (string) ( $test['recipient_masked'] ?? '—' ) ) . ' '
				. ( ! empty( $test['ok'] ) ? Ui::badge( 'Remis au transport', 'success' ) : Ui::badge( 'Échec', 'error' ) )
				. ( empty( $test['ok'] ) && '' !== (string) ( $test['error'] ?? '' ) ? '<br><small class="pst-help">' . esc_html( $this->humanize_error( (string) $test['error'] ) ) . '</small>' : '' )
				. '</dd>';
		}
		$out .= '</dl>';

		if ( $failed > 0 ) {
			$out .= Ui::alert( $failed . ( $failed > 1 ? ' e-mails n\'ont pas pu être envoyés.' : ' e-mail n\'a pas pu être envoyé.' ), 'warning' );
		} else {
			$out .= Ui::alert( 'Aucun envoi en échec.', 'success' );
		}

		$out .= '<div class="pst-admin-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px">';
		if ( $failed > 0 ) {
			$out .= '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-notifications', array( 'view' => 'failures' ) ) ) . '">Voir les échecs</a>';
		}
		if ( $has_diag && method_exists( self::DIR, 'send_test' ) ) {
			$out .= Ui::action_button( 'pst_admin_email_test', array(), 'Envoyer un e-mail de test' );
		}
		$out .= '</div>';
		$out .= '<p class="pst-help">L\'e-mail de test est envoyé à l\'adresse de votre compte, par le même transport que les notifications, sans passer par la file. « Remis au transport » n\'est pas une preuve de réception : vérifiez votre boîte.</p>';
		return $out . Ui::card_close();
	}

	/** Vue compacte des échecs définitifs. */
	private function failures( array $s ): string {
		$rows = method_exists( self::DIR, 'delivery_failures' ) ? (array) call_user_func( array( self::DIR, 'delivery_failures' ), 50 ) : array();
		$out  = '';
		$trs  = array();
		foreach ( $rows as $r ) {
			$trs[] = array(
				Ui::text( '' !== (string) $r['failed_at'] ? get_date_from_gmt( (string) $r['failed_at'], 'd/m/Y H:i' ) : get_date_from_gmt( (string) $r['created_at'], 'd/m/Y H:i' ) ),
				Ui::text( self::TEMPLATE_LABELS[ (string) $r['template'] ] ?? (string) $r['template'], true ),
				Ui::text( (string) $r['recipient_masked'], false, true ),
				Ui::text( (int) $r['attempts'] . ' / ' . (int) $r['max_attempts'] ),
				Ui::text( $this->humanize_error( (string) $r['last_error'] ), false, true ),
				Ui::badge( 'Échec définitif', 'error' ),
			);
		}
		$out .= Ui::card_open( 'Échecs définitifs', (string) count( $rows ) . ' / ' . (int) ( $s['failed'] ?? 0 ) );
		$out .= Ui::table( array( 'Date', 'Type', 'Destinataire', 'Tentatives', 'Erreur', 'État' ), $trs, 'Aucun e-mail en échec.' );
		$out .= Ui::card_close();

		$out .= Ui::card_open( 'Relance' );
		$out .= '<p class="pst-help">Un e-mail passe en « échec définitif » après épuisement de ses tentatives (repli exponentiel 2, 4, 8… min, plafonné à 1 h). '
			. 'Le module Notifications n\'expose pas d\'opération de relance sûre : aucune remise en file n\'est proposée ici (pas d\'écriture directe en base, aucune boucle de relance automatique). '
			. 'Corrigez d\'abord le transport (voir « Service e-mail »), puis vérifiez avec un e-mail de test ; les prochains envois partiront normalement.</p>';
		$out .= Ui::card_close();
		return $out;
	}

	/** Motif technique → phrase compréhensible (sans jargon inutile, sans donnée privée). */
	private function humanize_error( string $error ): string {
		if ( '' === $error ) {
			return '—';
		}
		if ( 'wp_mail_returned_false' === $error ) {
			return 'wp_mail() a renvoyé false : aucun transport e-mail disponible sur le serveur (motif PHPMailer non capturé à l\'époque).';
		}
		if ( 0 === strpos( $error, 'wp_mail_failed: ' ) ) {
			return 'Transport : ' . substr( $error, strlen( 'wp_mail_failed: ' ) );
		}
		$map = array(
			'unknown_template' => 'Template d\'e-mail inconnu.',
			'invalid_message'  => 'Message invalide (destinataire ou sujet manquant).',
			'no_email'         => 'Aucune adresse e-mail valide pour le destinataire.',
		);
		return $map[ $error ] ?? $error;
	}

	/** Prochain passage planifié du worker e-mail (hook Scheduler du core), lisible. */
	private function next_worker_run(): string {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return 'Cron système attendu (WP-Cron désactivé)';
		}
		$ts = wp_next_scheduled( 'postelio_job_notifications_worker' );
		if ( ! $ts ) {
			return 'Récurrent (15 min) — non planifié actuellement';
		}
		return 'Récurrent (15 min) — prochain : ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $ts ), 'd/m/Y H:i' );
	}
}
