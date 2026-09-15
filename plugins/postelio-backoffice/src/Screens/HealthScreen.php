<?php
/**
 * Santé du système : état général en tête, puis groupes Plateforme / Données / E-mails / Sources /
 * Paiements / Tâches automatiques en lignes « nom · état · détail ». Le jargon technique est replié
 * dans « Détails techniques ». Aucun secret, aucune action destructive : l'écran ne fait que relire
 * l'état.
 *
 * @package Postelio\Backoffice\Screens
 */

namespace Postelio\Backoffice\Screens;

use Postelio\Backoffice\Menu;
use Postelio\Backoffice\Support\Data;
use Postelio\Backoffice\Support\Fmt;
use Postelio\Backoffice\Support\Health;
use Postelio\Backoffice\Ui\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HealthScreen extends Screen {

	/** @var array<string,string> Clé de contrôle du socle => libellé lisible. */
	private const CHECKS = array(
		'database'         => 'Base de données',
		'audit_table'      => 'Journal d\'audit',
		'dependencies_met' => 'Dépendances des modules',
		'schema'           => 'Schéma de données',
		'scheduler'        => 'Tâches planifiées',
	);

	protected function capability(): string {
		return Menu::CAP_ADMIN;
	}

	protected function eyebrow(): string {
		return 'Postelio · Système';
	}

	protected function body(): string {
		$snap   = Health::snapshot();
		$core   = (array) $snap['core'];
		$global = Health::global_status();

		$out = $this->header( 'Santé du système', 'Diagnostic de la plateforme, du socle aux services.', Ui::button( 'Relire l\'état', $this->url( 'postelio-health' ), '', true ) );

		$ok       = 0;
		$todo     = 0;
		$data     = array();
		$sources  = array();
		$payments = array();
		$others   = array();
		foreach ( (array) $snap['modules'] as $m ) {
			$m = (array) $m;
			if ( Health::OK === (string) $m['status'] ) {
				++$ok;
			} else {
				++$todo;
			}
			$key = (string) $m['module'];
			if ( 'job-sources' === $key || 'job_sources' === $key ) {
				$sources[] = $m;
			} elseif ( 'billing' === $key ) {
				$payments[] = $m;
			} elseif ( 'moderation' === $key ) {
				$others[] = $m;
			} else {
				$data[] = $m;
			}
		}

		$titles = array( Health::OK => 'Tout fonctionne normalement', Health::DEGRADED => 'Fonctionnement dégradé', Health::ERROR => 'Un composant est en erreur' );
		$out   .= Ui::status_banner(
			$global,
			$titles[ $global ] ?? Health::label( $global ),
			$ok . ' services opérationnels' . ( $todo > 0 ? ' · ' . $todo . ' à configurer' : '' ) . ' · ' . count( (array) $snap['modules'] ) . ' modules actifs',
			Ui::badge( Health::label( $global ), Health::variant( $global ), true )
		);

		// Plateforme.
		$checks = array( array( 'Socle Postelio', Ui::badge( Health::label( (string) $core['status'] ), Health::variant( (string) $core['status'] ), true ), 'Version ' . Fmt::or_dash( $core['version'] ?? '' ) ) );
		foreach ( (array) $core['checks'] as $key => $value ) {
			$label    = self::CHECKS[ (string) $key ] ?? ucfirst( str_replace( '_', ' ', (string) $key ) );
			$checks[] = array( $label, Ui::badge( $value ? 'OK' : 'Problème', $value ? 'success' : 'error', true ), '' );
		}
		$out .= Ui::card_open( 'Plateforme' ) . Ui::checks( $checks ) . Ui::details( 'Détails techniques', Ui::kv( array(
			'Version du socle'  => Ui::text( Fmt::or_dash( $core['version'] ?? '' ), false, true ),
			'Schéma de base'    => Ui::text( Fmt::or_dash( $core['schema'] ?? '' ), false, true ),
			'Version WordPress' => Ui::text( (string) get_bloginfo( 'version' ), false, true ),
			'Version PHP'       => Ui::text( PHP_VERSION, false, true ),
		), true ) ) . Ui::card_close();

		$out .= Ui::grid_open( 2 );
		$out .= Ui::card_open( 'Données', 'Modules métier.' ) . $this->module_checks( $data, 'Aucun module de données.' ) . Ui::card_close();
		$out .= Ui::card_open( 'E-mails', 'Transport et file d\'envoi.', Ui::button( 'Service e-mail', $this->url( 'postelio-notifications' ), 'ghost', true ) ) . $this->emails() . Ui::card_close();
		$out .= Ui::card_open( 'Sources d\'offres', 'Connecteurs partenaires.', Ui::button( 'Connecteurs', $this->url( 'postelio-sources' ), 'ghost', true ) ) . $this->module_checks( $sources, 'Aucun connecteur enregistré.' ) . Ui::card_close();
		$out .= Ui::card_open( 'Paiements', 'Facturation et confirmation de paiement.' ) . $this->module_checks( $payments, 'Module Facturation absent.' ) . Ui::card_close();
		$out .= Ui::card_open( 'Tâches automatiques' ) . $this->workers() . Ui::card_close();
		$out .= Ui::card_open( 'Sécurité et modération', '', Ui::button( 'Indicateurs', $this->url( 'postelio-settings', array( 'tab' => 'security' ) ), 'ghost', true ) ) . Ui::checks( array_merge(
			$this->to_checks( $others ),
			array(
				array( 'Stockage des fichiers', Ui::badge( Data::module_active( 'files' ) ? 'Privé (hors web)' : 'Module absent', Data::module_active( 'files' ) ? 'success' : 'neutral', true ), '' ),
			)
		) ) . Ui::card_close();
		$out .= Ui::grid_close();

		return $out;
	}

	/** @param array<int,array<string,mixed>> $modules @return array<int,array{0:string,1:string,2:string}> */
	private function to_checks( array $modules ): array {
		$rows = array();
		foreach ( $modules as $m ) {
			$rows[] = array( (string) $m['label'], Ui::badge( Health::label( (string) $m['status'] ), Health::variant( (string) $m['status'] ), true ), Fmt::or_dash( $m['meta'] ?? '' ) === '—' ? '' : (string) $m['meta'] );
		}
		return $rows;
	}

	/** @param array<int,array<string,mixed>> $modules */
	private function module_checks( array $modules, string $empty ): string {
		return empty( $modules ) ? Ui::help( $empty ) : Ui::checks( $this->to_checks( $modules ) );
	}

	private function emails(): string {
		$stats = Data::delivery_stats();
		if ( null === $stats ) {
			return Ui::checks( array( array( 'Service e-mail', Ui::badge( 'Statistiques indisponibles', 'neutral' ), '' ) ) );
		}
		$failed  = (int) ( $stats['failed'] ?? 0 );
		$pending = (int) ( $stats['pending'] ?? 0 );
		return Ui::checks( array(
			array( 'File d\'envoi', Ui::badge( $pending > 0 ? $pending . ' en attente' : 'Vide', 'info' ), (int) ( $stats['sent'] ?? 0 ) . ' envoyés au total' ),
			array( 'Échecs définitifs', Ui::badge( $failed > 0 ? (string) $failed : 'Aucun', $failed > 0 ? 'warning' : 'success', true ), ! empty( $stats['next_retry_at'] ) ? 'Prochaine tentative : ' . Fmt::datetime( $stats['next_retry_at'] ) : '' ),
		) );
	}

	private function workers(): string {
		$cron_ok = ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
		$next    = wp_next_scheduled( 'postelio_job_notifications_worker' );
		return Ui::checks( array(
			array( 'Planificateur', Ui::badge( $cron_ok ? 'Actif' : 'Cron système attendu', $cron_ok ? 'success' : 'info', true ), $cron_ok ? 'WP-Cron' : 'WP-Cron désactivé' ),
			array( 'Envoi des notifications', Ui::badge( $next ? 'Planifié' : 'Non planifié', $next ? 'success' : 'warning', true ), $next ? 'Prochain passage : ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $next ), 'd/m/Y H:i' ) : '' ),
		) );
	}
}
