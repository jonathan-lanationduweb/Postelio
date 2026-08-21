<?php
/**
 * Contrat public STABLE de la messagerie, destiné aux autres plugins
 * (postelio-notifications pour le compteur global / e-mails, header…).
 *
 * Les consommateurs NE lisent JAMAIS les tables messaging directement : ils passent
 * par ce contrat ou par les événements (`message.created`, `conversation.*`).
 *
 * @package Postelio\Messaging\Api
 */

namespace Postelio\Messaging\Api;

use Postelio\Messaging\Conversations\ConversationRepository;
use Postelio\Messaging\Conversations\MessageRepository;
use Postelio\Messaging\Conversations\MessagingService;
use Postelio\Messaging\Conversations\ParticipantRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MessagingDirectory {

	private static function service(): MessagingService {
		return new MessagingService( new ConversationRepository(), new ParticipantRepository(), new MessageRepository() );
	}

	/**
	 * Nombre total de messages non lus pour l'utilisateur (pour un futur compteur
	 * header). NB : distinct des notifications générales — ne pas mélanger.
	 */
	public static function unread_count( int $user_id ): int {
		return self::service()->unread_total( $user_id );
	}

	/**
	 * Contexte d'une conversation, ou null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_conversation_context( string $conversation_uuid ): ?array {
		$c = ( new ConversationRepository() )->get_by_uuid( $conversation_uuid );
		if ( null === $c ) {
			return null;
		}
		return array(
			'conversation_uuid' => (string) $c['public_uuid'],
			'application_uuid'  => $c['application_uuid'],
			'job_uuid'          => $c['job_uuid'],
			'company_id'        => (int) $c['company_id'],
			'company_uuid'      => $c['company_uuid'],
			'candidate_user_id' => (int) $c['candidate_user_id'],
			'status'            => (string) $c['status'],
		);
	}

	public static function can_message( int $user_id, string $conversation_uuid ): bool {
		$c = ( new ConversationRepository() )->get_by_uuid( $conversation_uuid );
		if ( null === $c || 'active' !== $c['status'] ) {
			return false;
		}
		return null !== self::service()->access_role( $user_id, $c );
	}

	public static function close_conversation( int $actor_id, string $conversation_uuid ): void {
		self::service()->close( $actor_id, $conversation_uuid );
	}
}
