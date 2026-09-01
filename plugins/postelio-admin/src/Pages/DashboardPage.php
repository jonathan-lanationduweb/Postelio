<?php
/**
 * Tableau de bord : cockpit quotidien de Postelio. En-tête léger, KPI métier compacts, zone
 * « À traiter » (actions réelles uniquement — jamais inventées), et santé technique reléguée à une
 * ligne compacte. Toutes les valeurs viennent des contrats/endpoints réels ; « — » si indisponible.
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

final class DashboardPage extends Page {

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_VIEW;
	}

	protected function body(): string {
		$is_admin = current_user_can( \Postelio\Admin\Menu::CAP_ADMIN );

		$out  = Ui::toolbar( 'Vue d\'ensemble', 'Pilotage de la plateforme Postelio.', $this->health_badge() );

		// --- KPI métier -----------------------------------------------------
		$users = Metrics::user_counts();
		$cc    = $is_admin ? Metrics::company_counts() : null;
		$jc    = $is_admin ? Metrics::job_counts() : null;
		$apps  = ( $is_admin && Contracts::has( '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory' ) ) ? \Postelio\Applications\Api\ApplicationAdminDirectory::counts() : null;
		$mod   = Metrics::moderation_open();
		$modc  = Metrics::moderation_critical();

		$grid  = '<div class="pst-admin-grid pst-admin-grid--6">';
		$grid .= self::kpi( 'Candidats', $is_admin ? $users['candidates'] : null, '', '👤' );
		$grid .= self::kpi( 'Recruteurs', $is_admin ? $users['recruiters'] : null, '', '🧑‍💼' );
		$grid .= self::kpi( 'Entreprises', $cc ? (int) ( $cc['total'] ?? 0 ) : null, $cc ? ( (int) ( $cc['verified'] ?? 0 ) . ' vérifiées' ) : '', '🏢' );
		$grid .= self::kpi( 'Offres actives', $jc ? (int) ( $jc['published'] ?? 0 ) : null, $jc ? ( (int) ( $jc['expiring'] ?? 0 ) . ' expirent' ) : '', '📄' );
		$grid .= self::kpi( 'Candidatures', $apps ? (int) ( $apps['total'] ?? 0 ) : null, $apps ? ( (int) ( $apps['new'] ?? 0 ) . ' nouvelles' ) : '', '📨' );
		$grid .= self::kpi( 'À modérer', $mod, null !== $modc ? ( (int) $modc . ' critiques' ) : '', '🛡️', true );
		$grid .= '</div>';
		$out  .= $grid;

		// --- À traiter ------------------------------------------------------
		$out .= '<h2 class="pst-admin-section-title">À traiter</h2>';
		$todos = $is_admin ? $this->todos( $cc, $jc, $mod ) : array();
		if ( empty( $todos ) ) {
			$out .= '<div class="pst-todo__ok"><b>✓</b><span>' . esc_html( $is_admin ? 'Rien ne requiert votre attention pour le moment.' : 'Vue détaillée réservée aux administrateurs.' ) . '</span></div>';
		} else {
			$out .= '<div class="pst-todo">';
			foreach ( $todos as $t ) {
				$out .= $this->todo_card( $t );
			}
			$out .= '</div>';
		}

		// --- Raccourcis -----------------------------------------------------
		$out .= $this->shortcuts( $is_admin );

		// --- Santé (compacte) ----------------------------------------------
		$out .= '<h2 class="pst-admin-section-title">Santé du système</h2>';
		$out .= $this->health_line();

		return $out;
	}

	/** Carte KPI compacte (valeur + contexte réel, « — » si indisponible). */
	private static function kpi( string $label, ?int $value, string $sub, string $icon, bool $accent = false ): string {
		if ( null === $value ) {
			return Ui::stat( $label, '—', 'réservé admin', $accent, true, $icon );
		}
		return Ui::stat( $label, (string) $value, $sub, $accent, false, $icon );
	}

	/**
	 * Items « à traiter » réels (chacun affiché seulement si son compteur > 0).
	 *
	 * @param array<string,int>|null $cc
	 * @param array<string,int>|null $jc
	 * @return array<int,array<string,mixed>>
	 */
	private function todos( ?array $cc, ?array $jc, ?int $mod ): array {
		$items = array();

		$to_verify = $cc ? ( (int) ( $cc['pending'] ?? 0 ) + (int) ( $cc['manual_review'] ?? 0 ) ) : 0;
		if ( $to_verify > 0 && current_user_can( 'pst_verify_company' ) ) {
			$items[] = array( 'ic' => '🏢', 'n' => $to_verify, 'title' => 'entreprise(s) à vérifier', 'desc' => 'Vérification administrative en attente.', 'label' => 'Examiner', 'url' => $this->url( 'postelio-companies', array( 'tab' => 'pending' ) ), 'variant' => '' );
		}
		if ( null !== $mod && $mod > 0 && current_user_can( \Postelio\Admin\Menu::CAP_VIEW ) ) {
			$items[] = array( 'ic' => '🛡️', 'n' => $mod, 'title' => 'dossier(s) de modération ouverts', 'desc' => 'Signalements et contenus en attente de décision.', 'label' => 'Ouvrir la file', 'url' => $this->url( 'postelio-moderation' ), 'variant' => '' );
		}
		if ( current_user_can( 'pst_manage_billing' ) ) {
			$bh = Metrics::billing_health();
			$failed = is_array( $bh ) ? (int) ( $bh['failed'] ?? 0 ) : 0;
			if ( $failed > 0 ) {
				$items[] = array( 'ic' => '💳', 'n' => $failed, 'title' => 'paiement(s) en échec de traitement', 'desc' => 'Fulfillment à relancer ou à examiner.', 'label' => 'Voir la facturation', 'url' => $this->url( 'postelio-billing', array( 'tab' => 'fulfillment_failed' ) ), 'variant' => 'warn' );
			}
		}
		if ( Contracts::has( '\\Postelio\\Notifications\\Api\\NotificationDirectory' ) && method_exists( '\\Postelio\\Notifications\\Api\\NotificationDirectory', 'delivery_stats' ) ) {
			$st = (array) call_user_func( array( '\\Postelio\\Notifications\\Api\\NotificationDirectory', 'delivery_stats' ) );
			$nf = (int) ( $st['failed'] ?? 0 );
			if ( $nf > 0 ) {
				$items[] = array( 'ic' => '🔔', 'n' => $nf, 'title' => 'notification(s) en échec d\'envoi', 'desc' => 'Des e-mails n\'ont pas pu être délivrés.', 'label' => 'Voir les notifications', 'url' => $this->url( 'postelio-notifications' ), 'variant' => 'warn' );
			}
		}
		$expiring = $jc ? (int) ( $jc['expiring'] ?? 0 ) : 0;
		if ( $expiring > 0 && current_user_can( 'pst_manage_all_jobs' ) ) {
			$items[] = array( 'ic' => '⏳', 'n' => $expiring, 'title' => 'offre(s) expirent bientôt', 'desc' => 'Elles seront prochainement retirées de la diffusion.', 'label' => 'Voir les offres', 'url' => $this->url( 'postelio-jobs', array( 'tab' => 'expiring' ) ), 'variant' => 'info' );
		}
		return $items;
	}

	/** @param array<string,mixed> $t */
	private function todo_card( array $t ): string {
		$cls = 'pst-todo__item' . ( '' !== (string) $t['variant'] ? ' pst-todo__item--' . preg_replace( '/[^a-z]/', '', (string) $t['variant'] ) : '' );
		$h   = '<div class="' . esc_attr( $cls ) . '">';
		$h  .= '<span class="pst-todo__ic">' . esc_html( (string) $t['ic'] ) . '</span>';
		$h  .= '<div class="pst-todo__body">';
		$h  .= '<div class="pst-todo__title"><b>' . (int) $t['n'] . '</b> ' . esc_html( (string) $t['title'] ) . '</div>';
		$h  .= '<div class="pst-todo__desc">' . esc_html( (string) $t['desc'] ) . '</div>';
		$h  .= '<a class="pst-btn pst-btn--sm pst-btn--primary" href="' . esc_url( (string) $t['url'] ) . '">' . esc_html( (string) $t['label'] ) . ' →</a>';
		$h  .= '</div></div>';
		return $h;
	}

	private function shortcuts( bool $is_admin ): string {
		$links = array();
		if ( $is_admin ) {
			$links[] = array( 'Entreprises', 'postelio-companies' );
			$links[] = array( 'Offres', 'postelio-jobs' );
			$links[] = array( 'Candidatures', 'postelio-applications' );
		}
		if ( current_user_can( \Postelio\Admin\Menu::CAP_VIEW ) ) {
			$links[] = array( 'Modération', 'postelio-moderation' );
		}
		if ( current_user_can( 'pst_manage_site' ) ) {
			$links[] = array( 'Mon site', 'postelio-site' );
		}
		if ( empty( $links ) ) {
			return '';
		}
		$h = '<div class="pst-admin-actions" style="margin-top:18px">';
		foreach ( $links as $l ) {
			$h .= '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( $l[1] ) ) . '">' . esc_html( $l[0] ) . '</a> ';
		}
		return $h . '</div>';
	}

	/** Pastille d'état global compacte pour la toolbar. */
	private function health_badge(): string {
		$core = Health::snapshot()['core'];
		$st   = (string) $core['status'];
		return Ui::badge( 'Santé : ' . Health::label( $st ), Health::badge_variant( $st ), true );
	}

	/** Ligne de santé compacte : statut global + comptes + lien vers le détail. */
	private function health_line(): string {
		$snap    = Health::snapshot();
		$global  = $this->global_status( (string) $snap['core']['status'], (array) $snap['modules'] );
		$ok      = 0;
		$unconf  = 0;
		foreach ( (array) $snap['modules'] as $m ) {
			if ( Health::OK === $m['status'] ) {
				$ok++;
			} elseif ( in_array( $m['status'], array( Health::UNCONFIGURED, Health::DEGRADED, Health::ERROR ), true ) ) {
				$unconf++;
			}
		}
		$colors = array( Health::OK => '#2f6b5e', Health::DEGRADED => '#955700', Health::ERROR => '#a83232' );
		$dot    = $colors[ $global ] ?? '#8595a8';
		$txt    = '<b>' . esc_html( Health::label( $global ) ) . '</b> · ' . (int) $ok . ' services OK'
			. ( $unconf > 0 ? ' · ' . (int) $unconf . ' à configurer' : '' );
		return '<div class="pst-health-line"><span class="pst-health-line__dot" style="background:' . esc_attr( $dot ) . '"></span>'
			. '<span class="pst-health-line__txt">' . $txt . '</span>'
			. '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-health' ) ) . '">Voir les détails</a></div>';
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
}
