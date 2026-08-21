<?php
/**
 * Présentation des conversations/messages. Jamais d'ID SQL, de notes recruteur ni de
 * données d'autres candidatures. L'interlocuteur affiché dépend du rôle du lecteur.
 *
 * @package Postelio\Messaging\Conversations
 */

namespace Postelio\Messaging\Conversations;

use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Users\Api\UserDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ConversationPresenter {

	/**
	 * @param array<string,mixed> $c
	 * @return array<string, mixed>
	 */
	public static function row( array $c, string $viewer_role, int $unread ): array {
		return array(
			'uuid'             => $c['public_uuid'],
			'subject'          => $c['subject'],
			'status'           => $c['status'],
			'application_uuid' => $c['application_uuid'],
			'job_uuid'         => $c['job_uuid'],
			'company'          => array(
				'uuid' => $c['company_uuid'],
				'nom'  => CompanyDirectory::name_of( (int) $c['company_id'] ) ?? $c['company_name'],
			),
			'interlocutor'     => self::interlocutor( $c, $viewer_role ),
			'last_message_at'  => $c['last_message_at'],
			'unread_count'     => $unread,
		);
	}

	/**
	 * @param array<string,mixed> $c
	 * @return array<string, mixed>
	 */
	public static function detail( array $c, string $viewer_role, int $unread ): array {
		$row            = self::row( $c, $viewer_role, $unread );
		$row['created_at'] = $c['created_at'];
		return $row;
	}

	/**
	 * @param array<string,mixed> $m
	 * @return array<string, mixed>
	 */
	public static function message( array $m, int $viewer_id ): array {
		$deleted = 'deleted' === ( $m['status'] ?? 'sent' );
		return array(
			'uuid'        => $m['public_uuid'],
			'sender_role' => $m['sender_role'],
			'is_mine'     => (int) $m['sender_user_id'] === $viewer_id,
			'body'        => $deleted ? null : (string) $m['body'],
			'deleted'     => $deleted,
			'created_at'  => $m['created_at'],
		);
	}

	/**
	 * @param array<int, array<string,mixed>> $items
	 * @return array<int, array<string,mixed>>
	 */
	public static function messages( array $items, int $viewer_id ): array {
		return array_map( static fn( $m ) => self::message( $m, $viewer_id ), $items );
	}

	/**
	 * @param array<string,mixed> $c
	 * @return array<string, mixed>
	 */
	private static function interlocutor( array $c, string $viewer_role ): array {
		if ( MessagingService::ROLE_CANDIDATE === $viewer_role ) {
			// Le candidat voit l'ENTREPRISE comme interlocuteur.
			return array( 'type' => 'company', 'nom' => CompanyDirectory::name_of( (int) $c['company_id'] ) ?? $c['company_name'] );
		}
		// Le recruteur voit le CANDIDAT.
		$cid = (int) $c['candidate_user_id'];
		return array(
			'type'         => 'candidate',
			'display_name' => UserDirectory::display_name( $cid ),
			'profile_uuid' => UserDirectory::candidate_profile_uuid( $cid ),
		);
	}
}
