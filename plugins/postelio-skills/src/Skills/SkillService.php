<?php
/**
 * Logique métier des savoir-faire : création (toujours draft), édition, publication et archivage.
 * Publication et édition significative d'un contenu publié passent par la passerelle de
 * modération (`postelio/moderation/evaluate`) — mêmes règles que Jobs : low→published,
 * medium→published+case, high/critical→reste/redevient draft (fail-closed). AUCUN état pending.
 * L'auteur/l'entreprise sont TOUJOURS dérivés du serveur (anti-spoofing).
 *
 * @package Postelio\Skills\Skills
 */

namespace Postelio\Skills\Skills;

use Postelio\Core\ApiError;
use Postelio\Core\Plugin as Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SkillService {

	private SkillRepository $skills;

	public function __construct( SkillRepository $skills ) {
		$this->skills = $skills;
	}

	public function repository(): SkillRepository {
		return $this->skills;
	}

	/**
	 * Crée un savoir-faire en BROUILLON. @return array<string,mixed>
	 * @param array<string,mixed> $input
	 * @throws ApiError
	 */
	public function create( int $actor_id, array $input ): array {
		$this->assert_active( $actor_id );

		$title = SkillSanitizer::title( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title ) {
			throw ApiError::validation( array( 'title' => 'Titre requis.' ) );
		}
		$content = SkillSanitizer::content( (string) ( $input['content'] ?? '' ) );
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			throw ApiError::validation( array( 'content' => 'Contenu requis.' ) );
		}
		$category = sanitize_title( (string) ( $input['category'] ?? '' ) );
		if ( '' === $category ) {
			throw ApiError::validation( array( 'category' => 'Catégorie requise.' ) );
		}

		$author = $this->resolve_author( $actor_id, ! empty( $input['as_company'] ) );

		$id = $this->skills->create( $author['author_id'], array(
			'author_type'  => $author['author_type'],
			'company_id'   => $author['company_id'],
			'company_uuid' => $author['company_uuid'],
			'title'        => $title,
			'content'      => $content,
			'summary'      => SkillSanitizer::summary( (string) ( $input['summary'] ?? '' ) ),
			'details'      => SkillSanitizer::details( $input['details'] ?? array() ),
			'category'     => $category,
			'tags'         => SkillSanitizer::tags( $input['tags'] ?? array() ),
			'image_id'     => isset( $input['image_id'] ) ? (int) $input['image_id'] : 0,
		) );
		if ( 0 === $id ) {
			throw new ApiError( 'server_error', 'Création impossible.' );
		}
		$this->emit( 'skill.created', $id );
		return $this->skills->get( $id );
	}

	/**
	 * Édite un savoir-faire. Réévalue via la modération si le contenu publié change de façon
	 * significative (blocage → redevient draft, jamais silencieusement laissé public).
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 * @throws ApiError
	 */
	public function update( int $actor_id, string $uuid, array $input ): array {
		$this->assert_active( $actor_id );
		$skill = $this->owned_or_fail( $actor_id, $uuid );
		if ( SkillStateMachine::ARCHIVED === $skill['status'] ) {
			throw new ApiError( 'invalid_transition', 'Contenu archivé : réactivez-le avant édition.' );
		}
		$id     = (int) $skill['id'];
		$fields = array();
		$significant = false;
		if ( isset( $input['title'] ) ) {
			$fields['title'] = SkillSanitizer::title( (string) $input['title'] ); $significant = true;
		}
		if ( isset( $input['summary'] ) ) {
			$fields['summary'] = SkillSanitizer::summary( (string) $input['summary'] ); $significant = true;
		}
		if ( isset( $input['content'] ) ) {
			$fields['content'] = SkillSanitizer::content( (string) $input['content'] ); $significant = true;
		}
		if ( isset( $input['category'] ) ) {
			$fields['category'] = sanitize_title( (string) $input['category'] ); $significant = true;
		}
		if ( isset( $input['tags'] ) ) {
			$fields['tags'] = SkillSanitizer::tags( $input['tags'] ); $significant = true;
		}
		if ( isset( $input['details'] ) ) {
			$fields['details'] = SkillSanitizer::details( $input['details'] ); $significant = true;
		}
		if ( isset( $input['image_id'] ) ) {
			$fields['image_id'] = (int) $input['image_id'];
		}

		$this->skills->update_content( $id, $fields );
		$this->skills->bump_revision( $id );

		// Contenu publié + modification significative → réévaluation modération.
		if ( SkillStateMachine::PUBLISHED === $skill['status'] && $significant ) {
			$decision = $this->evaluate( $id );
			if ( is_array( $decision ) && ! empty( $decision['blocked'] ) ) {
				// Priorité sécurité : la nouvelle version ne reste pas publique.
				$this->skills->set_status( $id, SkillStateMachine::DRAFT );
			}
		}
		$this->emit( 'skill.updated', $id );
		return $this->skills->get( $id );
	}

	/**
	 * Publie un brouillon (gate de pré-publication). @return array<string,mixed>
	 * @throws ApiError
	 */
	public function publish( int $actor_id, string $uuid ): array {
		$this->assert_active( $actor_id );
		$skill = $this->owned_or_fail( $actor_id, $uuid );
		$id    = (int) $skill['id'];
		if ( SkillStateMachine::PUBLISHED === $skill['status'] ) {
			return $skill; // déjà publié : idempotent
		}
		if ( ! SkillStateMachine::can_transition( (string) $skill['status'], SkillStateMachine::PUBLISHED ) ) {
			throw new ApiError( 'invalid_transition', 'Publication impossible depuis « ' . $skill['status'] . ' ».' );
		}
		if ( SkillRepository::AUTHOR_COMPANY === $skill['author_type'] && $this->company_suspended( (int) $skill['company_id'] ) ) {
			throw ApiError::forbidden( 'Entreprise suspendue : publication indisponible.' );
		}

		$decision = $this->evaluate( $id );
		if ( is_array( $decision ) && ! empty( $decision['blocked'] ) ) {
			throw new ApiError( 'moderation_blocked', (string) ( $decision['message'] ?: 'Ce contenu ne respecte pas les règles de la plateforme.' ) );
		}
		$this->skills->set_status( $id, SkillStateMachine::PUBLISHED );
		$this->emit( 'skill.published', $id );
		return $this->skills->get( $id );
	}

	/** Archive (auteur). @return array<string,mixed> */
	public function archive( int $actor_id, string $uuid ): array {
		$this->assert_active( $actor_id );
		$skill = $this->owned_or_fail( $actor_id, $uuid );
		$this->skills->set_status( (int) $skill['id'], SkillStateMachine::ARCHIVED );
		$this->emit( 'skill.archived', (int) $skill['id'] );
		return $this->skills->get( (int) $skill['id'] );
	}

	// --- Helpers --------------------------------------------------------------

	/** Évalue le contenu via la passerelle de modération (null si module absent). */
	private function evaluate( int $id ) {
		$skill = $this->skills->get( $id );
		$text  = trim( (string) $skill['title'] . "\n" . (string) $skill['summary'] . "\n" . wp_strip_all_tags( (string) $skill['content'] ) );
		return apply_filters( 'postelio/moderation/evaluate', null, array(
			'resource_type' => 'skill',
			'text'          => $text,
			'actor_id'      => (int) $skill['author_id'],
			'resource_uuid' => (string) $skill['uuid'],
			'context'       => array( 'author_type' => $skill['author_type'] ),
		) );
	}

	/**
	 * @return array{author_id:int, author_type:string, company_id:int, company_uuid:string}
	 * @throws ApiError
	 */
	private function resolve_author( int $actor_id, bool $as_company ): array {
		if ( ! $as_company ) {
			return array( 'author_id' => $actor_id, 'author_type' => SkillRepository::AUTHOR_CANDIDATE, 'company_id' => 0, 'company_uuid' => '' );
		}
		if ( ! class_exists( '\\Postelio\\Companies\\Api\\CompanyDirectory' ) ) {
			throw new ApiError( 'server_error', 'Domaine entreprises indisponible.' );
		}
		$company_id = \Postelio\Companies\Api\CompanyDirectory::company_of_user( $actor_id );
		if ( $company_id <= 0 || ! \Postelio\Companies\Api\CompanyDirectory::is_member( $company_id, $actor_id ) ) {
			throw new ApiError( 'conflict', 'Aucune entreprise rattachée : publication au nom de l\'entreprise impossible.' );
		}
		if ( $this->company_suspended( $company_id ) ) {
			throw ApiError::forbidden( 'Entreprise suspendue : publication indisponible.' );
		}
		return array(
			'author_id'    => $actor_id,
			'author_type'  => SkillRepository::AUTHOR_COMPANY,
			'company_id'   => $company_id,
			'company_uuid' => (string) \Postelio\Companies\Api\CompanyDirectory::uuid_of( $company_id ),
		);
	}

	/** @return array<string,mixed> @throws ApiError */
	private function owned_or_fail( int $actor_id, string $uuid ): array {
		$skill = $this->skills->get_by_uuid( $uuid );
		if ( null === $skill ) {
			throw ApiError::not_found();
		}
		$is_admin = function_exists( 'current_user_can' ) && current_user_can( 'pst_moderate_content' );
		if ( SkillRepository::AUTHOR_COMPANY === $skill['author_type'] ) {
			$ok = $is_admin || ( class_exists( '\\Postelio\\Companies\\Api\\CompanyDirectory' )
				&& \Postelio\Companies\Api\CompanyDirectory::is_member( (int) $skill['company_id'], $actor_id ) );
		} else {
			$ok = $is_admin || (int) $skill['author_id'] === $actor_id;
		}
		if ( ! $ok ) {
			throw ApiError::not_found(); // non-divulgation
		}
		return $skill;
	}

	private function company_suspended( int $company_id ): bool {
		if ( $company_id <= 0 || ! class_exists( '\\Postelio\\Companies\\Api\\CompanyBilling' ) ) {
			return false;
		}
		$identity = \Postelio\Companies\Api\CompanyBilling::identity( $company_id );
		return is_array( $identity ) && ! empty( $identity['suspended'] );
	}

	private function assert_active( int $actor_id ): void {
		if ( class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) && ! \Postelio\Users\Api\UserDirectory::is_active( $actor_id ) ) {
			throw ApiError::forbidden( 'Action indisponible pour ce compte.' );
		}
	}

	private function emit( string $event, int $id ): void {
		$skill = $this->skills->get( $id );
		if ( null === $skill ) {
			return;
		}
		Core::instance()->events()->emit( $event, array(
			'skill_id'      => $id,
			'skill_uuid'    => (string) $skill['uuid'],
			'author_id'     => (int) $skill['author_id'],
			'company_id'    => (int) $skill['company_id'],
			'resource_type' => 'skill',
			'resource_id'   => (string) $id,
			'audit'         => array( 'skill_uuid' => (string) $skill['uuid'], 'status' => (string) $skill['status'], 'author_type' => (string) $skill['author_type'] ),
		) );
	}
}
