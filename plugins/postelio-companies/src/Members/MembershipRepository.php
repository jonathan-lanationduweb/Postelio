<?php
/**
 * Accès DB aux appartenances recruteur ↔ entreprise (`wp_postelio_company_members`).
 *
 * @package Postelio\Companies\Members
 */

namespace Postelio\Companies\Members;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MembershipRepository {

	public const ROLE_OWNER     = 'owner';
	public const ROLE_RECRUITER = 'recruiter';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'postelio_company_members';
	}

	public function add( int $company_id, int $user_id, string $role ): bool {
		global $wpdb;
		if ( null !== $this->role( $company_id, $user_id ) ) {
			return false; // déjà membre
		}
		$ok = $wpdb->insert(
			self::table(),
			array(
				'company_id'      => $company_id,
				'user_id'         => $user_id,
				'role_in_company' => $role,
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
		return false !== $ok;
	}

	public function role( int $company_id, int $user_id ): ?string {
		global $wpdb;
		$r = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT role_in_company FROM ' . self::table() . ' WHERE company_id = %d AND user_id = %d',
				$company_id,
				$user_id
			)
		);
		return null !== $r ? (string) $r : null;
	}

	public function is_member( int $company_id, int $user_id ): bool {
		return null !== $this->role( $company_id, $user_id );
	}

	public function is_owner( int $company_id, int $user_id ): bool {
		return self::ROLE_OWNER === $this->role( $company_id, $user_id );
	}

	/**
	 * Première entreprise dont l'utilisateur est membre (0 sinon).
	 */
	public function company_of_user( int $user_id ): int {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT company_id FROM ' . self::table() . ' WHERE user_id = %d ORDER BY id ASC LIMIT 1',
				$user_id
			)
		);
		return $id ? (int) $id : 0;
	}

	/**
	 * @return array<int, array{user_id:int, role_in_company:string, created_at:string}>
	 */
	public function members_of( int $company_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, role_in_company, created_at FROM ' . self::table() . ' WHERE company_id = %d ORDER BY id ASC',
				$company_id
			),
			ARRAY_A
		);
		return array_map(
			static function ( array $r ): array {
				$r['user_id'] = (int) $r['user_id'];
				return $r;
			},
			$rows ?: array()
		);
	}

	public function remove( int $company_id, int $user_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'company_id' => $company_id, 'user_id' => $user_id ), array( '%d', '%d' ) );
	}

	public function remove_all_for_company( int $company_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'company_id' => $company_id ), array( '%d' ) );
	}
}
