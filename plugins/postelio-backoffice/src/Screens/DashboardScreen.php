<?php
/**
 * Tableau de bord : synthèse utile en un écran. Bande d'indicateurs compacts, « À traiter » (actions
 * RÉELLES uniquement, jamais inventées), « Activité récente » construite à partir des façades de
 * lecture existantes (une requête paginée par source, jamais de N+1), raccourcis utiles, santé
 * discrète. Une valeur indisponible n'est pas affichée. Rendu 100 % via Ui.
 *
 * @package Postelio\Backoffice\Screens
 */

namespace Postelio\Backoffice\Screens;

use Postelio\Backoffice\Menu;
use Postelio\Backoffice\Support\Data;
use Postelio\Backoffice\Support\Fmt;
use Postelio\Backoffice\Ui\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DashboardScreen extends Screen {

	private const APPS       = '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory';
	private const JOBS       = '\\Postelio\\Jobs\\Api\\JobAdminDirectory';
	private const COMPANIES  = '\\Postelio\\Companies\\Api\\CompanyAdminDirectory';
	private const INTERVIEWS = '\\Postelio\\Interviews\\Api\\InterviewAdminDirectory';

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

		// --- Indicateurs : seulement ceux réellement disponibles ----------------
		$kpis = array();
		if ( $is_admin ) {
			$kpis[] = Ui::kpi( 'Candidats', $users['candidates'], '', false, $this->url( 'postelio-users', array( 'tab' => 'candidates' ) ) );
			$kpis[] = Ui::kpi( 'Recruteurs', $users['recruiters'], '', false, $this->url( 'postelio-users', array( 'tab' => 'recruiters' ) ) );
		}
		if ( $cc ) {
			$kpis[] = Ui::kpi( 'Entreprises', (int) ( $cc['total'] ?? 0 ), (int) ( $cc['verified'] ?? 0 ) . ' vérifiées', false, $this->url( 'postelio-companies' ) );
		}
		if ( $jc ) {
			$kpis[] = Ui::kpi( 'Offres actives', (int) ( $jc['published'] ?? 0 ), (int) ( $jc['expiring'] ?? 0 ) > 0 ? (int) $jc['expiring'] . ' expirent bientôt' : '', false, $this->url( 'postelio-jobs', array( 'tab' => 'published' ) ) );
		}
		if ( $apps ) {
			$kpis[] = Ui::kpi( 'Candidatures', (int) ( $apps['total'] ?? 0 ), (int) ( $apps['new'] ?? 0 ) > 0 ? (int) $apps['new'] . ' nouvelles' : '', false, $this->url( 'postelio-applications' ) );
		}
		if ( null !== $mod ) {
			$kpis[] = Ui::kpi( 'À modérer', $mod, null !== $modc && $modc > 0 ? (int) $modc . ' critiques' : '', $mod > 0, $this->url( 'postelio-moderation' ) );
		}
		if ( $kpis ) {
			$out .= Ui::kpis_open( count( $kpis ) ) . implode( '', $kpis ) . Ui::kpis_close();
		}

		// --- Colonne principale : À traiter + Activité récente -------------------
		$out .= '<div class="bo-grid bo-grid--main"><div class="bo-col">';

		$todos = $is_admin ? $this->todos( $cc, $jc, $mod ) : array();
		$out  .= Ui::card_open( 'À traiter', $is_admin ? 'Ce qui attend une décision ou une vérification.' : 'Vue détaillée réservée aux administrateurs.', '', '', 'Priorités' );
		if ( empty( $todos ) ) {
			$out .= Ui::empty_state( 'Rien à traiter', $is_admin ? 'Aucune décision ni vérification n\'est en attente.' : 'Les files de traitement sont réservées aux administrateurs.', '', 'check' );
		} else {
			$out .= Ui::rows_open();
			foreach ( $todos as $t ) {
				$out .= Ui::row( esc_html( (string) $t['title'] ), (string) $t['desc'], '', Ui::button( (string) $t['label'], (string) $t['url'], '', true ), (string) $t['variant'], Ui::count_lead( (int) $t['n'] ) );
			}
			$out .= Ui::rows_close();
		}
		$out .= Ui::card_close();

		if ( $is_admin ) {
			$activity = $this->activity();
			$out     .= Ui::card_open( 'Activité récente', 'Derniers mouvements sur la plateforme.', '', '', 'Suivi' );
			$out     .= empty( $activity ) ? Ui::empty_state( 'Aucune activité récente', 'Les candidatures, offres et entretiens apparaîtront ici dès qu\'ils existeront.', '', 'inbox' ) : Ui::activity( $activity );
			$out     .= Ui::card_close();
		}
		$out .= '</div>';

		// --- Colonne latérale : raccourcis utiles + santé ------------------------
		$out .= '<div class="bo-col">';
		$out .= Ui::card_open( 'Raccourcis', '', '', 'bo-card--aside' ) . Ui::tiles( $this->shortcuts( $is_admin ) ) . Ui::card_close();
		$out .= $this->health_card();
		$out .= '</div></div>';
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
	 * Activité récente : 5 événements maximum, issus des façades existantes (une requête par source,
	 * page 1, 3 éléments), triés par date décroissante. Aucune donnée inventée.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function activity(): array {
		$events = array();

		if ( Data::has( self::APPS ) ) {
			$res = Data::facade( self::APPS, 'list', array( array(), 1, 3 ), array() );
			foreach ( (array) ( $res['items'] ?? array() ) as $a ) {
				$a        = (array) $a;
				$events[] = array( 'ts' => (string) ( $a['created_at'] ?? '' ), 'icon' => 'file', 'text' => 'Candidature de ' . (string) ( $a['candidate'] ?? '' ) . ' · ' . (string) ( $a['job_title'] ?? '' ), 'sub' => trim( (string) ( $a['company'] ?? '' ) ), 'url' => $this->url( 'postelio-applications', array( 'view' => (string) $a['uuid'] ) ) );
			}
		}
		if ( Data::has( self::JOBS ) ) {
			$res = Data::facade( self::JOBS, 'list', array( array( 'status' => 'published' ), 1, 3 ), array() );
			foreach ( (array) ( $res['items'] ?? array() ) as $j ) {
				$j = (array) $j;
				if ( '' === (string) ( $j['date_publication'] ?? '' ) ) {
					continue;
				}
				$events[] = array( 'ts' => (string) $j['date_publication'], 'icon' => 'brief', 'text' => 'Offre publiée · ' . (string) ( $j['title'] ?? '' ), 'sub' => trim( (string) ( $j['company']['nom'] ?? '' ) . ( '' !== (string) ( $j['ville'] ?? '' ) ? ' · ' . (string) $j['ville'] : '' ), ' ·' ), 'url' => $this->url( 'postelio-jobs', array( 'view' => (string) $j['uuid'] ) ) );
			}
		}
		if ( Data::has( self::INTERVIEWS ) ) {
			$res = Data::facade( self::INTERVIEWS, 'list', array( array( 'status' => 'confirmed' ), 1, 3 ), array() );
			foreach ( (array) ( $res['items'] ?? array() ) as $iv ) {
				$iv = (array) $iv;
				if ( strtotime( (string) ( $iv['scheduled_at'] ?? '' ) . ' UTC' ) < time() ) {
					continue;
				}
				$events[] = array( 'ts' => (string) $iv['scheduled_at'], 'icon' => 'cal', 'text' => 'Entretien confirmé · ' . (string) ( $iv['candidate'] ?? '' ), 'sub' => trim( (string) ( $iv['job_title'] ?? '' ) . ' · ' . (string) ( $iv['company'] ?? '' ), ' ·' ), 'url' => $this->url( 'postelio-interviews', array( 'view' => (string) $iv['uuid'] ) ) );
			}
		}
		if ( Data::has( self::COMPANIES ) && current_user_can( 'pst_verify_company' ) ) {
			foreach ( array( 'pending', 'manual_review' ) as $st ) {
				$res = Data::facade( self::COMPANIES, 'list', array( array( 'status' => $st ), 1, 2 ), array() );
				foreach ( (array) ( $res['items'] ?? array() ) as $c ) {
					$c        = (array) $c;
					$events[] = array( 'ts' => '', 'icon' => 'build', 'text' => 'Entreprise à vérifier · ' . (string) ( $c['nom'] ?? '' ), 'sub' => trim( (string) ( $c['ville'] ?? '' ) ), 'url' => $this->url( 'postelio-companies', array( 'view' => (string) $c['uuid'] ) ) );
				}
			}
		}

		usort( $events, static fn( $a, $b ) => strcmp( (string) $b['ts'], (string) $a['ts'] ) );
		$events = array_slice( $events, 0, 5 );
		foreach ( $events as &$e ) {
			$e['time'] = '' !== $e['ts'] ? Fmt::relative( $e['ts'] ) : 'en attente';
			unset( $e['ts'] );
		}
		return $events;
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
			$items[] = array( 'n' => $nf, 'title' => $nf > 1 ? 'e-mails non envoyés' : 'e-mail non envoyé', 'desc' => 'Vérifier le service e-mail.', 'label' => 'Voir le service e-mail', 'url' => $this->url( 'postelio-notifications' ), 'variant' => 'warning' );
		}
		$expiring = $jc ? (int) ( $jc['expiring'] ?? 0 ) : 0;
		if ( $expiring > 0 && current_user_can( 'pst_manage_all_jobs' ) ) {
			$items[] = array( 'n' => $expiring, 'title' => $expiring > 1 ? 'offres expirent bientôt' : 'offre expire bientôt', 'desc' => 'Elles seront prochainement retirées de la diffusion.', 'label' => 'Voir les offres', 'url' => $this->url( 'postelio-jobs', array( 'tab' => 'expiring' ) ), 'variant' => 'info' );
		}
		return $items;
	}

	/** Raccourcis réellement utiles (le site et la santé ont déjà leur place ailleurs). @return array<int,array<string,string>> */
	private function shortcuts( bool $is_admin ): array {
		$tiles = array();
		if ( $is_admin ) {
			$tiles[] = array( 'title' => 'Offres', 'sub' => 'Diffusion et cycle de vie', 'url' => $this->url( 'postelio-jobs' ) );
			$tiles[] = array( 'title' => 'Candidatures', 'sub' => 'Suivi du parcours', 'url' => $this->url( 'postelio-applications' ) );
			$tiles[] = array( 'title' => 'Entreprises', 'sub' => 'Vérification et fiches', 'url' => $this->url( 'postelio-companies' ) );
		}
		$tiles[] = array( 'title' => 'Modération', 'sub' => 'Signalements et décisions', 'url' => $this->url( 'postelio-moderation' ) );
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
