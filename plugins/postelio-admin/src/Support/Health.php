<?php
/**
 * Agrégation de la santé des modules Postelio pour la page « Santé du système ». Combine le
 * snapshot du core (Registry + schéma + DB/audit) et les endpoints /health des modules qui en
 * exposent (moderation, billing, job-sources). Aucun secret. Dégrade proprement si un module
 * est absent.
 *
 * @package Postelio\Admin\Support
 */

namespace Postelio\Admin\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Health {

	public const OK           = 'ok';
	public const DEGRADED     = 'degraded';
	public const UNCONFIGURED = 'unconfigured';
	public const ERROR        = 'error';
	public const ABSENT       = 'absent';

	/** @return array{core:array<string,mixed>, modules:array<int,array<string,mixed>>} */
	public static function snapshot(): array {
		$core = array( 'status' => self::ABSENT, 'version' => '—', 'schema' => '—', 'checks' => array() );
		$modules = array();

		if ( class_exists( '\\Postelio\\Core\\Plugin' ) && class_exists( '\\Postelio\\Core\\Health\\Status' ) ) {
			try {
				$snap = ( new \Postelio\Core\Health\Status( \Postelio\Core\Plugin::instance()->registry() ) )->snapshot();
				$core = array(
					'status'  => (string) ( $snap['status'] ?? self::DEGRADED ),
					'version' => (string) ( $snap['core_version'] ?? '—' ),
					'schema'  => (string) ( $snap['schema_version'] ?? '—' ),
					'checks'  => (array) ( $snap['checks'] ?? array() ),
				);
				$registered = (array) ( $snap['modules'] ?? array() );
			} catch ( \Throwable $e ) {
				$registered = array();
			}
		} else {
			$registered = array();
		}

		// Libellés lisibles par module.
		$labels = array(
			'core' => 'Core', 'users' => 'Utilisateurs', 'companies' => 'Entreprises', 'jobs' => 'Offres',
			'applications' => 'Candidatures', 'files' => 'CV & fichiers', 'messaging' => 'Messagerie',
			'interviews' => 'Entretiens', 'notifications' => 'Notifications', 'job-sources' => 'Sources d\'offres',
			'job_sources' => 'Sources d\'offres', 'moderation' => 'Modération', 'billing' => 'Facturation', 'skills' => 'Savoir-faire',
		);

		foreach ( $registered as $module ) {
			if ( 'core' === $module ) {
				continue;
			}
			$row = array( 'module' => $module, 'label' => $labels[ $module ] ?? ucfirst( (string) $module ), 'status' => self::OK, 'meta' => '' );
			// Détails via /health pour les modules qui en exposent.
			if ( 'moderation' === $module ) {
				$h = Contracts::rest( 'GET', '/postelio/v1/moderation/health' );
				$d = is_array( $h['data'] ) ? ( $h['data']['data'] ?? array() ) : array();
				$row['meta'] = 'Provider : ' . (string) ( $d['provider'] ?? 'local_only' );
			} elseif ( 'billing' === $module ) {
				$h = Contracts::rest( 'GET', '/postelio/v1/billing/health' );
				$d = is_array( $h['data'] ) ? ( $h['data']['data'] ?? array() ) : array();
				$configured = ! empty( $d['configured'] );
				$mode       = (string) ( $d['mode'] ?? 'unknown' );
				$row['status'] = $configured ? self::OK : self::UNCONFIGURED;
				$row['meta']   = 'Stripe : ' . ( $configured ? 'configuré (' . $mode . ')' : 'non configuré / mode test' )
					. ' · Facture légale : ' . ( ! empty( $d['invoice_legal_ready'] ) ? 'prête' : 'à configurer' );
			} elseif ( 'job-sources' === $module || 'job_sources' === $module ) {
				$h = Contracts::rest( 'GET', '/postelio/v1/job-sources/health' );
				$d = is_array( $h['data'] ) ? ( $h['data']['data'] ?? $h['data'] ) : array();
				$configured = ! empty( $d['configured'] ) || ! empty( $d['providers'] );
				$row['status'] = $configured ? self::OK : self::UNCONFIGURED;
				$row['meta']   = $configured ? 'France Travail : configuré' : 'France Travail : à configurer (aucune clé)';
			}
			$modules[] = $row;
		}

		return array( 'core' => $core, 'modules' => $modules );
	}

	public static function badge_variant( string $status ): string {
		switch ( $status ) {
			case self::OK:           return 'success';
			case self::DEGRADED:     return 'warning';
			case self::UNCONFIGURED: return 'info';
			case self::ERROR:        return 'error';
		}
		return 'neutral';
	}

	public static function label( string $status ): string {
		switch ( $status ) {
			case self::OK:           return 'OK';
			case self::DEGRADED:     return 'DÉGRADÉ';
			case self::UNCONFIGURED: return 'NON CONFIGURÉ';
			case self::ERROR:        return 'ERREUR';
			case self::ABSENT:       return 'ABSENT';
		}
		return strtoupper( $status );
	}
}
