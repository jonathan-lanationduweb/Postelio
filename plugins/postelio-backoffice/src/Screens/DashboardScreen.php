<?php
/**
 * Tableau de bord : synthèse utile en un écran. Bande d'indicateurs compacts, « À traiter » (actions
 * RÉELLES uniquement, jamais inventées, compteur en tête de ligne), raccourcis en tuiles, santé
 * discrète. Données via Support\Data (contrats réels) ; une valeur indisponible s'affiche « — ».
 * Rendu 100 % via Ui (aucun style inline).
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

	protected function eyebrow(): string {
		return 'Postelio · Activité';
	}

	protected function body(): string {
		$is_admin = current_user_can( Menu::CAP_ADMIN );

		$users = Data::user_counts();
		$cc    = $is_admin ? Data::company_counts() : null;
		$jc    = $is_admin ? Data::job_counts() : null;
		$apps  = $is_admin ? Data::application_counts() : null;
		$mod   = Data::moderation_open();
		$modc  = Data::moderation_critical();

		$actions = '';
		if ( current_user_can( Menu::CAP_SITE ) ) {
			$actions .= Ui::button( 'Modifier le site', $this->url( 'postelio-site-pages' ), 'primary' );
		}
		$actions .= Ui::button( 'Voir le site', $this->front_url(), '', false, true );

		$out = $this->header( 'Tableau de bord', 'L\'essentiel de la plateforme, en un coup d\'œil.', $actions );

		// --- Indicateurs (bande compacte, cliquables) -------------------------
		$out .= Ui::kpis_open( 6 );
		$out .= Ui::kpi( 'Candidats', $is_admin ? $users['candidates'] : null, '', false, $is_admin ? $this->url( 'postelio-users', array( 'tab' => 'candidates' ) ) : '' );
		$out .= Ui::kpi( 'Recruteurs', $is_admin ? $users['recruiters'] : null, '', false, $is_admin ? $this->url( 'postelio-users', array( 'tab' => 'recruiters' ) ) : '' );
		$out .= Ui::kpi( 'Entreprises', $cc ? (int) ( $cc['total'] ?? 0 ) : null, $cc ? (int) ( $cc['verified'] ?? 0 ) . ' vérifiées' : '', false, $is_admin ? $this->url( 'postelio-companies' ) : '' );
		$out .= Ui::kpi( 'Offres actives', $jc ? (int) ( $jc['published'] ?? 0 ) : null, $jc ? (int) ( $jc['expiring'] ?? 0 ) . ' expirent bientôt' : '', false, $is_admin ? $this->url( 'postelio-jobs', array( 'tab' => 'published' ) ) : '' );
		$out .= Ui::kpi( 'Candidatures', $apps ? (int) ( $apps['total'] ?? 0 ) : null, $apps ? (int) ( $apps['new'] ?? 0 ) . ' nouvelles' : '', false, $is_admin ? $this->url( 'postelio-applications' ) : '' );
		$out .= Ui::kpi( 'À modérer', $mod, null !== $modc && $modc > 0 ? (int) $modc . ' critiques' : '', null !== $mod && $mod > 0, $this->url( 'postelio-moderation' ) );
		$out .= Ui::kpis_close();

		// --- À traiter + colonne latérale -------------------------------------
		$out .= '<div class="bo-grid bo-grid--main">';

		$todos = $is_admin ? $this->todos( $cc, $jc, $mod ) : array();
		$out  .= Ui::card_open( 'À traiter', $is_admin ? 'Ce qui attend une décision ou une vérification.' : 'Vue détaillée réservée aux administrateurs.', '', '', 'Priorités' );
		if ( empty( $todos ) ) {
			$out .= Ui::empty_state( 'Rien à traiter', $is_admin ? 'Aucune décision ni vérification n\'est en attente pour le moment.' : 'Les files de traitement sont réservées aux administrateurs.' );
		} else {
			$out .= Ui::rows_open();
			foreach ( $todos as $t ) {
				$out .= Ui::row(
					esc_html( (string) $t['title'] ),
					(string) $t['desc'],
					'',
					Ui::button( (string) $t['label'], (string) $t['url'], '', true ),
					(string) $t['variant'],
					Ui::count_lead( (int) $t['n'] )
				);
			}
			$out .= Ui::rows_close();
		}
		$out .= Ui::card_close();

		$out .= '<div class="bo-col">';
		$out .= Ui::card_open( 'Raccourcis', '', '', 'bo-card--aside' ) . Ui::tiles( $this->shortcuts( $is_admin ) ) . Ui::card_close();
		$out .= $this->health_card();
		$out .= '</div>';

		$out .= '</div>';
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
			$items[] = array( 'n' => $to_verify, 'title' => $to_verify > 1 ? 'entreprises à vérifier' : 'entreprise à vérifier', 'desc' => 'Vérification administrative en attente.', 'label' => 'Examiner', 'url' => $this->url( 'postelio-companies', array( 'tab' => 'pending' ) ), 'variant' => 'accent' );
		}
		if ( null !== $mod && $mod > 0 ) {
			$items[] = array( 'n' => $mod, 'title' => $mod > 1 ? 'dossiers de modération ouverts' : 'dossier de modération ouvert', 'desc' => 'Signalements et contenus en attente de décision.', 'label' => 'Ouvrir la file', 'url' => $this->url( 'postelio-moderation' ), 'variant' => 'accent' );
		}
		if ( current_user_can( Menu::CAP_BILLING ) ) {
			$bh     = Data::billing_health();
			$failed = is_array( $bh ) ? (int) ( $bh['failed'] ?? 0 ) : 0;
			if ( $failed > 0 ) {
				$items[] = array( 'n' => $failed, 'title' => $failed > 1 ? 'paiements en échec de traitement' : 'paiement en échec de traitement', 'desc' => 'Traitement à relancer ou à examiner.', 'label' => 'Voir la facturation', 'url' => $this->url( 'postelio-billing', array( 'tab' => 'fulfillment_failed' ) ), 'variant' => 'warning' );
			}
		}
		$st = Data::delivery_stats();
		$nf = is_array( $st ) ? (int) ( $st['failed'] ?? 0 ) : 0;
		if ( $nf > 0 ) {
			$items[] = array( 'n' => $nf, 'title' => $nf > 1 ? 'e-mails non envoyés' : 'e-mail non envoyé', 'desc' => 'Vérifier le service e-mail (transport).', 'label' => 'Voir le service e-mail', 'url' => $this->url( 'postelio-notifications' ), 'variant' => 'warning' );
		}
		$expiring = $jc ? (int) ( $jc['expiring'] ?? 0 ) : 0;
		if ( $expiring > 0 && current_user_can( 'pst_manage_all_jobs' ) ) {
			$items[] = array( 'n' => $expiring, 'title' => $expiring > 1 ? 'offres expirent bientôt' : 'offre expire bientôt', 'desc' => 'Elles seront prochainement retirées de la diffusion.', 'label' => 'Voir les offres', 'url' => $this->url( 'postelio-jobs', array( 'tab' => 'expiring' ) ), 'variant' => 'info' );
		}
		return $items;
	}

	/** @return array<int,array<string,string>> */
	private function shortcuts( bool $is_admin ): array {
		$tiles = array();
		if ( $is_admin ) {
			$tiles[] = array( 'title' => 'Offres', 'sub' => 'Cycle de vie et diffusion', 'url' => $this->url( 'postelio-jobs' ) );
			$tiles[] = array( 'title' => 'Candidatures', 'sub' => 'Suivi du parcours', 'url' => $this->url( 'postelio-applications' ) );
			$tiles[] = array( 'title' => 'Entreprises', 'sub' => 'Vérification et fiches', 'url' => $this->url( 'postelio-companies' ) );
			$tiles[] = array( 'title' => 'Utilisateurs', 'sub' => 'Candidats et recruteurs', 'url' => $this->url( 'postelio-users' ) );
		}
		if ( current_user_can( Menu::CAP_SITE ) ) {
			$tiles[] = array( 'title' => 'Mon site', 'sub' => 'Pages, navigation, apparence', 'url' => $this->url( 'postelio-site-pages' ) );
		}
		$tiles[] = array( 'title' => 'Modération', 'sub' => 'Signalements et décisions', 'url' => $this->url( 'postelio-moderation' ) );
		if ( $is_admin ) {
			$tiles[] = array( 'title' => 'Réglages', 'sub' => 'État réel de la plateforme', 'url' => $this->url( 'postelio-settings' ) );
		}
		return $tiles;
	}

	/** Carte compacte : statut global + comptes + lien vers le détail (écran Santé). */
	private function health_card(): string {
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
		$action = current_user_can( Menu::CAP_ADMIN ) ? Ui::button( 'Détails', $this->url( 'postelio-health' ), 'ghost', true ) : '';
		return Ui::card_open( 'Santé du système', '', $action, 'bo-card--aside' )
			. '<div class="bo-actions bo-actions--wrap">' . Ui::badge( Data::health_label( $global ), Data::health_variant( $global ), true )
			. Ui::text( (int) $ok . ' services OK' . ( $todo > 0 ? ' · ' . (int) $todo . ' à configurer' : '' ), false, true ) . '</div>'
			. Ui::card_close();
	}
}
