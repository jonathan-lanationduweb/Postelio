<?php
/**
 * Logique d'appartenance recruteur ↔ entreprise.
 *
 * Émet `company.member_added` (écouté par postelio-users pour renseigner
 * `RecruiterProfile.company_id`). Les invitations/retraits/changements de rôle
 * NE sont PAS exposés dans ce lot (hors périmètre documentaire) : le schéma les
 * prépare (rôles owner|recruiter, relation n-n).
 *
 * @package Postelio\Companies\Members
 */

namespace Postelio\Companies\Members;

use Postelio\Core\Plugin as Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MembershipService {

	private MembershipRepository $members;

	public function __construct( MembershipRepository $members ) {
		$this->members = $members;
	}

	public function repository(): MembershipRepository {
		return $this->members;
	}

	/**
	 * Ajoute un membre et émet l'événement métier.
	 */
	public function add_member( int $company_id, int $user_id, string $role ): bool {
		$added = $this->members->add( $company_id, $user_id, $role );
		if ( $added ) {
			Core::instance()->events()->emit(
				'company.member_added',
				array(
					'company_id'    => $company_id,
					'user_id'       => $user_id,
					'role'          => $role,
					'resource_type' => 'company',
					'resource_id'   => (string) $company_id,
					'audit'         => array( 'role_in_company' => $role ),
				)
			);
		}
		return $added;
	}

	public function can_manage( int $company_id, int $user_id ): bool {
		return $this->members->is_member( $company_id, $user_id );
	}

	public function company_of_user( int $user_id ): int {
		return $this->members->company_of_user( $user_id );
	}
}
