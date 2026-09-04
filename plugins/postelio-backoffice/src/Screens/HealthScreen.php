<?php
/**
 * Santé du système : synthèse lisible (état global, socle, modules) organisée en groupes
 * Plateforme / Données / Intégrations / Tâches automatiques / Sécurité. Le jargon technique est
 * replié dans « Détails techniques ». Aucun secret, aucune action destructive : l'écran ne fait que
 * relire l'état.
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

	protected function body(): string {
		$snap   = Health::snapshot();
		$core   = (array) $snap['core'];
		$global = Health::global_status();

		$out = Ui::page_header(
			'Santé du système',
			'Diagnostic de la plateforme Postelio.',
			Ui::badge( Health::label( $global ), Health::variant( $global ), true ) . Ui::button( 'Relire l\'état', $this->url( 'postelio-health' ), 'ghost', true )
		);

		if ( Health::ERROR === $global ) {
			$out .= Ui::alert( 'Un composant signale une erreur. Consultez les sections ci-dessous.', 'error' );
		} elseif ( Health::DEGRADED === $global ) {
			$out .= Ui::alert( 'Fonctionnement dégradé sur au moins un composant.', 'warning' );
		}

		$ok    = 0;
		$todo  = 0;
		$data  = array();
		$integ = array();
		foreach ( (array) $snap['modules'] as $m ) {
			$m = (array) $m;
			if ( Health::OK === (string) $m['status'] ) {
				++$ok;
			} else {
				++$todo;
			}
			if ( in_array( (string) $m['module'], Health::PROVIDER_MODULES, true ) ) {
				$integ[] = $m;
			} else {
				$data[] = $m;
			}
		}

		$out .= '<div class="bo-stats bo-stats--4">';
		$out .= Ui::stat( 'État global', Health::label( $global ), '', Health::OK !== $global );
		$out .= Ui::stat( 'Services opérationnels', $ok );
		$out .= Ui::stat( 'À configurer', $todo, '', $todo > 0 );
		$out .= Ui::stat( 'Modules actifs', count( (array) $snap['modules'] ) );
		$out .= '</div>';

		// Plateforme.
		$pairs = array( 'Socle Postelio' => Ui::badge( Health::label( (string) $core['status'] ), Health::variant( (string) $core['status'] ), true ) );
		foreach ( (array) $core['checks'] as $key => $value ) {
			$label           = self::CHECKS[ (string) $key ] ?? ucfirst( str_replace( '_', ' ', (string) $key ) );
			$pairs[ $label ] = Ui::badge( $value ? 'OK' : 'Problème', $value ? 'success' : 'error', true );
		}
		$out .= Ui::card_open( 'Plateforme' ) . Ui::kv( $pairs ) . Ui::details( 'Détails techniques', Ui::kv( array(
			'Version du socle'  => Ui::text( Fmt::or_dash( $core['version'] ?? '' ), false, true ),
			'Schéma de base'    => Ui::text( Fmt::or_dash( $core['schema'] ?? '' ), false, true ),
			'Version WordPress' => Ui::text( (string) get_bloginfo( 'version' ), false, true ),
			'Version PHP'       => Ui::text( PHP_VERSION, false, true ),
		) ) ) . Ui::card_close();

		// Données / Intégrations.
		$out .= '<div class="bo-grid bo-grid--2">';
		$out .= Ui::card_open( 'Données' ) . Ui::table( array( 'Module', 'État', 'Détail' ), $this->rows( $data ), 'Aucun module de données.' ) . Ui::card_close();
		$out .= Ui::card_open( 'Intégrations' ) . Ui::table( array( 'Service', 'État', 'Détail' ), $this->rows( $integ ), 'Aucune intégration.' ) . Ui::card_close();
		$out .= '</div>';

		// Tâches automatiques + sécurité.
		$out .= '<div class="bo-grid bo-grid--2">';
		$out .= Ui::card_open( 'Tâches automatiques' ) . $this->workers() . Ui::card_close();
		$out .= Ui::card_open( 'Sécurité' ) . Ui::kv( array(
			'Stockage des fichiers' => Ui::badge( Data::module_active( 'files' ) ? 'Privé (hors web)' : 'Module absent', Data::module_active( 'files' ) ? 'success' : 'neutral', true ),
			'Modération de contenu' => Ui::badge( Data::module_active( 'moderation' ) ? 'Active' : 'Inactive', Data::module_active( 'moderation' ) ? 'success' : 'neutral', true ),
		) ) . '<p class="bo-help">' . Ui::button( 'Tous les indicateurs de sécurité', $this->url( 'postelio-settings', array( 'tab' => 'security' ) ), 'ghost', true ) . '</p>' . Ui::card_close();
		$out .= '</div>';

		return $out;
	}

	/** @param array<int,array<string,mixed>> $modules @return array<int,array<int,string>> */
	private function rows( array $modules ): array {
		$rows = array();
		foreach ( $modules as $m ) {
			$rows[] = array(
				Ui::text( (string) $m['label'], true ),
				Ui::badge( Health::label( (string) $m['status'] ), Health::variant( (string) $m['status'] ), true ),
				Ui::text( Fmt::or_dash( $m['meta'] ?? '' ), false, true ),
			);
		}
		return $rows;
	}

	private function workers(): string {
		$cron_ok = ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
		$pairs   = array(
			'Planificateur' => Ui::badge( $cron_ok ? 'Actif' : 'Cron système attendu', $cron_ok ? 'success' : 'info', true ),
		);
		$stats = Data::delivery_stats();
		if ( null !== $stats ) {
			$failed                       = (int) ( $stats['failed'] ?? 0 );
			$pairs['E-mails en attente']  = Ui::text( (string) (int) ( $stats['pending'] ?? 0 ) );
			$pairs['E-mails envoyés']     = Ui::text( (string) (int) ( $stats['sent'] ?? 0 ) );
			$pairs['E-mails en échec']    = Ui::badge( (string) $failed, $failed > 0 ? 'warning' : 'success' );
			if ( ! empty( $stats['next_retry_at'] ) ) {
				$pairs['Prochaine tentative'] = Ui::text( Fmt::datetime( $stats['next_retry_at'] ) );
			}
		} else {
			$pairs['Service e-mail'] = Ui::badge( 'Statistiques indisponibles', 'neutral' );
		}
		return Ui::kv( $pairs ) . '<p class="bo-help">' . Ui::button( 'Ouvrir le service e-mail', $this->url( 'postelio-notifications' ), 'ghost', true ) . '</p>';
	}
}
