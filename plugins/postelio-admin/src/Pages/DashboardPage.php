<?php
/**
 * Tableau de bord : vue d'ensemble de la plateforme. KPI dérivés des contrats/endpoints réels
 * (jamais inventés ; « — » si indisponible proprement). Cartes gérées selon la capability.
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

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

		$core = Health::snapshot()['core'];
		$health_badge = Ui::badge( 'Santé : ' . Health::label( (string) $core['status'] ), Health::badge_variant( (string) $core['status'] ), true );

		$out  = Ui::header( 'Postelio', 'Vue d\'ensemble de la plateforme', $health_badge );

		// --- KPI principaux -------------------------------------------------
		$users = Metrics::user_counts();
		$mod_open = Metrics::moderation_open();
		$mod_crit = Metrics::moderation_critical();

		$grid  = '<div class="pst-admin-grid">';
		$grid .= self::stat_or_dash( 'Candidats', $is_admin ? $users['candidates'] : null, $is_admin ? '' : 'réservé admin', false );
		$grid .= self::stat_or_dash( 'Recruteurs', $is_admin ? $users['recruiters'] : null, '', false );

		$cc = $is_admin ? Metrics::company_counts() : null;
		$grid .= self::stat_or_dash( 'Entreprises', $cc ? $cc['total'] : null, $cc ? ( (int) ( $cc['verified'] ?? 0 ) . ' vérifiées' ) : '' );
		$jc = $is_admin ? Metrics::job_counts() : null;
		$grid .= self::stat_or_dash( 'Offres publiées', $jc ? (int) ( $jc['published'] ?? 0 ) : null, $jc ? ( (int) ( $jc['expiring'] ?? 0 ) . ' expirent bientôt' ) : '' );
		$grid .= self::stat_or_dash( 'Modération à traiter', $mod_open, null !== $mod_crit ? ( (int) $mod_crit . ' critiques' ) : '', true );
		$grid .= self::stat_or_dash( 'Savoir-faire publiés', Metrics::skills_published() );
		$grid .= '</div>';
		$out  .= $grid;

		// --- Deux colonnes : modération + services --------------------------
		$out .= '<div class="pst-admin-grid pst-admin-grid--2">';

		// Carte modération
		$out .= Ui::card_open( 'Modération' );
		if ( null === $mod_open ) {
			$out .= Ui::empty_state( 'Module indisponible', 'Le module Modération n\'est pas actif.', '🛡️' );
		} else {
			$out .= '<div class="pst-admin-stat" style="margin-bottom:12px"><span class="pst-admin-stat__value">' . (int) $mod_open . '</span><span class="pst-admin-stat__label">dossiers ouverts</span></div>';
			$crit = (int) ( $mod_crit ?? 0 );
			$out .= '<p>' . Ui::badge( $crit . ' critiques', $crit > 0 ? 'critical' : 'neutral' ) . ' ';
			$out .= '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-moderation' ) ) . '">Ouvrir la file</a></p>';
		}
		$out .= Ui::card_close();

		// Carte état des services
		$out .= Ui::card_open( 'État des services' );
		$snap = Health::snapshot();
		$out .= '<div class="pst-health-row"><span class="pst-health-row__name">Core</span>' . Ui::badge( Health::label( (string) $snap['core']['status'] ), Health::badge_variant( (string) $snap['core']['status'] ), true ) . '</div>';
		foreach ( $snap['modules'] as $m ) {
			// Masque la facturation aux non-admins facturation.
			if ( 'billing' === $m['module'] && ! current_user_can( 'pst_manage_billing' ) ) {
				continue;
			}
			$out .= '<div class="pst-health-row"><span class="pst-health-row__name">' . esc_html( (string) $m['label'] ) . '</span>'
				. Ui::badge( Health::label( (string) $m['status'] ), Health::badge_variant( (string) $m['status'] ), true ) . '</div>';
		}
		$out .= '<p style="margin-top:12px"><a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-health' ) ) . '">Détails santé</a></p>';
		$out .= Ui::card_close();

		$out .= '</div>';

		// --- Facturation (admin billing) ------------------------------------
		if ( current_user_can( 'pst_manage_billing' ) ) {
			$bh = Metrics::billing_health();
			$out .= Ui::card_open( 'Facturation' );
			if ( null === $bh ) {
				$out .= Ui::empty_state( 'Module indisponible', 'Le module Facturation n\'est pas actif.', '💳' );
			} else {
				$out .= '<div class="pst-admin-grid">';
				$out .= self::stat_or_dash( 'Commandes honorées', $bh['paid'] );
				$out .= self::stat_or_dash( 'Fulfillment en échec', $bh['failed'], '', true );
				$out .= Ui::stat( 'Stripe', $bh['configured'] ? 'Configuré' : 'Non configuré', 'mode ' . (string) $bh['mode'], false, ! $bh['configured'] );
				$out .= '</div>';
			}
			$out .= Ui::card_close();
		}

		return $out;
	}

	/** Rend une carte stat, ou « — » grisé si la valeur est indisponible (null). */
	private static function stat_or_dash( string $label, $value, string $sub = '', bool $accent = false ): string {
		if ( null === $value ) {
			return Ui::stat( $label, '—', '' !== $sub ? $sub : 'indisponible', $accent, true );
		}
		return Ui::stat( $label, (string) $value, $sub, $accent, false );
	}
}
