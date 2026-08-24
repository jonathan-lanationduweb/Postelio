<?php
/**
 * Exécute les actions de modération en DÉLÉGUANT au domaine propriétaire via ses contrats
 * publics (jamais d'UPDATE direct des tables d'un autre plugin). Les actions sensibles
 * (suspend user/company/job) exigent une capability admin.
 *
 * @package Postelio\Moderation\Actions
 */

namespace Postelio\Moderation\Actions;

use Postelio\Core\ApiError;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ModerationActions {

	/** Actions autorisées à un modérateur (non admin). */
	public const MODERATOR_ACTIONS = array( 'no_action', 'hide', 'unhide', 'close_conversation', 'warning', 'dismiss', 'escalate' );
	/** Actions réservées à l'admin. */
	public const ADMIN_ACTIONS = array( 'suspend_job', 'unsuspend_job', 'suspend_company', 'unsuspend_company', 'suspend_user', 'unsuspend_user' );

	public static function is_valid( string $action ): bool {
		return in_array( $action, array_merge( self::MODERATOR_ACTIONS, self::ADMIN_ACTIONS ), true );
	}

	/**
	 * @param array<int,string> $reason_codes
	 * @throws ApiError
	 */
	public function execute( string $action, string $resource_type, string $resource_uuid, int $actor_id, array $reason_codes = array() ): void {
		if ( ! self::is_valid( $action ) ) {
			throw ApiError::validation( array( 'action' => 'Action inconnue.' ) );
		}
		// Garde capability pour les actions admin.
		if ( in_array( $action, self::ADMIN_ACTIONS, true ) && ! ( function_exists( 'current_user_can' ) && current_user_can( 'pst_manage_platform' ) ) ) {
			// Fallback : caps spécifiques.
			$cap_map = array(
				'suspend_user' => 'pst_suspend_account', 'unsuspend_user' => 'pst_suspend_account',
				'suspend_company' => 'pst_suspend_company', 'unsuspend_company' => 'pst_suspend_company',
				'suspend_job' => 'pst_manage_all_jobs', 'unsuspend_job' => 'pst_manage_all_jobs',
			);
			if ( ! current_user_can( $cap_map[ $action ] ?? 'pst_manage_platform' ) ) {
				throw ApiError::forbidden( 'Action réservée à l\'administration.' );
			}
		}

		switch ( $action ) {
			case 'no_action':
			case 'dismiss':
			case 'escalate':
			case 'warning':
				// warning : décision historisée (event) + moderation.user_warned émis ici.
				if ( 'warning' === $action ) {
					\Postelio\Core\Plugin::instance()->events()->emit( 'moderation.user_warned', array( 'resource_type' => $resource_type, 'resource_uuid' => $resource_uuid ) );
				}
				return;

			case 'close_conversation':
				if ( class_exists( '\\Postelio\\Messaging\\Api\\MessagingDirectory' ) ) {
					\Postelio\Messaging\Api\MessagingDirectory::close_conversation( $actor_id, $resource_uuid );
				}
				$this->emit_hidden( $resource_type, $resource_uuid );
				return;

			case 'hide':
				$this->set_external_visibility( $resource_type, $resource_uuid, 'hidden' );
				$this->emit_hidden( $resource_type, $resource_uuid );
				return;
			case 'unhide':
				$this->set_external_visibility( $resource_type, $resource_uuid, 'visible' );
				\Postelio\Core\Plugin::instance()->events()->emit( 'moderation.content_restored', array( 'resource_type' => $resource_type, 'resource_uuid' => $resource_uuid ) );
				return;

			case 'suspend_job':
				$this->call_jobs( 'suspend', $actor_id, $resource_uuid );
				return; // Notifications via job.suspended — pas de doublon
			case 'unsuspend_job':
				$this->call_jobs( 'unsuspend', $actor_id, $resource_uuid );
				return;

			case 'suspend_company':
				$this->call_company( 'suspend', $actor_id, $resource_uuid, $reason_codes );
				return; // Notifications via company.suspended
			case 'unsuspend_company':
				$this->call_company( 'unsuspend', $actor_id, $resource_uuid, $reason_codes );
				return;

			case 'suspend_user':
				$this->call_user( 'suspend', $actor_id, $resource_uuid );
				return;
			case 'unsuspend_user':
				$this->call_user( 'unsuspend', $actor_id, $resource_uuid );
				return;
		}
	}

	private function emit_hidden( string $resource_type, string $resource_uuid ): void {
		\Postelio\Core\Plugin::instance()->events()->emit( 'moderation.content_hidden', array( 'resource_type' => $resource_type, 'resource_uuid' => $resource_uuid ) );
	}

	private function set_external_visibility( string $resource_type, string $resource_uuid, string $visibility ): void {
		if ( ( 'external_job' === $resource_type || 'job' === $resource_type ) && class_exists( '\\Postelio\\JobSources\\Api\\JobSourcesModeration' ) ) {
			\Postelio\JobSources\Api\JobSourcesModeration::set_visibility( $resource_uuid, $visibility );
		}
		// Savoir-faire (Lot 13) : masquage/démasquage délégué au contrat Skills.
		if ( 'skill' === $resource_type && class_exists( '\\Postelio\\Skills\\Api\\SkillModeration' ) ) {
			\Postelio\Skills\Api\SkillModeration::set_visibility( $resource_uuid, $visibility );
		}
	}

	private function call_jobs( string $op, int $actor_id, string $job_uuid ): void {
		if ( class_exists( '\\Postelio\\Jobs\\Api\\JobModeration' ) ) {
			'suspend' === $op
				? \Postelio\Jobs\Api\JobModeration::suspend( $actor_id, $job_uuid )
				: \Postelio\Jobs\Api\JobModeration::unsuspend( $actor_id, $job_uuid );
		}
	}

	/** @param array<int,string> $reason_codes */
	private function call_company( string $op, int $actor_id, string $company_uuid, array $reason_codes ): void {
		if ( class_exists( '\\Postelio\\Companies\\Api\\CompanyModeration' ) ) {
			'suspend' === $op
				? \Postelio\Companies\Api\CompanyModeration::suspend( $actor_id, $company_uuid, implode( ',', $reason_codes ) )
				: \Postelio\Companies\Api\CompanyModeration::unsuspend( $actor_id, $company_uuid );
		}
	}

	private function call_user( string $op, int $actor_id, string $user_uuid ): void {
		if ( class_exists( '\\Postelio\\Users\\Api\\UserModeration' ) ) {
			'suspend' === $op
				? \Postelio\Users\Api\UserModeration::suspend( $user_uuid, $actor_id )
				: \Postelio\Users\Api\UserModeration::unsuspend( $user_uuid, $actor_id );
		}
	}
}
