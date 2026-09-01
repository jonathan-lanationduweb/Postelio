<?php
/**
 * Santé du système : synthèse globale (OK / DÉGRADÉ / ERREUR) + sections Plateforme / Données /
 * Workers / Providers / Sécurité. Agrège le snapshot du core et l'état des modules (et leurs
 * /health). Aucun secret. Bouton « Rafraîchir » non destructif (relecture d'état).
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Contracts;
use Postelio\Admin\Support\Health;
use Postelio\Admin\Support\Metrics;
use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HealthPage extends Page {

	/** Modules « données » vs « providers » (le reste tombe en Données par défaut). */
	private const DATA_MODULES     = array( 'users', 'companies', 'jobs', 'applications', 'files', 'messaging', 'interviews', 'skills' );
	private const PROVIDER_MODULES = array( 'moderation', 'billing', 'job-sources', 'job_sources' );

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_ADMIN;
	}

	protected function body(): string {
		$snap = Health::snapshot();
		$core = $snap['core'];

		$global = $this->global_status( (string) $core['status'], (array) $snap['modules'] );
		$refresh = '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-health', array( 'r' => (string) $this->paged() ) ) ) . '">Rafraîchir l\'état</a>';

		$out  = Ui::toolbar( 'Santé du système', 'Diagnostic de la plateforme Postelio (sans aucun secret).', $refresh );

		// Synthèse globale.
		$out .= '<div class="pst-admin-grid">';
		$out .= Ui::stat( 'État global', Health::label( $global ), '', Health::ERROR === $global, false, $this->emoji( $global ) );
		$out .= Ui::stat( 'Version core', (string) $core['version'], 'schéma ' . (string) $core['schema'] );
		$out .= Ui::stat( 'Modules actifs', (string) count( (array) $snap['modules'] ), 'enregistrés' );
		$out .= '</div>';

		if ( Health::ERROR === $global ) {
			$out .= Ui::alert( 'Un ou plusieurs composants signalent une erreur. Consultez les sections ci-dessous.', 'error' );
		} elseif ( Health::DEGRADED === $global ) {
			$out .= Ui::alert( 'Fonctionnement dégradé sur au moins un composant.', 'warning' );
		}

		// Plateforme.
		$out .= Ui::card_open( 'Plateforme' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Statut</dt><dd>' . Ui::badge( Health::label( (string) $core['status'] ), Health::badge_variant( (string) $core['status'] ), true ) . '</dd>';
		$check_labels = array(
			'database' => 'Base de données', 'audit_table' => 'Journal d\'audit', 'dependencies_met' => 'Dépendances',
			'schema' => 'Schéma', 'scheduler' => 'Tâches planifiées',
		);
		foreach ( (array) $core['checks'] as $k => $v ) {
			$label = $check_labels[ (string) $k ] ?? ucfirst( str_replace( '_', ' ', (string) $k ) );
			$out  .= '<dt>' . esc_html( $label ) . '</dt><dd>' . Ui::badge( $v ? 'OK' : 'KO', $v ? 'success' : 'error' ) . '</dd>';
		}
		$out .= '</dl>';
		$out .= '<details class="pst-admin-details"><summary>Détails techniques</summary><dl class="pst-admin-kv">'
			. '<dt>Version core</dt><dd>' . esc_html( (string) $core['version'] ) . '</dd>'
			. '<dt>Schéma DB</dt><dd>' . esc_html( (string) $core['schema'] ) . '</dd>'
			. '</dl></details>';
		$out .= Ui::card_close();

		// Répartition Données / Providers.
		$data = array();
		$prov = array();
		foreach ( (array) $snap['modules'] as $m ) {
			if ( in_array( (string) $m['module'], self::PROVIDER_MODULES, true ) ) {
				$prov[] = $m;
			} else {
				$data[] = $m;
			}
		}

		$out .= Ui::card_open( 'Données' ) . Ui::table( array( 'Élément', 'État', 'Détails' ), $this->rows( $data ), 'Aucun module de données.' ) . Ui::card_close();
		$out .= Ui::card_open( 'Intégrations' ) . Ui::table( array( 'Service', 'État', 'Détails' ), $this->rows( $prov ), 'Aucune intégration.' ) . Ui::card_close();

		// Tâches automatiques.
		$out .= Ui::card_open( 'Tâches automatiques' ) . $this->workers() . Ui::card_close();

		// Sécurité (indicateurs synthétiques).
		$out .= Ui::card_open( 'Sécurité' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Stockage fichiers</dt><dd>' . Ui::badge( Contracts::module_active( 'files' ) ? 'Privé' : 'Module absent', Contracts::module_active( 'files' ) ? 'success' : 'neutral', true ) . '</dd>';
		$out .= '<dt>Modération</dt><dd>' . Ui::badge( Contracts::module_active( 'moderation' ) ? 'Active' : 'Inactive', Contracts::module_active( 'moderation' ) ? 'success' : 'neutral', true ) . '</dd>';
		$out .= '<dt>Détails complets</dt><dd><a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-settings', array( 'tab' => 'security' ) ) ) . '">Onglet Sécurité des réglages</a></dd>';
		$out .= '</dl>' . Ui::card_close();

		return $out;
	}

	/** @param array<int,array<string,mixed>> $modules @return array<int,array<int,string>> */
	private function rows( array $modules ): array {
		$rows = array();
		foreach ( $modules as $m ) {
			$rows[] = array(
				Ui::text( (string) $m['label'], true ),
				Ui::badge( Health::label( (string) $m['status'] ), Health::badge_variant( (string) $m['status'] ), true ),
				Ui::text( (string) ( $m['meta'] ?? '' ), false, true ),
			);
		}
		return $rows;
	}

	private function workers(): string {
		$active = ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
		$h      = '<dl class="pst-admin-kv"><dt>Tâches planifiées</dt><dd>' . Ui::badge( $active ? 'Actives' : 'Cron système attendu', $active ? 'success' : 'info', true ) . '</dd>';
		if ( Contracts::has( '\\Postelio\\Notifications\\Api\\NotificationDirectory' ) && method_exists( '\\Postelio\\Notifications\\Api\\NotificationDirectory', 'delivery_stats' ) ) {
			$st = (array) call_user_func( array( '\\Postelio\\Notifications\\Api\\NotificationDirectory', 'delivery_stats' ) );
			$failed = (int) ( $st['failed'] ?? 0 );
			$h .= '<dt>Envois e-mail en attente</dt><dd>' . esc_html( (string) (int) ( $st['pending'] ?? 0 ) ) . '</dd>';
			$h .= '<dt>Envois e-mail réussis</dt><dd>' . esc_html( (string) (int) ( $st['sent'] ?? 0 ) ) . '</dd>';
			$h .= '<dt>Envois e-mail en échec</dt><dd>' . Ui::badge( (string) $failed, $failed > 0 ? 'warning' : 'success' ) . '</dd>';
			if ( ! empty( $st['next_retry_at'] ) ) {
				$h .= '<dt>Prochaine tentative</dt><dd>' . esc_html( mysql2date( 'd/m/Y H:i', (string) $st['next_retry_at'] ) ) . '</dd>';
			}
		} else {
			$h .= '<dt>Service e-mail</dt><dd>' . Ui::badge( 'Statistiques indisponibles', 'neutral' ) . '</dd>';
		}
		return $h . '</dl>';
	}

	/** @param array<int,array<string,mixed>> $modules */
	private function global_status( string $core, array $modules ): string {
		$statuses = array( $core );
		foreach ( $modules as $m ) {
			$statuses[] = (string) $m['status'];
		}
		if ( in_array( Health::ERROR, $statuses, true ) ) {
			return Health::ERROR;
		}
		if ( in_array( Health::DEGRADED, $statuses, true ) ) {
			return Health::DEGRADED;
		}
		return Health::OK;
	}

	private function emoji( string $status ): string {
		switch ( $status ) {
			case Health::OK:       return '✅';
			case Health::DEGRADED: return '⚠️';
			case Health::ERROR:    return '⛔';
		}
		return '•';
	}
}
