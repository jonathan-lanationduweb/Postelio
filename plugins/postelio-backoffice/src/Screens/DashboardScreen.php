<?php
/**
 * Tableau de bord : titre simple, santé discrète, indicateurs utiles, « À traiter » (actions
 * RÉELLES uniquement, jamais inventées), raccourcis. Données via Support\Data (contrats réels) ;
 * une valeur indisponible s'affiche « — ». Rendu 100 % via Ui (aucun style inline).
 *
 * @package Postelio\Backoffice\Screens
 */

namespace Postelio\Backoffice\Screens;

use Postelio\Backoffice\Menu;
use Postelio\Backoffice\Support\Data;
use Postelio\Backoffice\Ui\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DashboardScreen extends Screen {

	protected function capability(): string {
		return Menu::CAP_VIEW;
	}

	protected function body(): string {
		$is_admin = current_user_can( Menu::CAP_ADMIN );

		$users = Data::user_counts();
		$cc    = $is_admin ? Data::company_counts() : null;
		$jc    = $is_admin ? Data::job_counts() : null;
		$apps  = $is_admin ? Data::application_counts() : null;
		$mod   = Data::moderation_open();
		$modc  = Data::moderation_critical();

		$actions = $this->health_badge();
		if ( current_user_can( Menu::CAP_SITE ) ) {
			$actions .= Ui::button( 'Modifier le site', $this->url( 'postelio-site-pages' ), 'primary' );
		}
		$actions .= Ui::button( 'Voir le site', $this->front_url(), '', false, true );

		$out = Ui::page_header( 'Tableau de bord', 'L\'essentiel de Postelio, en un coup d\'œil.', $actions );

		// --- Indicateurs -----------------------------------------------------
		$out .= '<div class="bo-stats">';
		$out .= Ui::stat( 'Candidats', $is_admin ? $users['candidates'] : null );
		$out .= Ui::stat( 'Recruteurs', $is_admin ? $users['recruiters'] : null );
		$out .= Ui::stat( 'Entreprises', $cc ? (int) ( $cc['total'] ?? 0 ) : null, $cc ? (int) ( $cc['verified'] ?? 0 ) . ' vérifiées' : '' );
		$out .= Ui::stat( 'Offres actives', $jc ? (int) ( $jc['published'] ?? 0 ) : null, $jc ? (int) ( $jc['expiring'] ?? 0 ) . ' expirent bientôt' : '' );
		$out .= Ui::stat( 'Candidatures', $apps ? (int) ( $apps['total'] ?? 0 ) : null, $apps ? (int) ( $apps['new'] ?? 0 ) . ' nouvelles' : '' );
		$out .= Ui::stat( 'À modérer', $mod, null !== $modc ? (int) $modc . ' critiques' : '', null !== $mod && $mod > 0 );
		$out .= '</div>';

		// --- À traiter + raccourcis ------------------------------------------
		$out .= '<div class="bo-grid bo-grid--main">';

		$todos = $is_admin ? $this->todos( $cc, $jc, $mod ) : array();
		$out  .= Ui::card_open( 'À traiter', $is_admin ? 'Ce qui attend une décision ou une vérification.' : 'Vue détaillée réservée aux administrateurs.' );
		if ( empty( $todos ) ) {
			$out .= Ui::alert( $is_admin ? 'Rien ne requiert votre attention pour le moment.' : 'Vue détaillée réservée aux administrateurs.', 'success' );
		} else {
			$out .= Ui::rows_open();
			foreach ( $todos as $t ) {
				$out .= Ui::row(
					'<strong class="bo-row__count">' . (int) $t['n'] . '</strong> ' . esc_html( (string) $t['title'] ),
					(string) $t['desc'],
					'',
					Ui::button( (string) $t['label'], (string) $t['url'], 'primary', true ),
					(string) $t['variant']
				);
			}
			$out .= Ui::rows_close();
		}
		$out .= Ui::card_close();

		$out .= Ui::card_open( 'Raccourcis' ) . $this->shortcuts( $is_admin ) . Ui::card_close();
		$out .= '</div>';

		// --- Santé (compacte) -------------------------------------------------
		$out .= $this->health_line();
		return $out;
	}

	/** URL du front public (racine de l'origine, cf. postelio-site). */
	private function front_url(): string {
		if ( class_exists( '\\Postelio\\Site\\Api\\SiteConfigDirectory' ) && method_exists( '\\Postelio\\Site\\Api\\SiteConfigDirectory', 'front_origin' ) ) {
			return \Postelio\Site\Api\SiteConfigDirectory::front_origin() . '/';
		}
		return home_url( '/' );
	}

	/**
	 * Items « à traiter » réels (affichés seulement si compteur > 0 et capability).
	 *
	 * @param array<string,int>|null $cc
	 * @param array<string,int>|null $jc
	 * @return array<int,array<string,mixed>>
	 */
	private function todos( ?array $cc, ?array $jc, ?int $mod ): array {
		$items = array();

		$to_verify = $cc ? ( (int) ( $cc['pending'] ?? 0 ) + (int) ( $cc['manual_review'] ?? 0 ) ) : 0;
		if ( $to_verify > 0 && current_user_can( 'pst_verify_company' ) ) {
			$items[] = array( 'n' => $to_verify, 'title' => 'entreprise(s) à vérifier', 'desc' => 'Vérification administrative en attente.', 'label' => 'Examiner', 'url' => $this->url( 'postelio-companies', array( 'tab' => 'pending' ) ), 'variant' => 'accent' );
		}
		if ( null !== $mod && $mod > 0 ) {
			$items[] = array( 'n' => $mod, 'title' => 'dossier(s) de modération ouverts', 'desc' => 'Signalements et contenus en attente de décision.', 'label' => 'Ouvrir la file', 'url' => $this->url( 'postelio-moderation' ), 'variant' => 'accent' );
		}
		if ( current_user_can( Menu::CAP_BILLING ) ) {
			$bh     = Data::billing_health();
			$failed = is_array( $bh ) ? (int) ( $bh['failed'] ?? 0 ) : 0;
			if ( $failed > 0 ) {
				$items[] = array( 'n' => $failed, 'title' => 'paiement(s) en échec de traitement', 'desc' => 'Fulfillment à relancer ou à examiner.', 'label' => 'Voir la facturation', 'url' => $this->url( 'postelio-billing', array( 'tab' => 'fulfillment_failed' ) ), 'variant' => 'warning' );
			}
		}
		$st = Data::delivery_stats();
		$nf = is_array( $st ) ? (int) ( $st['failed'] ?? 0 ) : 0;
		if ( $nf > 0 ) {
			$items[] = array( 'n' => $nf, 'title' => 'e-mail(s) n\'ont pas pu être envoyés', 'desc' => 'Vérifier le service e-mail (transport).', 'label' => 'Voir les notifications', 'url' => $this->url( 'postelio-notifications' ), 'variant' => 'warning' );
		}
		$expiring = $jc ? (int) ( $jc['expiring'] ?? 0 ) : 0;
		if ( $expiring > 0 && current_user_can( 'pst_manage_all_jobs' ) ) {
			$items[] = array( 'n' => $expiring, 'title' => 'offre(s) expirent bientôt', 'desc' => 'Elles seront prochainement retirées de la diffusion.', 'label' => 'Voir les offres', 'url' => $this->url( 'postelio-jobs', array( 'tab' => 'expiring' ) ), 'variant' => 'info' );
		}
		return $items;
	}

	private function shortcuts( bool $is_admin ): string {
		$links = array();
		if ( $is_admin ) {
			$links[] = array( 'Gérer les offres', 'postelio-jobs' );
			$links[] = array( 'Voir les candidatures', 'postelio-applications' );
			$links[] = array( 'Vérifier les entreprises', 'postelio-companies' );
			$links[] = array( 'Utilisateurs', 'postelio-users' );
		}
		if ( current_user_can( Menu::CAP_SITE ) ) {
			$links[] = array( 'Modifier le site', 'postelio-site-pages' );
		}
		$links[] = array( 'Modération', 'postelio-moderation' );
		if ( $is_admin ) {
			$links[] = array( 'Réglages', 'postelio-settings' );
		}
		$h = '<div class="bo-actions bo-actions--wrap">';
		foreach ( $links as $l ) {
			$h .= Ui::button( $l[0], $this->url( $l[1] ), '', true );
		}
		return $h . '</div>';
	}

	private function health_badge(): string {
		$core = Data::health()['core'];
		$st   = (string) ( $core['status'] ?? 'absent' );
		return Ui::badge( 'Santé : ' . Data::health_label( $st ), Data::health_variant( $st ), true );
	}

	/** Ligne compacte : statut global + comptes + lien vers le détail (écran Santé). */
	private function health_line(): string {
		$snap   = Data::health();
		$global = Data::health_global();
		$ok     = 0;
		$todo   = 0;
		foreach ( (array) $snap['modules'] as $m ) {
			$s = (string) ( $m['status'] ?? '' );
			if ( 'ok' === $s ) {
				$ok++;
			} elseif ( in_array( $s, array( 'unconfigured', 'degraded', 'error' ), true ) ) {
				$todo++;
			}
		}
		$text = Ui::badge( Data::health_label( $global ), Data::health_variant( $global ), true )
			. ' <span class="bo-muted">' . (int) $ok . ' services OK' . ( $todo > 0 ? ' · ' . (int) $todo . ' à configurer' : '' ) . '</span>';
		return '<div class="bo-healthline"><span class="bo-healthline__label">Santé du système</span><span class="bo-healthline__text">' . $text . '</span>'
			. ( current_user_can( Menu::CAP_ADMIN ) ? Ui::button( 'Détails', $this->url( 'postelio-health' ), 'ghost', true ) : '' ) . '</div>';
	}
}
