<?php
/**
 * Intègre le rattachement entreprise dans le profil recruteur.
 *
 * Écoute l'événement `company.member_added` émis par postelio-companies et
 * renseigne `RecruiterProfile.company_id`. Découplage strict : postelio-companies
 * ne touche jamais la table des profils recruteurs ; users met à jour SA donnée.
 *
 * @package Postelio\Users\Integration
 */

namespace Postelio\Users\Integration;

use Postelio\Core\Events;
use Postelio\Users\Profiles\RecruiterProfileRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CompanyLink {

	private RecruiterProfileRepository $recruiters;
	private Events $events;

	public function __construct( RecruiterProfileRepository $recruiters, Events $events ) {
		$this->recruiters = $recruiters;
		$this->events     = $events;
	}

	public function register(): void {
		$this->events->on( 'company.member_added', array( $this, 'on_member_added' ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function on_member_added( $payload = array() ): void {
		$payload    = is_array( $payload ) ? $payload : array();
		$user_id    = (int) ( $payload['user_id'] ?? 0 );
		$company_id = (int) ( $payload['company_id'] ?? 0 );
		if ( $user_id > 0 && $company_id > 0 ) {
			$this->recruiters->set_company( $user_id, $company_id );
		}
	}
}
