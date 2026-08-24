<?php
/**
 * Cascade de masquage (découplée) : la suspension d'un utilisateur ou d'une entreprise masque
 * ses savoir-faire (drapeau `pst_susp_hidden`), la réactivation les restaure — MAIS uniquement
 * ceux masqués PAR LA SUSPENSION. Un contenu masqué par la modération (`pst_mod_hidden`) reste
 * masqué (drapeaux distincts). Jamais de hard-delete.
 *
 * @package Postelio\Skills\Integration
 */

namespace Postelio\Skills\Integration;

use Postelio\Core\Events;
use Postelio\Skills\Skills\SkillRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SuspensionSync {

	private Events $events;
	private SkillRepository $skills;

	public function __construct( Events $events, SkillRepository $skills ) {
		$this->events = $events;
		$this->skills = $skills;
	}

	public function register(): void {
		$this->events->on( 'user.suspended', array( $this, 'on_user_suspended' ) );
		$this->events->on( 'user.unsuspended', array( $this, 'on_user_unsuspended' ) );
		$this->events->on( 'user.deleted', array( $this, 'on_user_suspended' ) ); // suppression compte → masquage (pas de restauration auto)
		$this->events->on( 'company.suspended', array( $this, 'on_company_suspended' ) );
		$this->events->on( 'company.verified', array( $this, 'on_company_restored' ) ); // réactivation = re-vérification
	}

	/** @param array<string,mixed> $p */
	public function on_user_suspended( $p ): void {
		$uid = (int) ( ( is_array( $p ) ? $p['id'] ?? 0 : 0 ) );
		if ( $uid <= 0 ) {
			return;
		}
		foreach ( $this->skills->personal_ids_of_user( $uid ) as $id ) {
			$this->skills->set_susp_hidden( $id, true );
		}
	}

	/** @param array<string,mixed> $p */
	public function on_user_unsuspended( $p ): void {
		$uid = (int) ( ( is_array( $p ) ? $p['id'] ?? 0 : 0 ) );
		if ( $uid <= 0 ) {
			return;
		}
		foreach ( $this->skills->personal_ids_of_user( $uid ) as $id ) {
			$this->skills->set_susp_hidden( $id, false ); // mod_hidden reste inchangé
		}
	}

	/** @param array<string,mixed> $p */
	public function on_company_suspended( $p ): void {
		$cid = (int) ( ( is_array( $p ) ? $p['company_id'] ?? 0 : 0 ) );
		if ( $cid <= 0 ) {
			return;
		}
		foreach ( $this->skills->ids_of_company( $cid ) as $id ) {
			$this->skills->set_susp_hidden( $id, true );
		}
	}

	/** @param array<string,mixed> $p */
	public function on_company_restored( $p ): void {
		$cid = (int) ( ( is_array( $p ) ? $p['company_id'] ?? 0 : 0 ) );
		if ( $cid <= 0 ) {
			return;
		}
		foreach ( $this->skills->ids_of_company( $cid ) as $id ) {
			$this->skills->set_susp_hidden( $id, false ); // mod_hidden reste inchangé
		}
	}
}
