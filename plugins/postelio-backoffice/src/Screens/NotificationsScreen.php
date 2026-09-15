<?php
/**
 * Service e-mail — centre d'envoi : Transport / État / Dernier test en tête, puis file d'attente et
 * échecs. L'état courant est établi par la preuve la plus récente (dernier test réel) : des erreurs
 * historiques n'écrasent jamais l'état actuel. Destinataires MASQUÉS, jamais de contenu ; envoi d'un
 * e-mail de test par le MÊME provider que les notifications. Tout est lu via le contrat public
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
		'email_verification'        => 'Vérification d\'adresse e-mail',
		'password_reset'            => 'Réinitialisation du mot de passe',
	);

	protected function capability(): string {
		return Menu::CAP_ADMIN;
	}

	protected function eyebrow(): string {
		return 'Postelio · Système';
	}

	protected function body(): string {
		if ( ! Data::module_active( 'notifications' ) || ! Data::has( self::DIR ) ) {
			return $this->header( 'Service e-mail', 'Suivi des envois.' )
				. Ui::empty_state( 'Module indisponible', 'Le module Notifications n\'est pas actif.' );
		}
		$stats = (array) call_user_func( array( self::DIR, 'delivery_stats' ) );

		if ( 'failures' === $this->current( 'view' ) ) {
			return $this->header( 'E-mails en échec', 'Envois abandonnés après toutes les tentatives.', Ui::button( 'Retour au service e-mail', $this->url( 'postelio-notifications' ), 'ghost', true ) )
				. $this->failures( $stats );
		}

		$transport = Data::facade( self::DIR, 'transport', array(), null );
		$transport = is_array( $transport ) ? $transport : array( 'label' => 'wp_mail', 'detail' => '', 'smtp_configured' => false );
		$test      = Data::facade( self::DIR, 'last_test', array(), null );
		$failed    = (int) ( $stats['failed'] ?? 0 );
		$state     = $this->state( $transport, is_array( $test ) ? $test : null, $failed );

		$actions = '';
		if ( method_exists( self::DIR, 'send_test' ) && current_user_can( 'pst_manage_platform' ) ) {
			$actions .= Ui::action_button( 'pst_admin_email_test', array(), 'Envoyer un e-mail de test', 'primary' );
		}
		$out = $this->header( 'Service e-mail', 'Centre d\'envoi des notifications. Aucun contenu, destinataires masqués.', $actions );

		// Bande d'état : transport · état · dernier test · file · échecs.
		$out .= Ui::kpis_open( 5 );
		$out .= '<div class="bo-kpi bo-kpi--text"><span class="bo-kpi__label">Transport</span><span class="bo-kpi__value">' . esc_html( (string) $transport['label'] ) . '</span><span class="bo-kpi__sub">' . esc_html( ! empty( $transport['smtp_configured'] ) ? 'SMTP configuré' : 'transport serveur' ) . '</span></div>';
		$out .= '<div class="bo-kpi bo-kpi--text"><span class="bo-kpi__label">État</span><span class="bo-kpi__value">' . Ui::badge( $state[0], $state[1], true ) . '</span>'
			. ( '' !== $state[2] ? '<span class="bo-kpi__sub">' . esc_html( $state[2] ) . '</span>' : '' ) . '</div>';
		$out .= Ui::kpi( 'Dernier test', is_array( $test ) ? Fmt::date( $test['at'] ?? '' ) : null, is_array( $test ) ? ( ! empty( $test['ok'] ) ? 'remis au transport' : 'en échec' ) : 'aucun test effectué' );
		$out .= Ui::kpi( 'File d\'attente', (int) ( $stats['pending'] ?? 0 ), (int) ( $stats['processing'] ?? 0 ) > 0 ? (int) $stats['processing'] . ' en cours' : '', false, '' );
		$out .= Ui::kpi( 'Échecs', $failed, (int) ( $stats['sent'] ?? 0 ) . ' envoyés au total', $failed > 0, $failed > 0 ? $this->url( 'postelio-notifications', array( 'view' => 'failures' ) ) : '' );
		$out .= Ui::kpis_close();

		$out .= Ui::grid_open( 2 );

		// Transport et test.
		$out .= Ui::card_open( 'Transport', 'Canal réellement utilisé par les notifications.' );
		$pairs = array(
			'Transport'  => Ui::text( (string) $transport['label'], true ),
			'Traitement' => Ui::badge( 'Automatique', 'info' ),
			'État'       => Ui::badge( $state[0], $state[1], true ),
		);
		if ( is_array( $test ) ) {
			$pairs['Dernier test'] = Ui::text( Fmt::datetime( $test['at'] ?? '' ) . ( '' !== (string) ( $test['recipient_masked'] ?? '' ) ? ' → ' . (string) $test['recipient_masked'] : '' ) )
				. ( ! empty( $test['ok'] ) ? Ui::badge( 'Remis au transport', 'success' ) : Ui::badge( 'Échec', 'error' ) );
		}
		$out .= Ui::kv( $pairs );
		if ( '' !== (string) ( $transport['detail'] ?? '' ) ) {
			$out .= Ui::help( (string) $transport['detail'] );
		}
		if ( is_array( $test ) && empty( $test['ok'] ) && '' !== (string) ( $test['error'] ?? '' ) ) {
			$out .= Ui::alert( $this->humanize( (string) $test['error'] ), 'error' );
		}
		$out .= Ui::help( 'L\'e-mail de test part à l\'adresse de votre compte, par le même transport que les notifications, sans passer par la file. « Remis au transport » n\'est pas une preuve de réception.' );
		$out .= Ui::card_close();

		// File et échecs.
		$out .= Ui::card_open( 'File d\'envoi', 'Tentatives et échecs.', $failed > 0 ? Ui::button( 'Voir les échecs', $this->url( 'postelio-notifications', array( 'view' => 'failures' ) ), '', true ) : '' );
		$out .= Ui::kv_present( array(
			'En attente'          => (int) ( $stats['pending'] ?? 0 ),
			'En cours'            => (int) ( $stats['processing'] ?? 0 ),
			'Non envoyés'         => (int) ( $stats['skipped'] ?? 0 ) . ' (devenus inutiles)',
			'Échecs définitifs'   => Ui::html( Ui::badge( (string) $failed, $failed > 0 ? 'warning' : 'success' ) ),
			'Prochaine tentative' => '' !== (string) ( $stats['next_retry_at'] ?? '' ) ? Fmt::datetime( $stats['next_retry_at'] ) : '',
			'Dernier échec'       => '' !== (string) ( $stats['last_failed_at'] ?? '' ) ? Fmt::datetime( $stats['last_failed_at'] ) : '',
			'Passage automatique' => $this->next_worker_run(),
		) );
		$out .= Ui::help( 'Un envoi qui échoue est retenté avec un délai croissant (2, 4, 8… minutes, au maximum une heure), puis abandonné. Les échecs passés n\'altèrent pas l\'état courant du transport.' );
		$out .= Ui::card_close();

		return $out . Ui::grid_close();
	}

	/**
	 * État courant : la preuve la plus concrète (dernier test) prime sur l'historique des échecs.
	 *
	 * @param array<string,mixed>      $transport
	 * @param array<string,mixed>|null $test
	 * @return array{0:string,1:string,2:string} libellé, variante, précision
	 */
	private function state( array $transport, ?array $test, int $failed ): array {
		if ( null !== $test && ! empty( $test['ok'] ) ) {
			return array( 'Opérationnel', 'success', 'test réussi le ' . Fmt::date( $test['at'] ?? '' ) );
		}
		if ( null !== $test ) {
			return array( 'Configuration requise', 'warning', 'dernier test en échec' );
		}
		if ( $failed > 0 ) {
			return array( 'À vérifier', 'warning', 'échecs sans test récent' );
		}
		if ( empty( $transport['smtp_configured'] ) ) {
			return array( 'Non vérifié', 'neutral', 'aucun test effectué' );
		}
		return array( 'Opérationnel', 'success', '' );
	}

	/** @param array<string,mixed> $stats */
	private function failures( array $stats ): string {
		$rows_data = Data::facade( self::DIR, 'delivery_failures', array( 50 ), array() );
		$rows      = array();
		foreach ( (array) $rows_data as $r ) {
			$r      = (array) $r;
			$when   = '' !== (string) ( $r['failed_at'] ?? '' ) ? $r['failed_at'] : ( $r['created_at'] ?? '' );
			$rows[] = array(
				Ui::meta( self::TEMPLATES[ (string) $r['template'] ] ?? (string) $r['template'], (string) ( $r['recipient_masked'] ?? '' ) ),
				Ui::text( (int) ( $r['attempts'] ?? 0 ) . ' / ' . (int) ( $r['max_attempts'] ?? 0 ), false, true ),
				Ui::text( $this->humanize( (string) ( $r['last_error'] ?? '' ) ), false, true ),
				Ui::text( Fmt::datetime( $when ), false, true ),
				Ui::badge( 'Abandonné', 'error', true ),
			);
		}
		$out  = Ui::card_open( 'Envois abandonnés', count( $rows ) . ' affichés sur ' . (int) ( $stats['failed'] ?? 0 ), '', 'bo-card--flush' );
		$out .= Ui::table( array( 'E-mail', 'Tentatives', 'Erreur', 'Date', 'État' ), $rows, 'Aucun e-mail en échec.' );
		$out .= Ui::card_close();
		$out .= Ui::help( 'Le module Notifications n\'expose aucune opération de relance sûre : le back-office ne remet rien en file et n\'écrit jamais en base. Corrigez d\'abord le transport, vérifiez avec un e-mail de test ; les prochains envois partiront normalement.' );
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
			return 'Toutes les 15 minutes · non planifié actuellement';
		}
		return 'Toutes les 15 minutes · prochain passage : ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $ts ), 'd/m/Y H:i' );
	}
}
