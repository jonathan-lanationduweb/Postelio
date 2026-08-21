<?php
/**
 * Présentation d'un utilisateur pour l'API + enrichissement transversal de `/me`.
 *
 * Le core expose `/me` (identité + rôles + capabilities). Ce présentateur s'y
 * greffe via le filtre `postelio/me` pour ajouter e-mail, statut, vérification
 * e-mail et un résumé de profil — SANS que le core connaisse le domaine users.
 *
 * @package Postelio\Users\Users
 */

namespace Postelio\Users\Users;

use Postelio\Users\Profiles\CandidateProfileRepository;
use Postelio\Users\Profiles\RecruiterProfileRepository;
use Postelio\Users\Settings\SettingsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UserPresenter {

	private CandidateProfileRepository $candidates;
	private RecruiterProfileRepository $recruiters;
	private SettingsService $settings;

	public function __construct(
		CandidateProfileRepository $candidates,
		RecruiterProfileRepository $recruiters,
		SettingsService $settings
	) {
		$this->candidates = $candidates;
		$this->recruiters = $recruiters;
		$this->settings   = $settings;
	}

	/**
	 * Objet utilisateur "public" retourné par /auth et /me.
	 *
	 * @return array<string, mixed>
	 */
	public function present( \WP_User $user ): array {
		$id   = (int) $user->ID;
		$role = AccountService::role_slug( $user );

		return array(
			'id'             => $id,
			'email'          => $user->user_email,
			'display_name'   => $user->display_name,
			'role'           => $role,
			'status'         => AccountService::status( $id ),
			'email_verified' => '' !== (string) get_user_meta( $id, AccountService::META_EMAIL_VERIFIED, true ),
			'has_profile'    => $this->has_profile( $id, $role ),
		);
	}

	/**
	 * Callback du filtre `postelio/me`.
	 *
	 * @param array<string, mixed> $identity Identité de base fournie par le core.
	 * @return array<string, mixed>
	 */
	public function enrich_me( array $identity, \WP_User $user ): array {
		$id   = (int) $user->ID;
		$role = AccountService::role_slug( $user );

		$identity['email']          = $user->user_email;
		$identity['role']           = $role;
		$identity['status']         = AccountService::status( $id );
		$identity['email_verified'] = '' !== (string) get_user_meta( $id, AccountService::META_EMAIL_VERIFIED, true );
		$identity['settings']       = $this->settings->get( $id );
		$identity['profile']        = 'recruiter' === $role
			? $this->recruiters->get_by_user( $id )
			: $this->candidates->get_by_user( $id );

		return $identity;
	}

	private function has_profile( int $id, string $role ): bool {
		return 'recruiter' === $role
			? null !== $this->recruiters->get_by_user( $id )
			: null !== $this->candidates->get_by_user( $id );
	}
}
