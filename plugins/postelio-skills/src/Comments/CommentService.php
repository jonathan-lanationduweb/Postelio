<?php
/**
 * Commentaires (« Avis » V1). Insertion précédée d'une évaluation ModerationGateway (comme
 * Messaging) : low→publié, medium→publié+case, high/critical→AUCUNE row + `moderation_blocked`.
 * Rate-limit configurable. Aucun état pending, aucun antispam parallèle.
 *
 * @package Postelio\Skills\Comments
 */

namespace Postelio\Skills\Comments;

use Postelio\Core\ApiError;
use Postelio\Core\Plugin as Core;
use Postelio\Skills\Skills\SkillRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CommentService {

	public const BODY_MAX = 2000;

	private CommentRepository $comments;
	private SkillRepository $skills;

	public function __construct( CommentRepository $comments, SkillRepository $skills ) {
		$this->comments = $comments;
		$this->skills   = $skills;
	}

	public function comments(): CommentRepository {
		return $this->comments;
	}

	/**
	 * @return array{items:array<int,array<string,mixed>>, total:int}
	 * @throws ApiError
	 */
	public function list_for_skill( string $skill_uuid, int $page, int $per_page ): array {
		$skill = $this->skills->get_by_uuid( $skill_uuid );
		if ( null === $skill || ! $this->skills->is_public_visible( $skill ) ) {
			throw ApiError::not_found();
		}
		return $this->comments->list_published_for_skill( (int) $skill['id'], $page, $per_page );
	}

	/**
	 * Poste un commentaire (modéré à l'insert). @return array<string,mixed>
	 * @throws ApiError
	 */
	public function create( int $actor_id, string $skill_uuid, string $raw_body ): array {
		if ( class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) && ! \Postelio\Users\Api\UserDirectory::is_active( $actor_id ) ) {
			throw ApiError::forbidden( 'Action indisponible pour ce compte.' );
		}
		$skill = $this->skills->get_by_uuid( $skill_uuid );
		if ( null === $skill || ! $this->skills->is_public_visible( $skill ) ) {
			throw ApiError::not_found(); // non-divulgation : on ne commente qu'un contenu public
		}
		$body = mb_substr( sanitize_textarea_field( $raw_body ), 0, self::BODY_MAX );
		if ( '' === trim( $body ) ) {
			throw ApiError::validation( array( 'body' => 'Commentaire vide.' ) );
		}
		$this->rate_limit_or_fail( $actor_id );

		// Modération pré-insert (bloqué → aucune row).
		$decision = apply_filters( 'postelio/moderation/evaluate', null, array(
			'resource_type' => 'skill_comment',
			'text'          => $body,
			'actor_id'      => $actor_id,
			'resource_uuid' => (string) $skill['uuid'],
			'context'       => array( 'skill_uuid' => (string) $skill['uuid'] ),
		) );
		if ( is_array( $decision ) && ! empty( $decision['blocked'] ) ) {
			throw new ApiError( 'moderation_blocked', (string) ( $decision['message'] ?: 'Ce commentaire ne respecte pas les règles de la plateforme.' ) );
		}

		$id = $this->comments->insert( array(
			'skill_id'       => (int) $skill['id'],
			'skill_uuid'     => (string) $skill['uuid'],
			'author_user_id' => $actor_id,
			'author_role'    => class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) ? \Postelio\Users\Api\UserDirectory::role( $actor_id ) : '',
			'body'           => $body,
			'status'         => CommentRepository::PUBLISHED,
		) );
		if ( 0 === $id ) {
			throw new ApiError( 'server_error', 'Commentaire non enregistré.' );
		}
		$comment = $this->comments->get_by_uuid( $this->uuid_of( $id ) );

		Core::instance()->events()->emit( 'skill.comment_created', array(
			'skill_id'      => (int) $skill['id'],
			'skill_uuid'    => (string) $skill['uuid'],
			'comment_uuid'  => $comment ? (string) $comment['public_uuid'] : '',
			'author_id'     => $actor_id,
			'recipient_id'  => (int) $skill['author_id'], // auteur du savoir-faire (notification)
			'resource_type' => 'skill',
			'resource_id'   => (string) $skill['id'],
			'audit'         => array( 'skill_uuid' => (string) $skill['uuid'] ),
		) );
		return $comment ?: array();
	}

	private function uuid_of( int $id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT public_uuid FROM ' . CommentRepository::table() . ' WHERE id = %d', $id ) );
	}

	private function rate_limit_or_fail( int $user_id ): void {
		$max = (int) apply_filters( 'postelio/skills/comment_rate_per_hour', 30 );
		if ( $max <= 0 ) {
			return;
		}
		if ( $this->comments->count_recent_by_author( $user_id, HOUR_IN_SECONDS ) >= $max ) {
			throw new ApiError( 'rate_limited', 'Trop de commentaires ; réessayez plus tard.' );
		}
	}
}
