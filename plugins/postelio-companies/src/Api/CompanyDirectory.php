<?php
/**
 * Contrat public STABLE d'annuaire d'entreprises, destiné aux autres plugins
 * (postelio-jobs, etc.). Permet de résoudre l'appartenance recruteur ↔ entreprise
 * et les identifiants (interne ↔ UUID public) SANS accéder aux internes de
 * postelio-companies (tables, meta, CPT).
 *
 * @package Postelio\Companies\Api
 */

namespace Postelio\Companies\Api;

use Postelio\Companies\Companies\CompanyRepository;
use Postelio\Companies\Members\MembershipRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CompanyDirectory {

	/** Entreprise (ID interne) dont l'utilisateur est membre, 0 sinon. */
	public static function company_of_user( int $user_id ): int {
		return ( new MembershipRepository() )->company_of_user( $user_id );
	}

	public static function is_member( int $company_id, int $user_id ): bool {
		return ( new MembershipRepository() )->is_member( $company_id, $user_id );
	}

	public static function role_of( int $company_id, int $user_id ): ?string {
		return ( new MembershipRepository() )->role( $company_id, $user_id );
	}

	/** Propriétaire (owner) de l'entreprise, ou null. Sert au ciblage des notifications. */
	public static function owner_of( int $company_id ): ?int {
		foreach ( ( new MembershipRepository() )->members_of( $company_id ) as $m ) {
			if ( MembershipRepository::ROLE_OWNER === ( $m['role_in_company'] ?? '' ) ) {
				return (int) $m['user_id'];
			}
		}
		return null;
	}

	public static function exists( int $company_id ): bool {
		return ( new CompanyRepository() )->exists( $company_id );
	}

	/** ID interne depuis l'UUID public (0 si inconnu). */
	public static function id_from_uuid( string $uuid ): int {
		$c = ( new CompanyRepository() )->get_by_uuid( $uuid );
		return $c ? (int) $c['id'] : 0;
	}

	public static function uuid_of( int $company_id ): ?string {
		$c = ( new CompanyRepository() )->get( $company_id );
		return $c ? (string) $c['uuid'] : null;
	}

	public static function name_of( int $company_id ): ?string {
		$c = ( new CompanyRepository() )->get( $company_id );
		return $c ? (string) $c['nom'] : null;
	}

	/**
	 * Résumé public d'une entreprise pour l'affichage dans une offre.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function public_summary( int $company_id ): ?array {
		$c = ( new CompanyRepository() )->get( $company_id );
		if ( null === $c ) {
			return null;
		}
		return array(
			'uuid'     => $c['uuid'],
			'nom'      => $c['nom'],
			'ville'    => $c['editorial']['ville'] ?? null,
			'secteur'  => $c['editorial']['secteur'] ?? null,
			'logo_url' => $c['editorial']['logo_url'] ?? null,
			'verified' => 'verified' === ( $c['verification']['status'] ?? 'unverified' ),
		);
	}
}
