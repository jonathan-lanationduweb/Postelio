<?php
/**
 * Aiguilleur des notifications : écoute les événements métier (Applications, Messaging,
 * Interviews, Companies, Jobs) et décide, par la MATRICE V1 + préférences, quoi créer
 * en in-app et/ou mettre en file e-mail. Applique : acteur ≠ destinataire, catégories
 * obligatoires, idempotence (dedup_key), anti-spam messages, rappels d'entretien.
 *
 * Ne lit jamais les tables d'un autre plugin : passe par les contrats publics
 * (CompanyDirectory, JobDirectory, MessagingDirectory, InterviewDirectory, UserDirectory).
 * N'écoute JAMAIS ses propres événements (pas de boucle).
 *
 * @package Postelio\Notifications\Notifications
 */

namespace Postelio\Notifications\Notifications;

use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Core\Events;
use Postelio\Core\Jobs\Scheduler;
use Postelio\Interviews\Api\InterviewDirectory;
use Postelio\Jobs\Api\JobDirectory;
use Postelio\Users\Api\UserDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NotificationRouter {

	private NotificationService $notifications;
	private EmailDispatcher $emails;
	private PreferenceService $prefs;
	private ?Scheduler $scheduler = null;

	public function __construct( NotificationService $notifications, EmailDispatcher $emails, PreferenceService $prefs ) {
		$this->notifications = $notifications;
		$this->emails        = $emails;
		$this->prefs         = $prefs;
	}

	// --- Config (filtrable) ---------------------------------------------------

	private function message_email_delay(): int {
		return (int) apply_filters( 'postelio/notifications/message_email_delay', 5 * MINUTE_IN_SECONDS );
	}
	private function message_email_window(): int {
		return (int) apply_filters( 'postelio/notifications/message_email_window', 30 * MINUTE_IN_SECONDS );
	}
	/** @return array<string,int> */
	private function reminder_offsets(): array {
		return array(
			'24h' => (int) apply_filters( 'postelio/notifications/reminder_offset_24h', 24 * HOUR_IN_SECONDS ),
			'1h'  => (int) apply_filters( 'postelio/notifications/reminder_offset_1h', HOUR_IN_SECONDS ),
		);
	}

	// --- Câblage --------------------------------------------------------------

	public function register( Events $events, Scheduler $scheduler ): void {
		$this->scheduler = $scheduler;

		// Applications
		$events->on( 'application.created', array( $this, 'on_application_created' ) );
		$events->on( 'application.selected', array( $this, 'on_application_selected' ) );
		$events->on( 'application.rejected', array( $this, 'on_application_rejected' ) );
		$events->on( 'application.withdrawn', array( $this, 'on_application_withdrawn' ) );
		// (D2) application.status_changed / reviewed / shortlisted / interview : IGNORÉS.

		// Messaging
		$events->on( 'message.created', array( $this, 'on_message_created' ) );
		$events->on( 'conversation.read', array( $this, 'on_conversation_read' ) );

		// Interviews
		$events->on( 'interview.proposed', array( $this, 'on_interview_proposed' ) );
		$events->on( 'interview.confirmed', array( $this, 'on_interview_confirmed' ) );
		$events->on( 'interview.declined', array( $this, 'on_interview_declined' ) );
		$events->on( 'interview.reschedule_requested', array( $this, 'on_interview_reschedule_requested' ) );
		$events->on( 'interview.rescheduled', array( $this, 'on_interview_rescheduled' ) );
		$events->on( 'interview.cancelled', array( $this, 'on_interview_cancelled' ) );

		// Companies
		$events->on( 'company.verified', array( $this, 'on_company_verified' ) );
		$events->on( 'company.rejected', array( $this, 'on_company_rejected' ) );
		$events->on( 'company.suspended', array( $this, 'on_company_suspended' ) );

		// Jobs
		$events->on( 'job.expiring', array( $this, 'on_job_expiring' ) );
		$events->on( 'job.expired', array( $this, 'on_job_expired' ) );
		$events->on( 'job.suspended', array( $this, 'on_job_suspended' ) );

		// Worker file e-mail + rappels d'entretien (Scheduler unique du core).
		$this->emails->set_scheduler( $scheduler ); // pour les one-shots à l'échéance exacte
		$scheduler->on( 'notifications_worker', array( $this, 'run_worker' ) );
		$scheduler->on( 'notifications_flush', array( $this, 'run_worker' ), 10, 1 ); // one-shot ponctuel (ignore l'arg id)
		$scheduler->on( 'iv_reminder_24h', array( $this, 'fire_reminder_24h' ), 10, 1 );
		$scheduler->on( 'iv_reminder_1h', array( $this, 'fire_reminder_1h' ), 10, 1 );
		$scheduler->recurring( 'notifications_worker', 'postelio_15min' ); // filet de sécurité
	}

	public function run_worker(): void {
		$this->emails->process( (int) apply_filters( 'postelio/notifications/worker_batch', 20 ) );
	}

	// --- Applications ---------------------------------------------------------

	/** @param array<string,mixed> $p */
	public function on_application_created( $p ): void {
		$job_id       = (int) ( $p['job_id'] ?? 0 );
		$company_id   = (int) ( $p['company_id'] ?? 0 );
		$app_uuid     = (string) ( $p['application_uuid'] ?? '' );
		$candidate_id = (int) ( $p['candidate_id'] ?? 0 );
		$job_title    = $this->job_title( $job_id );
		$company      = $this->company_name( $company_id );

		// Recruteur(s) : notification + e-mail (préférence new_applications).
		foreach ( $this->recruiter_recipients( $job_id, $company_id, 0 ) as $rec ) {
			$this->notify( $rec, array(
				'category' => 'new_applications', 'type' => 'new_application', 'event_name' => 'application.created', 'priority' => 'normal',
				'title' => 'Nouvelle candidature — ' . $job_title, 'body' => 'Vous avez reçu une nouvelle candidature.',
				'resource_type' => 'application', 'resource_uuid' => $app_uuid, 'action_type' => 'open_application',
				'group_key' => 'application:' . $app_uuid,
				'email_template' => 'new_application', 'email_vars' => array( 'job_title' => $job_title ),
			) );
		}
		// Candidat : e-mail de confirmation UNIQUEMENT (pas d'in-app — D2).
		$this->notify( $candidate_id, array(
			'category' => 'application_status', 'type' => 'application_received', 'event_name' => 'application.created', 'priority' => 'normal',
			'in_app' => false,
			'resource_type' => 'application', 'resource_uuid' => $app_uuid, 'action_type' => 'open_application',
			'group_key' => 'application:' . $app_uuid,
			'email_template' => 'application_received', 'email_vars' => array( 'job_title' => $job_title, 'company_name' => $company ),
		) );
	}

	/** @param array<string,mixed> $p */
	public function on_application_selected( $p ): void {
		$this->candidate_application_notice( $p, 'application_selected', 'application_selected', 'Votre candidature a été retenue', 'Félicitations : votre candidature a été retenue.' );
	}

	/** @param array<string,mixed> $p */
	public function on_application_rejected( $p ): void {
		// Jamais de motif interne / note / reviewer (D13).
		$this->candidate_application_notice( $p, 'application_rejected', 'application_rejected', 'Réponse à votre candidature', 'Votre candidature n\'a pas été retenue cette fois-ci.' );
	}

	/** @param array<string,mixed> $p */
	public function on_application_withdrawn( $p ): void {
		$job_id     = (int) ( $p['job_id'] ?? 0 );
		$company_id = (int) ( $p['company_id'] ?? 0 );
		$app_uuid   = (string) ( $p['application_uuid'] ?? '' );
		$job_title  = $this->job_title( $job_id );
		foreach ( $this->recruiter_recipients( $job_id, $company_id, 0 ) as $rec ) {
			$this->notify( $rec, array(
				'category' => 'new_applications', 'type' => 'application_withdrawn', 'event_name' => 'application.withdrawn', 'priority' => 'normal',
				'title' => 'Candidature retirée — ' . $job_title, 'body' => 'Un candidat a retiré sa candidature.',
				'resource_type' => 'application', 'resource_uuid' => $app_uuid, 'action_type' => 'open_application',
				'group_key' => 'application:' . $app_uuid, 'email' => false, // in-app seul par défaut
			) );
		}
	}

	/** @param array<string,mixed> $p */
	private function candidate_application_notice( array $p, string $type, string $template, string $title, string $body ): void {
		$app_uuid     = (string) ( $p['application_uuid'] ?? '' );
		$candidate_id = (int) ( $p['candidate_id'] ?? 0 );
		$job_id       = (int) ( $p['job_id'] ?? 0 );
		$company_id   = (int) ( $p['company_id'] ?? 0 );
		$this->notify( $candidate_id, array(
			'category' => 'application_status', 'type' => $type, 'event_name' => 'application.' . str_replace( 'application_', '', $type ), 'priority' => 'important',
			'title' => $title, 'body' => $body,
			'resource_type' => 'application', 'resource_uuid' => $app_uuid, 'action_type' => 'open_application',
			'group_key' => 'application:' . $app_uuid,
			'email_template' => $template, 'email_vars' => array( 'job_title' => $this->job_title( $job_id ), 'company_name' => $this->company_name( $company_id ) ),
		) );
	}

	// --- Messaging ------------------------------------------------------------

	/** @param array<string,mixed> $p */
	public function on_message_created( $p ): void {
		$conv_uuid   = (string) ( $p['conversation_uuid'] ?? '' );
		$sender      = (int) ( $p['sender_user_id'] ?? 0 );
		$recipient_r = (string) ( $p['recipient_role'] ?? '' );
		$company_id  = (int) ( $p['company_id'] ?? 0 );
		$job_uuid    = (string) ( $p['job_uuid'] ?? '' );
		$job_title   = '' !== $job_uuid ? $this->job_title( JobDirectory::id_from_uuid( $job_uuid ) ) : 'votre échange';

		// Destinataires : candidat direct, ou côté entreprise = créateur d'offre + owner (D3).
		$recipients = array();
		if ( 'candidate' === $recipient_r ) {
			$rid = (int) ( $p['recipient_user_id'] ?? 0 );
			if ( $rid > 0 ) {
				$recipients[] = $rid;
			}
		} else {
			$job_id     = '' !== $job_uuid ? JobDirectory::id_from_uuid( $job_uuid ) : 0;
			$recipients = $this->recruiter_recipients( $job_id, $company_id, $sender );
		}

		$delay  = $this->message_email_delay();
		$bucket = (int) floor( time() / max( 1, $this->message_email_window() ) );

		foreach ( array_unique( $recipients ) as $uid ) {
			if ( $uid === $sender ) {
				continue; // acteur ≠ destinataire
			}
			$this->notify( $uid, array(
				'category' => 'messages', 'type' => 'new_message', 'event_name' => 'message.created', 'priority' => 'normal',
				'title' => 'Nouveau message', 'body' => 'Vous avez reçu un nouveau message.',
				'resource_type' => 'conversation', 'resource_uuid' => $conv_uuid, 'action_type' => 'open_conversation',
				'group_key' => 'conversation:' . $conv_uuid,
				'dedup_variant' => (string) ( $p['message_uuid'] ?? '' ), // un in-app par message
				// E-mail différé, conditionnel à la non-lecture, 1/conversation/fenêtre (bucket).
				'email_template' => 'new_message', 'email_vars' => array( 'job_title' => $job_title ),
				'email_scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'email_dedup' => 'e:new_message:' . $conv_uuid . ':' . $uid . ':' . $bucket,
				'email_payload' => array( 'skip_if_conversation_read' => true, 'conversation_uuid' => $conv_uuid ),
			) );
		}
	}

	/** @param array<string,mixed> $p */
	public function on_conversation_read( $p ): void {
		$uid       = (int) ( $p['user_id'] ?? 0 );
		$conv_uuid = (string) ( $p['conversation_uuid'] ?? '' );
		if ( $uid <= 0 || '' === $conv_uuid ) {
			return;
		}
		// Résout les notifs in-app du fil + annule les e-mails encore en attente (D4).
		$this->notifications->resolve_group( $uid, 'conversation:' . $conv_uuid );
		$this->emails->repository()->skip_pending_prefix( 'e:new_message:' . $conv_uuid . ':' . $uid, 'email', 'conversation_read' );
	}

	// --- Interviews -----------------------------------------------------------

	/** @param array<string,mixed> $p */
	public function on_interview_proposed( $p ): void {
		$iv_uuid = (string) ( $p['interview_uuid'] ?? '' );
		$this->notify( (int) $p['candidate_user_id'], array(
			'category' => 'interviews', 'type' => 'interview_proposed', 'event_name' => 'interview.proposed', 'priority' => 'important',
			'title' => 'Proposition d\'entretien', 'body' => 'Une entreprise vous propose un entretien : une action est nécessaire.',
			'resource_type' => 'interview', 'resource_uuid' => $iv_uuid, 'action_type' => 'open_interview',
			'group_key' => 'interview:' . $iv_uuid,
			'email_template' => 'interview_proposed', 'email_vars' => $this->interview_vars( $iv_uuid ),
		) );
	}

	/** @param array<string,mixed> $p */
	public function on_interview_confirmed( $p ): void {
		$iv_uuid   = (string) ( $p['interview_uuid'] ?? '' );
		$candidate = (int) $p['candidate_user_id'];
		$vars      = $this->interview_vars( $iv_uuid );

		// Recruteur(s) : notification + e-mail (préférence interviews).
		foreach ( $this->recruiter_recipients_for_interview( $p ) as $rec ) {
			$this->notify( $rec, array(
				'category' => 'interviews', 'type' => 'interview_confirmed', 'event_name' => 'interview.confirmed', 'priority' => 'normal',
				'title' => 'Entretien confirmé', 'body' => 'Le candidat a confirmé l\'entretien.',
				'resource_type' => 'interview', 'resource_uuid' => $iv_uuid, 'action_type' => 'open_interview',
				'group_key' => 'interview:' . $iv_uuid,
				'email_template' => 'interview_confirmed_proof', 'email_vars' => $vars,
			) );
		}
		// Candidat (acteur) : e-mail de PREUVE obligatoire, pas d'in-app (D12).
		$this->notify( $candidate, array(
			'category' => 'interviews', 'type' => 'interview_confirmed_proof', 'event_name' => 'interview.confirmed', 'priority' => 'important',
			'in_app' => false, 'mandatory' => true,
			'resource_type' => 'interview', 'resource_uuid' => $iv_uuid, 'action_type' => 'open_interview',
			'group_key' => 'interview:' . $iv_uuid,
			'email_template' => 'interview_confirmed_proof', 'email_vars' => $vars,
		) );
		// (Re)planifie les rappels.
		$this->schedule_reminders( $iv_uuid );
	}

	/** @param array<string,mixed> $p */
	public function on_interview_declined( $p ): void {
		$iv_uuid = (string) ( $p['interview_uuid'] ?? '' );
		foreach ( $this->recruiter_recipients_for_interview( $p ) as $rec ) {
			$this->notify( $rec, array(
				'category' => 'interviews', 'type' => 'interview_declined', 'event_name' => 'interview.declined', 'priority' => 'normal',
				'title' => 'Entretien décliné', 'body' => 'Le candidat a décliné la proposition d\'entretien.',
				'resource_type' => 'interview', 'resource_uuid' => $iv_uuid, 'action_type' => 'open_interview',
				'group_key' => 'interview:' . $iv_uuid,
				'email_template' => 'interview_declined', 'email_vars' => $this->interview_vars( $iv_uuid ),
			) );
		}
		$this->cancel_reminders( $iv_uuid );
	}

	/** @param array<string,mixed> $p */
	public function on_interview_reschedule_requested( $p ): void {
		$iv_uuid = (string) ( $p['interview_uuid'] ?? '' );
		foreach ( $this->recruiter_recipients_for_interview( $p ) as $rec ) {
			$this->notify( $rec, array(
				'category' => 'interviews', 'type' => 'interview_reschedule_requested', 'event_name' => 'interview.reschedule_requested', 'priority' => 'important',
				'title' => 'Nouveau créneau proposé', 'body' => 'Le candidat propose un autre créneau pour l\'entretien.',
				'resource_type' => 'interview', 'resource_uuid' => $iv_uuid, 'action_type' => 'open_interview',
				'group_key' => 'interview:' . $iv_uuid,
				'email_template' => 'interview_rescheduled', 'email_vars' => $this->interview_vars( $iv_uuid ),
			) );
		}
		$this->cancel_reminders( $iv_uuid ); // créneau en discussion → rappels invalides
	}

	/** @param array<string,mixed> $p */
	public function on_interview_rescheduled( $p ): void {
		$iv_uuid = (string) ( $p['interview_uuid'] ?? '' );
		$this->notify( (int) $p['candidate_user_id'], array(
			'category' => 'interviews', 'type' => 'interview_rescheduled', 'event_name' => 'interview.rescheduled', 'priority' => 'important',
			'title' => 'Entretien reprogrammé', 'body' => 'Votre entretien a été reprogrammé.',
			'resource_type' => 'interview', 'resource_uuid' => $iv_uuid, 'action_type' => 'open_interview',
			'group_key' => 'interview:' . $iv_uuid,
			'email_template' => 'interview_rescheduled', 'email_vars' => $this->interview_vars( $iv_uuid ),
		) );
		// Rappels : annulés puis replanifiés seulement si l'entretien est de nouveau confirmé.
		$this->cancel_reminders( $iv_uuid );
		$ctx = InterviewDirectory::get_context( $iv_uuid );
		if ( $ctx && 'confirmed' === $ctx['status'] ) {
			$this->schedule_reminders( $iv_uuid );
		}
	}

	/** @param array<string,mixed> $p */
	public function on_interview_cancelled( $p ): void {
		$iv_uuid   = (string) ( $p['interview_uuid'] ?? '' );
		$actor     = (int) ( $p['actor_user_id'] ?? 0 );
		$candidate = (int) $p['candidate_user_id'];
		$vars      = $this->interview_vars( $iv_uuid );

		// Notifie l'AUTRE partie (jamais l'acteur). Annulation = e-mail OBLIGATOIRE (D8).
		if ( $actor === $candidate ) {
			foreach ( $this->recruiter_recipients_for_interview( $p ) as $rec ) {
				$this->notify( $rec, $this->cancel_spec( $iv_uuid, $vars ) );
			}
		} else {
			$this->notify( $candidate, $this->cancel_spec( $iv_uuid, $vars ) );
		}
		$this->cancel_reminders( $iv_uuid );
	}

	/** @param array<string,string> $vars @return array<string,mixed> */
	private function cancel_spec( string $iv_uuid, array $vars ): array {
		return array(
			'category' => 'interviews', 'type' => 'interview_cancelled', 'event_name' => 'interview.cancelled', 'priority' => 'important',
			'mandatory' => true,
			'title' => 'Entretien annulé', 'body' => 'Un entretien a été annulé.',
			'resource_type' => 'interview', 'resource_uuid' => $iv_uuid, 'action_type' => 'open_interview',
			'group_key' => 'interview:' . $iv_uuid,
			'email_template' => 'interview_cancelled', 'email_vars' => $vars,
		);
	}

	// --- Rappels (Scheduler) --------------------------------------------------

	private function schedule_reminders( string $iv_uuid ): void {
		if ( null === $this->scheduler || '' === $iv_uuid ) {
			return;
		}
		$ctx = InterviewDirectory::get_context( $iv_uuid );
		if ( ! $ctx ) {
			return;
		}
		$start   = strtotime( (string) $ctx['scheduled_at'] . ' UTC' );
		$offsets = $this->reminder_offsets();
		foreach ( array( '24h', '1h' ) as $off ) {
			$ts = $start - $offsets[ $off ];
			if ( $ts > time() ) {
				$this->scheduler->schedule( 'iv_reminder_' . $off, $ts, array( $iv_uuid ) );
			}
		}
	}

	private function cancel_reminders( string $iv_uuid ): void {
		if ( null === $this->scheduler || '' === $iv_uuid ) {
			return;
		}
		$this->scheduler->cancel( 'iv_reminder_24h', array( $iv_uuid ) );
		$this->scheduler->cancel( 'iv_reminder_1h', array( $iv_uuid ) );
	}

	public function fire_reminder_24h( string $iv_uuid ): void {
		$this->fire_reminder( $iv_uuid, '24h', true );
	}
	public function fire_reminder_1h( string $iv_uuid ): void {
		$this->fire_reminder( $iv_uuid, '1h', false );
	}

	private function fire_reminder( string $iv_uuid, string $variant, bool $with_email ): void {
		$ctx = InterviewDirectory::get_context( $iv_uuid );
		if ( ! $ctx || 'confirmed' !== $ctx['status'] ) {
			return; // entretien plus confirmé → pas de rappel
		}
		$vars       = $this->interview_vars( $iv_uuid );
		$recipients = array_merge( array( (int) $ctx['candidate_user_id'] ), $this->recruiter_recipients_for_interview( array( 'job_uuid' => $ctx['job_uuid'], 'company_id' => $ctx['company_id'], 'actor_user_id' => 0 ) ) );
		foreach ( array_unique( $recipients ) as $uid ) {
			$this->notify( $uid, array(
				'category' => 'interviews', 'type' => 'interview_reminder', 'event_name' => 'interview.reminder', 'priority' => 'important',
				'title' => 'Rappel d\'entretien', 'body' => 'Votre entretien approche.',
				'resource_type' => 'interview', 'resource_uuid' => $iv_uuid, 'action_type' => 'open_interview',
				'group_key' => 'interview:' . $iv_uuid, 'dedup_variant' => 'reminder_' . $variant,
				'email' => $with_email,
				'email_template' => 'interview_reminder', 'email_vars' => $vars,
				'email_dedup' => 'e:interview_reminder_' . $variant . ':' . $iv_uuid . ':' . $uid,
			) );
		}
	}

	// --- Companies ------------------------------------------------------------

	/** @param array<string,mixed> $p */
	public function on_company_verified( $p ): void {
		$this->company_owner_notice( $p, 'company_verified', 'company_verified', 'normal', false, 'Entreprise vérifiée', 'Votre entreprise est désormais vérifiée.' );
	}
	/** @param array<string,mixed> $p */
	public function on_company_rejected( $p ): void {
		$this->company_owner_notice( $p, 'company_rejected', 'company_rejected', 'important', false, 'Vérification non aboutie', 'La vérification de votre entreprise n\'a pas abouti.' );
	}
	/** @param array<string,mixed> $p */
	public function on_company_suspended( $p ): void {
		$this->company_owner_notice( $p, 'company_suspended', 'company_suspended', 'critical', true, 'Entreprise suspendue', 'Votre entreprise a été suspendue.' );
	}

	/** @param array<string,mixed> $p */
	private function company_owner_notice( array $p, string $type, string $template, string $priority, bool $mandatory, string $title, string $body ): void {
		$company_id = (int) ( $p['company_id'] ?? 0 );
		$owner      = CompanyDirectory::owner_of( $company_id );
		if ( null === $owner ) {
			return;
		}
		$this->notify( $owner, array(
			'category' => 'company', 'type' => $type, 'event_name' => 'company.' . str_replace( 'company_', '', $type ), 'priority' => $priority,
			'mandatory' => $mandatory,
			'title' => $title, 'body' => $body, // jamais le motif interne (D15)
			'resource_type' => 'company', 'resource_uuid' => CompanyDirectory::uuid_of( $company_id ), 'action_type' => 'company_profile',
			'group_key' => 'company:' . $company_id,
			'email_template' => $template, 'email_vars' => array( 'company_name' => $this->company_name( $company_id ) ),
		) );
	}

	// --- Jobs -----------------------------------------------------------------

	/** @param array<string,mixed> $p */
	public function on_job_expiring( $p ): void {
		$this->job_notice( $p, 'job_expiring', 'job_expiring', 'normal', 'Votre offre expire bientôt', 'Une de vos offres arrive à expiration.' );
	}
	/** @param array<string,mixed> $p */
	public function on_job_expired( $p ): void {
		$this->job_notice( $p, 'job_expired', 'job_expired', 'normal', 'Offre expirée', 'Une de vos offres a expiré.' );
	}
	/** @param array<string,mixed> $p */
	public function on_job_suspended( $p ): void {
		$this->job_notice( $p, 'job_suspended', 'job_suspended', 'important', 'Offre suspendue', 'Une de vos offres a été suspendue.' );
	}

	/** @param array<string,mixed> $p */
	private function job_notice( array $p, string $type, string $template, string $priority, string $title, string $body ): void {
		$job_id     = (int) ( $p['job_id'] ?? 0 );
		$company_id = (int) ( $p['company_id'] ?? 0 );
		$job_uuid   = JobDirectory::uuid_of( $job_id );
		$job_title  = $this->job_title( $job_id );
		foreach ( $this->recruiter_recipients( $job_id, $company_id, 0 ) as $rec ) {
			$this->notify( $rec, array(
				'category' => 'job_expiration', 'type' => $type, 'event_name' => 'job.' . str_replace( 'job_', '', $type ), 'priority' => $priority,
				'title' => $title . ' — ' . $job_title, 'body' => $body,
				'resource_type' => 'job', 'resource_uuid' => $job_uuid, 'action_type' => 'manage_job',
				'group_key' => 'job:' . $job_id,
				'email_template' => $template, 'email_vars' => array( 'job_title' => $job_title ),
			) );
		}
	}

	// --- Cœur : notify() ------------------------------------------------------

	/**
	 * Crée l'in-app et/ou met l'e-mail en file selon la matrice + préférences + mandatory.
	 *
	 * @param array<string,mixed> $spec
	 */
	private function notify( int $user_id, array $spec ): void {
		if ( $user_id <= 0 || ! UserDirectory::exists( $user_id ) || ! UserDirectory::is_active( $user_id ) ) {
			return; // §59
		}
		$category  = (string) $spec['category'];
		$type      = (string) $spec['type'];
		$mandatory = (bool) ( $spec['mandatory'] ?? false );
		$resource  = (string) ( $spec['resource_uuid'] ?? '' );
		$variant   = (string) ( $spec['dedup_variant'] ?? '' );

		// IN-APP
		$want_in_app = (bool) ( $spec['in_app'] ?? true );
		if ( $want_in_app && ( $mandatory || $this->prefs->allows( $user_id, $category, 'in_app' ) ) ) {
			$this->notifications->create( array(
				'user_id' => $user_id, 'type' => $type, 'event_name' => (string) $spec['event_name'],
				'priority' => (string) ( $spec['priority'] ?? 'normal' ),
				'title' => (string) ( $spec['title'] ?? '' ), 'body' => $spec['body'] ?? null,
				'resource_type' => $spec['resource_type'] ?? null, 'resource_uuid' => $resource,
				'action_type' => $spec['action_type'] ?? null,
				'action_payload' => array( 'action_type' => $spec['action_type'] ?? null, 'resource_type' => $spec['resource_type'] ?? null, 'resource_uuid' => $resource ),
				'group_key' => $spec['group_key'] ?? null,
				'dedup_key' => 'n:' . $type . ':' . $resource . ':' . $user_id . ( '' !== $variant ? ':' . $variant : '' ),
			) );
		}

		// EMAIL
		$want_email = (bool) ( $spec['email'] ?? true ) && ! empty( $spec['email_template'] );
		if ( $want_email && ( $mandatory || $this->prefs->allows( $user_id, $category, 'email' ) ) ) {
			$vars                   = isset( $spec['email_vars'] ) && is_array( $spec['email_vars'] ) ? $spec['email_vars'] : array();
			$vars['recipient_name'] = UserDirectory::display_name( $user_id );
			$payload                = array(
				'recipient_name' => $vars['recipient_name'],
				'cta_url'        => $this->action_url( (string) ( $spec['action_type'] ?? '' ), $resource ),
				'vars'           => $vars,
			);
			if ( isset( $spec['email_payload'] ) && is_array( $spec['email_payload'] ) ) {
				$payload = array_merge( $payload, $spec['email_payload'] );
			}
			$this->emails->enqueue( array(
				'user_id' => $user_id, 'template' => (string) $spec['email_template'],
				'priority' => (string) ( $spec['priority'] ?? 'normal' ),
				'payload' => $payload,
				'scheduled_at' => (string) ( $spec['email_scheduled_at'] ?? current_time( 'mysql', true ) ),
				'dedup_key' => (string) ( $spec['email_dedup'] ?? ( 'e:' . $type . ':' . $resource . ':' . $user_id . ( '' !== $variant ? ':' . $variant : '' ) ) ),
			) );
		}
	}

	// --- Helpers --------------------------------------------------------------

	/** @return int[] */
	private function recruiter_recipients( int $job_id, int $company_id, int $exclude ): array {
		$ids = array();
		$creator = $job_id > 0 ? JobDirectory::created_by( $job_id ) : null;
		if ( null !== $creator ) {
			$ids[] = $creator;
		}
		$owner = $company_id > 0 ? CompanyDirectory::owner_of( $company_id ) : null;
		if ( null !== $owner ) {
			$ids[] = $owner;
		}
		$ids = array_values( array_unique( array_filter( $ids, static fn( $v ) => (int) $v > 0 && (int) $v !== $exclude ) ) );
		return array_map( 'intval', $ids );
	}

	/** @param array<string,mixed> $p @return int[] */
	private function recruiter_recipients_for_interview( array $p ): array {
		$job_id = ! empty( $p['job_uuid'] ) ? JobDirectory::id_from_uuid( (string) $p['job_uuid'] ) : 0;
		return $this->recruiter_recipients( $job_id, (int) ( $p['company_id'] ?? 0 ), (int) ( $p['actor_user_id'] ?? 0 ) );
	}

	private function job_title( int $job_id ): string {
		$t = $job_id > 0 ? JobDirectory::title_of( $job_id ) : null;
		return null !== $t && '' !== $t ? $t : 'une offre';
	}
	private function company_name( int $company_id ): string {
		$n = $company_id > 0 ? CompanyDirectory::name_of( $company_id ) : null;
		return null !== $n && '' !== $n ? $n : 'une entreprise';
	}

	/** @return array<string,string> */
	private function interview_vars( string $iv_uuid ): array {
		$ctx = InterviewDirectory::get_context( $iv_uuid );
		if ( ! $ctx ) {
			return array( 'interview_ref' => substr( $iv_uuid, 0, 8 ) );
		}
		$type_label = array( 'video' => 'Visioconférence', 'onsite' => 'Sur place', 'phone' => 'Téléphone' )[ (string) $ctx['type'] ] ?? (string) $ctx['type'];
		$location   = '';
		if ( 'video' === $ctx['type'] && ! empty( $ctx['video_data']['meeting_url'] ) ) {
			$location = 'Lien : ' . $ctx['video_data']['meeting_url'];
		} elseif ( 'onsite' === $ctx['type'] && is_array( $ctx['location_data'] ) ) {
			$l        = $ctx['location_data'];
			$location = 'Adresse : ' . trim( ( $l['address'] ?? '' ) . ' ' . ( $l['postal_code'] ?? '' ) . ' ' . ( $l['city'] ?? '' ) );
		} elseif ( 'phone' === $ctx['type'] && ! empty( $ctx['phone_data']['phone_number'] ) ) {
			$location = 'Téléphone : ' . $ctx['phone_data']['phone_number'];
		}
		return array(
			'job_title'     => '' !== (string) $ctx['job_uuid'] ? $this->job_title( JobDirectory::id_from_uuid( (string) $ctx['job_uuid'] ) ) : 'le poste',
			'company_name'  => (string) ( $ctx['company_name'] ?? 'l\'entreprise' ),
			'date_label'    => $this->format_dt( (string) $ctx['scheduled_at'], (string) $ctx['timezone'] ),
			'timezone'      => (string) $ctx['timezone'],
			'duration'      => (string) ( (int) $ctx['duration_minutes'] ),
			'type_label'    => $type_label,
			'location_block' => $location,
			'interview_ref' => substr( $iv_uuid, 0, 8 ),
		);
	}

	/** DATETIME UTC → libellé lisible dans le fuseau métier. */
	private function format_dt( string $utc, string $tz ): string {
		if ( '' === $utc || '0000-00-00 00:00:00' === $utc ) {
			return '';
		}
		try {
			$dt = new \DateTime( $utc . ' UTC' );
			$dt->setTimezone( new \DateTimeZone( '' !== $tz ? $tz : 'UTC' ) );
			return $dt->format( 'd/m/Y à H\hi' );
		} catch ( \Exception $e ) {
			return $utc . ' UTC';
		}
	}

	/** URL de deep-link pour l'e-mail (le web/Tauri résout `action_type`+`resource_uuid`). */
	private function action_url( string $action_type, string $resource_uuid ): string {
		$base = rtrim( (string) apply_filters( 'postelio/notifications/web_base_url', 'https://postelio.local' ), '/' );
		return $base . '/n/' . rawurlencode( $action_type ) . '/' . rawurlencode( $resource_uuid );
	}
}
