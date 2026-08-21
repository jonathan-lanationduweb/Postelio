<?php
/**
 * Mise en file et envoi des e-mails. La logique métier n'appelle jamais wp_mail() :
 * elle passe par `enqueue()` (idempotent), puis un worker Scheduler appelle `process()`.
 *
 * Prechecks au moment de l'envoi (jamais bloquant dans la requête HTTP) :
 *  - destinataire toujours actif (sinon `skipped`) ;
 *  - e-mail résolu à l'adresse COURANTE de l'utilisateur (§56) ;
 *  - garde spécifique message : conversation encore non lue (sinon `skipped`).
 *
 * @package Postelio\Notifications\Notifications
 */

namespace Postelio\Notifications\Notifications;

use Postelio\Core\Jobs\Scheduler;
use Postelio\Messaging\Api\MessagingDirectory;
use Postelio\Notifications\Email\EmailProvider;
use Postelio\Notifications\Email\TemplateRegistry;
use Postelio\Notifications\Email\WpMailProvider;
use Postelio\Users\Api\UserDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EmailDispatcher {

	private DeliveryRepository $repo;
	private ?Scheduler $scheduler = null;

	public function __construct( DeliveryRepository $repo ) {
		$this->repo = $repo;
	}

	public function set_scheduler( Scheduler $scheduler ): void {
		$this->scheduler = $scheduler;
	}

	public function repository(): DeliveryRepository {
		return $this->repo;
	}

	/** Provider actif (filtrable). V1 = WpMailProvider. */
	private function provider(): EmailProvider {
		$provider = apply_filters( 'postelio/notifications/email_provider', null );
		return $provider instanceof EmailProvider ? $provider : new WpMailProvider();
	}

	/**
	 * Met un e-mail en file. Idempotent (dedup_key, channel). Retourne l'id ou 0.
	 *
	 * @param array<string, mixed> $data
	 */
	public function enqueue( array $data ): int {
		$data['channel'] = 'email';
		$id              = $this->repo->enqueue( $data );
		// Précision de l'envoi : un one-shot Scheduler à l'échéance exacte (en plus du
		// worker récurrent de secours). WP-Cron se déclenche au trafic ; avec un vrai cron
		// système (recommandé prod), la précision descend à ~1 min. Le worker récurrent
		// `postelio_15min` reste le filet de sécurité (ticks manqués, retries/backoff).
		if ( $id > 0 && null !== $this->scheduler ) {
			$ts = strtotime( (string) ( $data['scheduled_at'] ?? '' ) . ' UTC' );
			$ts = $ts ? $ts : time();
			$this->scheduler->schedule( 'notifications_flush', max( time(), $ts ), array( (int) $id ) );
		}
		return $id;
	}

	/**
	 * Traite un lot borné de livraisons dues. Un `sent` n'est jamais retraité (claim
	 * atomique + statut). Retourne un petit résumé pour l'observabilité/tests.
	 *
	 * @return array{sent:int, skipped:int, failed:int}
	 */
	public function process( int $limit = 20 ): array {
		$sent = 0; $skipped = 0; $failed = 0;
		foreach ( $this->repo->claim_due( $limit ) as $row ) {
			$payload = is_array( $row['payload'] ) ? $row['payload'] : array();
			$user_id = (int) $row['user_id'];

			// §59 : destinataire inactif/supprimé → skip.
			if ( ! UserDirectory::exists( $user_id ) || ! UserDirectory::is_active( $user_id ) ) {
				$this->repo->mark_skipped( (int) $row['id'], 'recipient_inactive' );
				++$skipped;
				continue;
			}

			// Garde message : n'envoyer que si la conversation est TOUJOURS non lue (D4).
			if ( ! empty( $payload['skip_if_conversation_read'] ) && ! empty( $payload['conversation_uuid'] ) ) {
				if ( ! MessagingDirectory::has_unread_in_conversation( $user_id, (string) $payload['conversation_uuid'] ) ) {
					$this->repo->mark_skipped( (int) $row['id'], 'conversation_read' );
					++$skipped;
					continue;
				}
			}

			$email = $this->resolve_email( $user_id );
			if ( '' === $email ) {
				$this->repo->mark_skipped( (int) $row['id'], 'no_email' );
				++$skipped;
				continue;
			}

			$message = TemplateRegistry::render(
				(string) $row['template'],
				$email,
				(string) ( $payload['recipient_name'] ?? '' ),
				(string) ( $payload['cta_url'] ?? '' ),
				isset( $payload['vars'] ) && is_array( $payload['vars'] ) ? $payload['vars'] : array()
			);
			if ( null === $message ) {
				$this->repo->mark_attempt_failed( (int) $row['id'], (int) $row['attempts'], (int) $row['max_attempts'], 'unknown_template' );
				++$failed;
				continue;
			}

			$result = $this->provider()->send( $message );
			if ( $result->ok ) {
				$this->repo->mark_sent( (int) $row['id'], $result->provider_message_id );
				++$sent;
			} else {
				$this->repo->mark_attempt_failed( (int) $row['id'], (int) $row['attempts'], (int) $row['max_attempts'], $result->error );
				++$failed;
			}
		}
		return array( 'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed );
	}

	private function resolve_email( int $user_id ): string {
		$u = get_userdata( $user_id );
		return $u && is_email( $u->user_email ) ? (string) $u->user_email : '';
	}
}
