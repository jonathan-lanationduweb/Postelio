<?php
/**
 * Agrégation de la SANTÉ des modules Postelio pour l'écran Santé et le tableau de bord. Combine le
 * snapshot du core (`Core\Health\Status`) et les endpoints `/health` des modules qui en exposent.
 * Pure présentation : aucune décision métier, aucun secret, dégradation propre si un module manque.
 *
 * @package Postelio\Backoffice\Support
 */

namespace Postelio\Backoffice\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Health {

	public const OK           = 'ok';
	public const DEGRADED     = 'degraded';
	public const UNCONFIGURED = 'unconfigured';
	public const ERROR        = 'error';
	public const ABSENT       = 'absent';

	/** Modules « intégrations » (le reste est classé en « données »). */
	public const PROVIDER_MODULES = array( 'moderation', 'billing', 'job-sources', 'job_sources' );

	/** @var array<string,string> Libellés lisibles par module. */
	private const LABELS = array(
		'core' => 'Core', 'users' => 'Utilisateurs', 'companies' => 'Entreprises', 'jobs' => 'Offres',
		'applications' => 'Candidatures', 'files' => 'CV & fichiers', 'messaging' => 'Messagerie',
		'interviews' => 'Entretiens', 'notifications' => 'Notifications', 'job-sources' => 'Sources d\'offres',
		'job_sources' => 'Sources d\'offres', 'moderation' => 'Modération', 'billing' => 'Facturation',
		'skills' => 'Savoir-faire', 'alerts' => 'Favoris & Alertes', 'site' => 'Site',
	);

	/** @var array{core:array<string,mixed>, modules:array<int,array<string,mixed>>}|null Cache de requête. */
	private static ?array $cache = null;

	/** @return array{core:array<string,mixed>, modules:array<int,array<string,mixed>>} */
	public static function snapshot(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$core       = array( 'status' => self::ABSENT, 'version' => '—', 'schema' => '—', 'checks' => array() );
		$registered = array();
		$modules    = array();

		if ( class_exists( '\\Postelio\\Core\\Plugin' ) && class_exists( '\\Postelio\\Core\\Health\\Status' ) ) {
			try {
				$snap       = ( new \Postelio\Core\Health\Status( \Postelio\Core\Plugin::instance()->registry() ) )->snapshot();
				$core       = array(
					'status'  => (string) ( $snap['status'] ?? self::DEGRADED ),
					'version' => (string) ( $snap['core_version'] ?? '—' ),
					'schema'  => (string) ( $snap['schema_version'] ?? '—' ),
					'checks'  => (array) ( $snap['checks'] ?? array() ),
				);
				$registered = (array) ( $snap['modules'] ?? array() );
			} catch ( \Throwable $e ) {
				$registered = array();
			}
		}

		foreach ( $registered as $module ) {
			$module = (string) $module;
			if ( 'core' === $module ) {
				continue;
			}
			$row = array( 'module' => $module, 'label' => self::LABELS[ $module ] ?? ucfirst( $module ), 'status' => self::OK, 'meta' => '' );

			if ( 'moderation' === $module ) {
				$d           = Rest::payload( '/postelio/v1/moderation/health' );
				$row['meta'] = 'Fournisseur : ' . (string) ( $d['provider'] ?? 'local_only' );
			} elseif ( 'billing' === $module ) {
				$d             = Rest::payload( '/postelio/v1/billing/health' );
				$configured    = ! empty( $d['configured'] );
				$row['status'] = $configured ? self::OK : self::UNCONFIGURED;
				$row['meta']   = 'Stripe : ' . ( $configured ? 'configuré (' . (string) ( $d['mode'] ?? 'inconnu' ) . ')' : 'non configuré' )
					. ' · Facture légale : ' . ( ! empty( $d['invoice_legal_ready'] ) ? 'prête' : 'à configurer' );
			} elseif ( 'job-sources' === $module || 'job_sources' === $module ) {
				$d             = Rest::payload( '/postelio/v1/job-sources/health' );
				$configured    = ! empty( $d['configured'] ) || ! empty( $d['providers'] );
				$row['status'] = $configured ? self::OK : self::UNCONFIGURED;
				$row['meta']   = $configured ? 'France Travail : configuré' : 'France Travail : à configurer (aucune clé)';
			}
			$modules[] = $row;
		}

		self::$cache = array( 'core' => $core, 'modules' => $modules );
		return self::$cache;
	}

	/** Statut global agrégé : error > degraded > ok. */
	public static function global_status(): string {
		$snap     = self::snapshot();
		$statuses = array( (string) ( $snap['core']['status'] ?? self::OK ) );
		foreach ( $snap['modules'] as $m ) {
			$statuses[] = (string) $m['status'];
		}
		if ( in_array( self::ERROR, $statuses, true ) ) {
			return self::ERROR;
		}
		if ( in_array( self::DEGRADED, $statuses, true ) ) {
			return self::DEGRADED;
		}
		return self::OK;
	}

	public static function label( string $status ): string {
		$map = array( self::OK => 'OK', self::DEGRADED => 'Dégradé', self::UNCONFIGURED => 'À configurer', self::ERROR => 'Erreur', self::ABSENT => 'Absent' );
		return $map[ $status ] ?? ucfirst( $status );
	}

	public static function variant( string $status ): string {
		$map = array( self::OK => 'success', self::DEGRADED => 'warning', self::UNCONFIGURED => 'info', self::ERROR => 'error' );
		return $map[ $status ] ?? 'neutral';
	}
}
