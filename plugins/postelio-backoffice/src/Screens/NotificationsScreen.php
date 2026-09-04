<?php
/**
 * Service e-mail : observabilité de la file d'envoi + santé du transport réellement actif + vue
 * compacte des échecs définitifs (destinataires MASQUÉS, jamais de contenu) + envoi d'un e-mail de
 * test par le MÊME provider que les notifications. Tout est lu via le contrat public
 * `Notifications\Api\NotificationDirectory` ; aucune écriture, aucune remise en file.
 *
 * @package Postelio\Backoffice\Screens
 */

namespace Postelio\Backoffice\Screens;

use Postelio\Backoffice\Menu;
use Postelio\Backoffice\Support\Data;
use Postelio\Backoffice\Support\Fmt;
use Postelio\Backoffice\Ui\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NotificationsScreen extends Screen {

	private const DIR = '\\Postelio\\Notifications\\Api\\NotificationDirectory';

	/** @var array<string,string> template => libellé lisible (jamais le contenu de l'e-mail). */
	private const TEMPLATES = array(
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
		return Menu::CAP_ADMIN;
	}

	protected function body(): string {
		if ( ! Data::module_active( 'notifications' ) || ! Data::has( self::DIR ) ) {
			return Ui::page_header( 'Service e-mail', 'Suivi des envois.' )
				. Ui::empty_state( 'Module indisponible', 'Le module Notifications n\'est pas actif.' );
		}
		$stats = (array) call_user_func( array( self::DIR, 'delivery_stats' ) );

		if ( 'failures' === $this->current( 'view' ) ) {
			return Ui::page_header( 'E-mails en échec', 'Envois abandonnés après toutes les tentatives.', Ui::button( '← Service e-mail', $this->url( 'postelio-notifications' ), 'ghost', true ), 'Postelio · Service e-mail' )
				. $this->failures( $stats );
		}

		$out  = Ui::page_header( 'Service e-mail', 'Envois de notifications (aucun contenu, destinataires masqués).' );
		$out .= '<div class="bo-stats bo-stats--5">';
		$out .= Ui::stat( 'En attente', (int) ( $stats['pending'] ?? 0 ) );
		$out .= Ui::stat( 'En cours', (int) ( $stats['processing'] ?? 0 ) );
		$out .= Ui::stat( 'Envoyés', (int) ( $stats['sent'] ?? 0 ) );
		$out .= Ui::stat( 'En échec', (int) ( $stats['failed'] ?? 0 ), '', (int) ( $stats['failed'] ?? 0 ) > 0 );
		$out .= Ui::stat( 'Non envoyés', (int) ( $stats['skipped'] ?? 0 ), 'devenus inutiles' );
		$out .= '</div>';

		$out .= '<div class="bo-grid bo-grid--2">';
		$out .= $this->service_card( $stats );
		$out .= $this->queue_card( $stats );
		$out .= '</div>';
		return $out;
	}

	/** @param array<string,mixed> $stats */
	private function service_card( array $stats ): string {
		$transport = Data::facade( self::DIR, 'transport', array(), null );
		$transport = is_array( $transport ) ? $transport : array( 'label' => 'wp_mail', 'detail' => '', 'smtp_configured' => false );
		$test      = Data::facade( self::DIR, 'last_test', array(), null );
		$failed    = (int) ( $stats['failed'] ?? 0 );

		// L'état privilégie la preuve la plus concrète : le dernier test réel.
		if ( is_array( $test ) && ! empty( $test['ok'] ) ) {
			$state = array( 'Opérationnel', 'success' );
		} elseif ( is_array( $test ) ) {
			$state = array( 'Configuration requise', 'warning' );
		} elseif ( $failed > 0 ) {
			$state = array( 'Incident', 'error' );
		} elseif ( empty( $transport['smtp_configured'] ) ) {
			$state = array( 'Non vérifié', 'neutral' );
		} else {
			$state = array( 'Opérationnel', 'success' );
		}

		$pairs = array(
			'Transport'  => Ui::text( (string) $transport['label'], true ),
			'Traitement' => Ui::badge( 'Automatique', 'info' ),
			'État'       => Ui::badge( $state[0], $state[1], true ),
		);
		if ( is_array( $test ) ) {
			$pairs['Dernier test'] = Ui::text( Fmt::datetime( $test['at'] ?? '' ) . ' → ' . Fmt::or_dash( $test['recipient_masked'] ?? '' ) )
				. ' ' . ( ! empty( $test['ok'] ) ? Ui::badge( 'Remis au transport', 'success' ) : Ui::badge( 'Échec', 'error' ) );
		}

		$out = Ui::card_open( 'Service e-mail' ) . Ui::kv( $pairs );
		if ( '' !== (string) ( $transport['detail'] ?? '' ) ) {
			$out .= Ui::help( (string) $transport['detail'] );
		}
		if ( is_array( $test ) && empty( $test['ok'] ) && '' !== (string) ( $test['error'] ?? '' ) ) {
			$out .= Ui::alert( $this->humanize( (string) $test['error'] ), 'error' );
		}
		$out .= $failed > 0
			? Ui::alert( $failed . ( $failed > 1 ? ' e-mails n\'ont pas pu être envoyés.' : ' e-mail n\'a pas pu être envoyé.' ), 'warning' )
			: Ui::alert( 'Aucun envoi en échec.', 'success' );

		$actions = '';
		if ( $failed > 0 ) {
			$actions .= Ui::button( 'Voir les échecs', $this->url( 'postelio-notifications', array( 'view' => 'failures' ) ), '', true );
		}
		if ( Data::has( self::DIR ) && method_exists( self::DIR, 'send_test' ) && current_user_can( 'pst_manage_platform' ) ) {
			$actions .= Ui::action_button( 'pst_admin_email_test', array(), 'Envoyer un e-mail de test' );
		}
		if ( '' !== $actions ) {
			$out .= '<div class="bo-actions bo-actions--wrap">' . $actions . '</div>';
		}
		$out .= Ui::help( 'L\'e-mail de test part à l\'adresse de votre compte, par le même transport que les notifications, sans passer par la file. « Remis au transport » n\'est pas une preuve de réception.' );
		return $out . Ui::card_close();
	}

	/** @param array<string,mixed> $stats */
	private function queue_card( array $stats ): string {
		return Ui::card_open( 'File d\'envoi' ) . Ui::kv( array(
			'Prochaine tentative' => Ui::text( Fmt::datetime( $stats['next_retry_at'] ?? '' ) ),
			'Dernier échec'       => Ui::text( Fmt::datetime( $stats['last_failed_at'] ?? '' ) ),
			'Passage automatique' => Ui::text( $this->next_worker_run() ),
		) ) . Ui::help( 'Un envoi qui échoue est retenté avec un délai croissant (2, 4, 8… minutes, au maximum une heure), puis abandonné.' ) . Ui::card_close();
	}

	/** @param array<string,mixed> $stats */
	private function failures( array $stats ): string {
		$rows_data = Data::facade( self::DIR, 'delivery_failures', array( 50 ), array() );
		$rows      = array();
		foreach ( (array) $rows_data as $r ) {
			$r       = (array) $r;
			$when    = '' !== (string) ( $r['failed_at'] ?? '' ) ? $r['failed_at'] : ( $r['created_at'] ?? '' );
			$rows[] = array(
				Ui::text( Fmt::datetime( $when ), false, true ),
				Ui::text( self::TEMPLATES[ (string) $r['template'] ] ?? (string) $r['template'], true ),
				Ui::text( Fmt::or_dash( $r['recipient_masked'] ?? '' ), false, true ),
				Ui::text( (int) ( $r['attempts'] ?? 0 ) . ' / ' . (int) ( $r['max_attempts'] ?? 0 ) ),
				Ui::text( $this->humanize( (string) ( $r['last_error'] ?? '' ) ), false, true ),
				Ui::badge( 'Abandonné', 'error', true ),
			);
		}
		$out  = Ui::card_open( 'Envois abandonnés', count( $rows ) . ' / ' . (int) ( $stats['failed'] ?? 0 ) );
		$out .= Ui::table( array( 'Date', 'Type', 'Destinataire', 'Tentatives', 'Erreur', 'État' ), $rows, 'Aucun e-mail en échec.' );
		$out .= Ui::card_close();

		$out .= Ui::card_open( 'Relance' )
			. Ui::help( 'Le module Notifications n\'expose aucune opération de relance sûre : le back-office ne remet rien en file et n\'écrit jamais en base. Corrigez d\'abord le transport, vérifiez avec un e-mail de test, puis les prochains envois partiront normalement.' )
			. Ui::card_close();
		return $out;
	}

	/** Motif technique → phrase compréhensible, sans donnée privée. */
	private function humanize( string $error ): string {
		if ( '' === $error ) {
			return '—';
		}
		if ( 'wp_mail_returned_false' === $error ) {
			return 'Aucun transport e-mail disponible sur le serveur (motif détaillé non capturé à l\'époque).';
		}
		if ( 0 === strpos( $error, 'wp_mail_failed: ' ) ) {
			return 'Transport : ' . substr( $error, strlen( 'wp_mail_failed: ' ) );
		}
		$map = array(
			'unknown_template'   => 'Modèle d\'e-mail inconnu.',
			'invalid_message'    => 'Message invalide (destinataire ou objet manquant).',
			'no_email'           => 'Aucune adresse e-mail valide pour le destinataire.',
			'recipient_inactive' => 'Destinataire inactif ou supprimé.',
			'conversation_read'  => 'Conversation déjà lue : envoi devenu inutile.',
		);
		return $map[ $error ] ?? $error;
	}

	/** Prochain passage planifié du worker d'envoi (hook du planificateur du socle). */
	private function next_worker_run(): string {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return 'Cron système attendu (WP-Cron désactivé)';
		}
		$ts = wp_next_scheduled( 'postelio_job_notifications_worker' );
		if ( ! $ts ) {
			return 'Toutes les 15 minutes — non planifié actuellement';
		}
		return 'Toutes les 15 minutes — prochain : ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $ts ), 'd/m/Y H:i' );
	}
}
