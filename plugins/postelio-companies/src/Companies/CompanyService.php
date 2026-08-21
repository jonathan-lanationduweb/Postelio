<?php
/**
 * Logique métier des entreprises : création, mise à jour (avec verrouillage des
 * données légales vérifiées), calcul de complétion.
 *
 * Distinction stricte (exigence Lot 03) :
 *  - éditorial : toujours modifiable par un membre ;
 *  - légal : modifiable tant que l'entreprise n'est pas `verified` ; une fois
 *    vérifiée, l'identité légale est FIGÉE (non modifiable par le recruteur).
 *
 * @package Postelio\Companies\Companies
 */

namespace Postelio\Companies\Companies;

use Postelio\Companies\Members\MembershipRepository;
use Postelio\Companies\Members\MembershipService;
use Postelio\Companies\Verification\Siren;
use Postelio\Core\ApiError;
use Postelio\Core\Plugin as Core;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class CompanyService {

	private const EDITORIAL_KEYS = array(
		'secteur', 'activite', 'ville', 'effectif',
		'adresse', 'telephone', 'email', 'site', 'has_photo',
	);
	private const EDITORIAL_LIST_KEYS = array( 'avantages', 'valeurs' );
	private const EDITORIAL_OBJ_KEYS  = array( 'org', 'reseaux' );

	private const LEGAL_KEYS = array(
		'raison_sociale', 'nom_commercial', 'forme_juridique', 'siren', 'siret',
		'tva', 'naf_ape', 'adresse_siege', 'cp_siege', 'ville_siege', 'pays', 'date_creation',
	);

	private CompanyRepository $companies;
	private MembershipService $memberships;

	public function __construct( CompanyRepository $companies, MembershipService $memberships ) {
		$this->companies   = $companies;
		$this->memberships = $memberships;
	}

	/**
	 * Crée l'entreprise du recruteur courant et l'y rattache comme propriétaire.
	 *
	 * @param array<string, mixed> $input
	 * @throws ApiError conflict | validation_error
	 */
	public function create( int $actor_id, array $input ): int {
		// V1 : un recruteur appartient à une seule entreprise.
		if ( $this->memberships->company_of_user( $actor_id ) > 0 ) {
			throw new ApiError( 'conflict', 'Vous êtes déjà rattaché à une entreprise.' );
		}

		$nom = sanitize_text_field( (string) ( $input['nom'] ?? '' ) );
		if ( '' === $nom ) {
			throw ApiError::validation( array( 'nom' => 'Nom d\'entreprise requis.' ) );
		}
		$description    = sanitize_textarea_field( (string) ( $input['description'] ?? '' ) );
		$editorial      = $this->clean_editorial( (array) ( $input['editorial'] ?? array() ) );
		$legal_declared = $this->clean_legal( (array) ( $input['legal'] ?? array() ) );

		// Anti-doublon SIREN à la création.
		if ( ! empty( $legal_declared['siren'] ) ) {
			if ( ! Siren::is_valid_siren( $legal_declared['siren'] ) ) {
				throw ApiError::validation( array( 'siren' => 'SIREN invalide.' ) );
			}
			if ( $this->companies->find_id_by_siren( $legal_declared['siren'] ) > 0 ) {
				throw new ApiError( 'conflict', 'Une entreprise avec ce SIREN existe déjà.' );
			}
		}

		$company_id = $this->companies->create( $actor_id, $nom, $description, $editorial, $legal_declared );
		if ( 0 === $company_id ) {
			throw new ApiError( 'server_error', 'Création de l\'entreprise impossible.' );
		}

		$this->memberships->add_member( $company_id, $actor_id, MembershipRepository::ROLE_OWNER );

		Core::instance()->events()->emit(
			'company.created',
			array(
				'company_id'    => $company_id,
				'resource_type' => 'company',
				'resource_id'   => (string) $company_id,
				'audit'         => array( 'by' => $actor_id ),
			)
		);

		return $company_id;
	}

	/**
	 * Met à jour l'entreprise. Refuse toute modification légale si `verified`.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed> Modèle interne à jour.
	 * @throws ApiError forbidden | conflict | validation_error | not_found
	 */
	public function update( int $actor_id, int $company_id, array $input ): array {
		$company = $this->companies->get( $company_id );
		if ( null === $company ) {
			throw ApiError::not_found();
		}
		if ( ! $this->memberships->can_manage( $company_id, $actor_id ) ) {
			throw ApiError::forbidden( 'Vous ne gérez pas cette entreprise.' );
		}

		$status = (string) ( $company['verification']['status'] ?? 'unverified' );

		// Éditorial (toujours autorisé).
		if ( array_key_exists( 'nom', $input ) || array_key_exists( 'description', $input ) ) {
			$this->companies->update_nom_description(
				$company_id,
				array_key_exists( 'nom', $input ) ? sanitize_text_field( (string) $input['nom'] ) : null,
				array_key_exists( 'description', $input ) ? sanitize_textarea_field( (string) $input['description'] ) : null
			);
		}
		if ( array_key_exists( 'editorial', $input ) ) {
			$merged = array_merge( $company['editorial'] ?? array(), $this->clean_editorial( (array) $input['editorial'] ) );
			unset( $merged['logo_id'], $merged['logo_url'] );
			$this->companies->update_editorial( $company_id, $merged );
		}

		// Légal : verrouillé si vérifié.
		if ( array_key_exists( 'legal', $input ) ) {
			if ( 'verified' === $status ) {
				throw ApiError::forbidden( 'Identité légale vérifiée : non modifiable.' );
			}
			$legal = array_merge( $company['legal_declared'] ?? array(), $this->clean_legal( (array) $input['legal'] ) );
			if ( ! empty( $legal['siren'] ) ) {
				if ( ! Siren::is_valid_siren( $legal['siren'] ) ) {
					throw ApiError::validation( array( 'siren' => 'SIREN invalide.' ) );
				}
				if ( $this->companies->find_id_by_siren( $legal['siren'], $company_id ) > 0 ) {
					throw new ApiError( 'conflict', 'Une entreprise avec ce SIREN existe déjà.' );
				}
			}
			$this->companies->update_legal_declared( $company_id, $legal );
		}

		Core::instance()->events()->emit(
			'company.updated',
			array(
				'company_id'    => $company_id,
				'resource_type' => 'company',
				'resource_id'   => (string) $company_id,
				'audit'         => array( 'legal_touched' => array_key_exists( 'legal', $input ) ),
			)
		);

		return $this->companies->get( $company_id );
	}

	/**
	 * Complétion du profil (aligne la jauge du front).
	 *
	 * @param array<string, mixed> $company
	 * @return array{pct:int, missing:string[]}
	 */
	public static function completion( array $company ): array {
		$ed    = $company['editorial'] ?? array();
		$legal = $company['legal_declared'] ?? array();
		$checks = array(
			'logo'         => ! empty( $ed['logo_id'] ),
			'presentation' => strlen( (string) ( $company['description'] ?? '' ) ) > 40,
			'avantages'    => count( (array) ( $ed['avantages'] ?? array() ) ) >= 3,
			'valeurs'      => count( (array) ( $ed['valeurs'] ?? array() ) ) >= 2,
			'legal'        => ! empty( $legal['siren'] ) && ! empty( $legal['raison_sociale'] ),
			'contact'      => ! empty( $ed['email'] ) || ! empty( $ed['telephone'] ),
		);
		$done    = count( array_filter( $checks ) );
		$missing = array_keys( array_filter( $checks, static fn( $ok ) => ! $ok ) );
		return array( 'pct' => (int) round( ( $done / count( $checks ) ) * 100 ), 'missing' => array_values( $missing ) );
	}

	/**
	 * @param array<string, mixed> $in
	 * @return array<string, mixed>
	 */
	private function clean_editorial( array $in ): array {
		$out = array();
		foreach ( self::EDITORIAL_KEYS as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$out[ $k ] = 'has_photo' === $k ? (bool) $in[ $k ] : sanitize_text_field( (string) $in[ $k ] );
			}
		}
		foreach ( self::EDITORIAL_LIST_KEYS as $k ) {
			if ( array_key_exists( $k, $in ) && is_array( $in[ $k ] ) ) {
				$out[ $k ] = array_values( array_map( 'sanitize_text_field', array_map( 'strval', $in[ $k ] ) ) );
			}
		}
		foreach ( self::EDITORIAL_OBJ_KEYS as $k ) {
			if ( array_key_exists( $k, $in ) && is_array( $in[ $k ] ) ) {
				$out[ $k ] = $this->clean_assoc( $in[ $k ] );
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $in
	 * @return array<string, mixed>
	 */
	private function clean_legal( array $in ): array {
		$out = array();
		foreach ( self::LEGAL_KEYS as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$out[ $k ] = sanitize_text_field( (string) $in[ $k ] );
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $in
	 * @return array<string, mixed>
	 */
	private function clean_assoc( array $in ): array {
		$out = array();
		foreach ( $in as $k => $v ) {
			$key = sanitize_key( (string) $k );
			if ( is_array( $v ) ) {
				$out[ $key ] = array_values( array_map( 'sanitize_text_field', array_map( 'strval', $v ) ) );
			} else {
				$out[ $key ] = sanitize_text_field( (string) $v );
			}
		}
		return $out;
	}
}
