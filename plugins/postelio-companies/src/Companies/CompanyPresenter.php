<?php
/**
 * Présentation des entreprises selon l'audience. N'expose JAMAIS l'ID interne
 * (numérique) : seul l'`uuid` public identifie la ressource (D2).
 *
 *  - public  : éditorial + badge de vérification + identité légale VÉRIFIÉE (publique) ;
 *  - owner   : + identité légale déclarée, complétion, statut détaillé (motif si rejet) ;
 *  - admin   : + provider, reviewer, motif complet.
 *
 * @package Postelio\Companies\Companies
 */

namespace Postelio\Companies\Companies;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class CompanyPresenter {

	/** Champs d'identité légale publics une fois vérifiés (le SIREN est public). */
	private const PUBLIC_VERIFIED_LEGAL = array( 'raison_sociale', 'forme_juridique', 'siren', 'ville_siege', 'naf_ape' );

	/**
	 * @param array<string, mixed> $c Modèle interne.
	 * @return array<string, mixed>
	 */
	public static function public_view( array $c ): array {
		$status   = (string) ( $c['verification']['status'] ?? 'unverified' );
		$verified = 'verified' === $status;
		$legal    = array();
		if ( $verified ) {
			foreach ( self::PUBLIC_VERIFIED_LEGAL as $k ) {
				$legal[ $k ] = $c['legal_verified'][ $k ] ?? null;
			}
		}
		return array(
			'uuid'         => $c['uuid'],
			'nom'          => $c['nom'],
			'description'  => $c['description'],
			'editorial'    => self::public_editorial( $c['editorial'] ?? array() ),
			'verified'     => $verified,
			'verification' => array( 'status' => $status ),
			'legal'        => $legal, // vide si non vérifié
		);
	}

	/**
	 * @param array<string, mixed> $c
	 * @return array<string, mixed>
	 */
	public static function owner_view( array $c ): array {
		$status = (string) ( $c['verification']['status'] ?? 'unverified' );
		$verif  = array(
			'status'       => $status,
			'provider'     => $c['verification']['provider'] ?? null,
			'requested_at' => $c['verification']['requested_at'] ?? null,
			'verified_at'  => $c['verification']['verified_at'] ?? null,
			'legal_locked' => 'verified' === $status,
		);
		// Le motif n'est communiqué au recruteur que s'il est actionnable (rejet).
		if ( 'rejected' === $status ) {
			$verif['motif'] = $c['verification']['motif'] ?? null;
		}

		return array(
			'uuid'           => $c['uuid'],
			'nom'            => $c['nom'],
			'description'    => $c['description'],
			'editorial'      => $c['editorial'] ?? array(),
			'legal_declared' => $c['legal_declared'] ?? array(),
			'legal_verified' => $c['legal_verified'] ?? array(),
			'verification'   => $verif,
			'completion'     => CompanyService::completion( $c ),
		);
	}

	/**
	 * @param array<string, mixed> $c
	 * @return array<string, mixed>
	 */
	public static function admin_view( array $c ): array {
		$view                 = self::owner_view( $c );
		$view['verification'] = $c['verification'] ?? array( 'status' => 'unverified' ); // motif + reviewer complets
		return $view;
	}

	/**
	 * @param array<string, mixed> $ed
	 * @return array<string, mixed>
	 */
	private static function public_editorial( array $ed ): array {
		// Coordonnées de recrutement publiques ; aucun identifiant/flag interne.
		unset( $ed['has_photo'], $ed['logo_id'] );
		return $ed; // logo_url conservé (public)
	}
}
