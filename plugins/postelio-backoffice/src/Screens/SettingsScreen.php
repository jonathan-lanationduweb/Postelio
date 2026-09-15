<?php
/**
 * Réglages : état RÉEL de la plateforme, navigation latérale (catégories à gauche, domaine à droite).
 * Règle absolue : aucun faux interrupteur — chaque ligne affichée correspond à une source de vérité
 * détectable (option WordPress, module enregistré, endpoint `/health`, constante serveur). Tout est
 * en LECTURE SEULE ; les clés, jetons et secrets restent dans l'environnement serveur et ne sont
 * jamais affichés.
 *
 * @package Postelio\Backoffice\Screens
 */

namespace Postelio\Backoffice\Screens;

use Postelio\Backoffice\Menu;
use Postelio\Backoffice\Support\Data;
use Postelio\Backoffice\Support\Fmt;
use Postelio\Backoffice\Support\Health;
use Postelio\Backoffice\Support\Rest;
use Postelio\Backoffice\Ui\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsScreen extends Screen {

	/** @var array<string,array{0:string,1:string,2:string}> clé => [libellé, groupe, description] */
	private const SECTIONS = array(
		'general'       => array( 'Général', 'Plateforme', 'Identité de l\'installation et santé globale.' ),
		'accounts'      => array( 'Comptes', 'Plateforme', 'Rôles, inscription et vérification des comptes.' ),
		'security'      => array( 'Sécurité', 'Plateforme', 'Indicateurs de sécurité constatés.' ),
		'jobs'          => array( 'Offres', 'Métier', 'Diffusion et renouvellement des offres.' ),
		'moderation'    => array( 'Modération', 'Métier', 'Analyse de contenu et files de traitement.' ),
		'billing'       => array( 'Facturation', 'Métier', 'Paiement en ligne et facture légale.' ),
		'notifications' => array( 'Notifications', 'Services', 'Transport e-mail et file d\'envoi.' ),
		'sources'       => array( 'Sources d\'offres', 'Services', 'Connecteurs partenaires.' ),
		'system'        => array( 'Système', 'Services', 'Santé et écrans techniques.' ),
	);

	protected function capability(): string {
		return Menu::CAP_ADMIN;
	}

	protected function eyebrow(): string {
		return 'Postelio · Configuration';
	}

	protected function body(): string {
		$tab = $this->current( 'tab', 'general' );
		if ( ! isset( self::SECTIONS[ $tab ] ) ) {
			$tab = 'general';
		}
		$out = $this->header( 'Réglages', 'État réel de la plateforme, en lecture seule. Aucun secret n\'est affiché.' );

		$items = array();
		foreach ( self::SECTIONS as $key => $def ) {
			$items[] = array( 'label' => $def[0], 'url' => $this->url( 'postelio-settings', array( 'tab' => $key ) ), 'active' => $key === $tab, 'group' => $def[1] );
		}
		$out .= Ui::sidenav_layout_open() . Ui::sidenav( $items, 'Sections des réglages' );
		$out .= '<div class="bo-col">' . Ui::card_open( self::SECTIONS[ $tab ][0], self::SECTIONS[ $tab ][2] ) . $this->section( $tab ) . Ui::card_close() . '</div>';
		return $out . Ui::sidenav_layout_close();
	}

	private function section( string $tab ): string {
		switch ( $tab ) {
			case 'accounts':
				return $this->accounts();
			case 'jobs':
				return $this->jobs();
			case 'notifications':
				return $this->notifications();
			case 'moderation':
				return $this->moderation();
			case 'sources':
				return $this->sources();
			case 'billing':
				return $this->billing();
			case 'security':
				return $this->security();
			case 'system':
				return $this->system();
			default:
				return $this->general();
		}
	}

	private function state( bool $ok, string $on = 'Actif', string $off = 'Inactif' ): string {
		return Ui::badge( $ok ? $on : $off, $ok ? 'success' : 'neutral', true );
	}

	/** Lien discret « ouvrir l'écran dédié » en bas de section. */
	private function open_link( string $label, string $slug ): string {
		return '<div class="bo-section">' . Ui::button( $label, $this->url( $slug ), '', true ) . '</div>';
	}

	private function general(): string {
		$core = Health::snapshot()['core'];
		$env  = (string) wp_get_environment_type();
		return Ui::section_open( 'Installation' ) . Ui::kv( array(
			'Nom du site'      => Ui::text( (string) get_bloginfo( 'name' ), true ),
			'Environnement'    => Ui::badge( $env, 'production' === $env ? 'success' : 'info' ),
			'Langue'           => Ui::text( (string) get_locale() ),
			'Santé globale'    => Ui::badge( Health::label( Health::global_status() ), Health::variant( Health::global_status() ), true ),
		) ) . Ui::section_close()
		. Ui::section_open( 'Versions' ) . Ui::kv( array(
			'Socle Postelio'    => Ui::text( (string) $core['version'], false, true ),
			'Schéma de base'    => Ui::text( (string) $core['schema'], false, true ),
			'WordPress'         => Ui::text( (string) get_bloginfo( 'version' ), false, true ),
			'PHP'               => Ui::text( PHP_VERSION, false, true ),
		) ) . Ui::section_close();
	}

	private function accounts(): string {
		$roles = array(
			'postelio_candidate' => 'Candidat',
			'postelio_recruiter' => 'Recruteur',
			'postelio_admin'     => 'Administrateur',
			'postelio_moderator' => 'Modérateur',
			'postelio_support'   => 'Support',
		);
		$chips = '';
		foreach ( $roles as $slug => $label ) {
			$chips .= Ui::badge( $label, null !== get_role( $slug ) ? 'success' : 'neutral', true );
		}
		return Ui::section_open( 'Rôles Postelio', 'Rôles réellement enregistrés dans WordPress.' ) . '<div class="bo-chips">' . $chips . '</div>'
			. Ui::help( 'Les capacités associées à chaque rôle sont définies par le socle Postelio ; elles ne sont pas modifiables ici.' ) . Ui::section_close()
			. Ui::section_open( 'Inscription et vérification' ) . Ui::kv( array(
				'Inscription publique' => $this->state( (bool) get_option( 'users_can_register' ), 'Ouverte', 'Fermée' ),
				'Vérification e-mail'  => Data::module_active( 'users' ) ? Ui::badge( 'Gérée par le module Comptes', 'info' ) : Ui::badge( 'Module absent', 'neutral' ),
				'Suspension de compte' => current_user_can( 'pst_suspend_account' ) ? Ui::badge( 'Disponible pour votre profil', 'success' ) : Ui::badge( 'Selon capacité', 'neutral' ),
			) ) . Ui::section_close();
	}

	private function jobs(): string {
		$jc = Data::job_counts();
		return Ui::section_open( 'Diffusion' ) . Ui::kv( array(
			'Module Offres'    => $this->state( Data::module_active( 'jobs' ) ),
			'Offres publiées'  => Ui::text( Fmt::count( null !== $jc ? (int) ( $jc['published'] ?? 0 ) : null ) ),
			'Expirent bientôt' => Ui::text( Fmt::count( null !== $jc ? (int) ( $jc['expiring'] ?? 0 ) : null ) ),
		) ) . Ui::help( 'Les durées de diffusion et de renouvellement sont appliquées par le module Offres. Aucun réglage arbitraire n\'est exposé ici.' ) . Ui::section_close()
		. $this->open_link( 'Ouvrir les offres', 'postelio-jobs' );
	}

	private function notifications(): string {
		$transport  = 'wp_mail (transport serveur)';
		$configured = false;
		$t          = Data::facade( '\\Postelio\\Notifications\\Api\\NotificationDirectory', 'transport', array(), null );
		if ( is_array( $t ) ) {
			$transport  = (string) ( $t['label'] ?? $transport );
			$configured = ! empty( $t['smtp_configured'] );
		}
		$pairs = array(
			'Module Notifications' => $this->state( Data::module_active( 'notifications' ) ),
			'Transport e-mail'     => Ui::badge( $transport, $configured ? 'success' : 'warning' ),
			'Tâches planifiées'    => Ui::badge( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'Cron système attendu' : 'WP-Cron actif', 'info' ),
		);
		$out   = Ui::section_open( 'Transport' ) . Ui::kv( $pairs ) . Ui::section_close();
		$stats = Data::delivery_stats();
		if ( null !== $stats ) {
			$failed = (int) ( $stats['failed'] ?? 0 );
			$out   .= Ui::section_open( 'File d\'envoi' ) . Ui::kv( array(
				'En attente' => Ui::text( (string) (int) ( $stats['pending'] ?? 0 ) ),
				'En échec'   => Ui::badge( (string) $failed, $failed > 0 ? 'warning' : 'success' ),
			) ) . Ui::section_close();
		}
		return $out . Ui::help( 'Le contenu des e-mails et les adresses des destinataires ne sont jamais affichés dans le back-office.' )
			. $this->open_link( 'Ouvrir le service e-mail', 'postelio-notifications' );
	}

	private function moderation(): string {
		$active   = Data::module_active( 'moderation' );
		$provider = $active ? (string) ( Rest::payload( '/postelio/v1/moderation/health' )['provider'] ?? 'local_only' ) : 'local_only';
		$labels   = array( 'local_only' => 'Analyse locale uniquement' );
		return Ui::section_open( 'Analyse de contenu' ) . Ui::kv( array(
			'Module Modération'  => $this->state( $active ),
			'Analyse de contenu' => Ui::badge( $labels[ $provider ] ?? $provider, 'info' ),
			'Dossiers ouverts'   => Ui::text( Fmt::count( Data::moderation_open() ) ),
		) ) . Ui::section_close() . $this->open_link( 'Ouvrir la modération', 'postelio-moderation' );
	}

	private function sources(): string {
		$sh         = Data::job_sources_health();
		$configured = is_array( $sh ) && ! empty( $sh['configured'] );
		return Ui::section_open( 'Connecteurs' ) . Ui::kv( array(
			'Module Sources' => $this->state( Data::module_active( 'job-sources' ) || Data::module_active( 'job_sources' ) ),
			'France Travail' => Ui::badge( $configured ? 'Connecté' : 'Non connecté', $configured ? 'success' : 'info', true ),
			'Identifiants'   => Ui::badge( 'Environnement serveur', 'neutral' ),
		) ) . Ui::help( 'Les identifiants des connecteurs sont lus dans l\'environnement serveur ; ils ne sont ni affichés ni modifiables ici.' ) . Ui::section_close()
			. $this->open_link( 'Voir les connecteurs', 'postelio-sources' );
	}

	private function billing(): string {
		if ( ! Data::module_active( 'billing' ) ) {
			return Ui::empty_state( 'Module indisponible', 'Le module Facturation n\'est pas actif.' );
		}
		$d   = Rest::payload( '/postelio/v1/billing/health' );
		$out = Ui::section_open( 'Paiement en ligne' ) . Ui::kv( array(
			'Mode'                     => Ui::badge( array( 'test' => 'Mode test', 'live' => 'Mode réel' )[ (string) ( $d['mode'] ?? '' ) ] ?? 'Mode inconnu', 'info' ),
			'Paiement en ligne'        => $this->state( ! empty( $d['configured'] ), 'Configuré', 'Non configuré' ),
			'Confirmation de paiement' => $this->state( ! empty( $d['webhook_configured'] ), 'Configurée', 'Non configurée' ),
		) ) . Ui::section_close();
		$out .= Ui::section_open( 'Facture légale' ) . Ui::kv( array(
			'Identité du vendeur' => $this->state( ! empty( $d['seller_configured'] ), 'Complète', 'Incomplète' ),
			'Facture légale'      => $this->state( ! empty( $d['invoice_legal_ready'] ), 'Prête', 'À configurer' ),
		) );
		if ( empty( $d['invoice_legal_ready'] ) ) {
			$out .= Ui::alert( 'Configuration de facturation incomplète : aucune facture légale ne peut être émise.', 'warning' );
		}
		$out .= Ui::help( 'Aucune clé ni secret de paiement n\'est exposé : la configuration se fait par les variables d\'environnement du serveur.' ) . Ui::section_close();
		return $out . $this->open_link( 'Ouvrir la facturation', 'postelio-billing' );
	}

	private function security(): string {
		$webhook_ok = false;
		if ( Data::module_active( 'billing' ) ) {
			$webhook_ok = ! empty( Rest::payload( '/postelio/v1/billing/health' )['webhook_configured'] );
		}
		$indicators = array(
			'Vérification e-mail'      => array( Data::module_active( 'users' ), 'Gérée par le module', 'Module absent' ),
			'Double authentification'  => array( false, 'Active', 'Non implémentée' ),
			'Journal d\'audit'         => array( Data::has( '\\Postelio\\Core\\Events\\EventBus' ) || current_user_can( 'pst_view_audit_log' ), 'Événements journalisés', 'Indisponible' ),
			'Stockage des fichiers'    => array( Data::module_active( 'files' ), 'Privé (hors web)', 'Module absent' ),
			'Modération de contenu'    => array( Data::module_active( 'moderation' ), 'Active', 'Inactive' ),
			'Confirmation de paiement' => array( $webhook_ok, 'Signée et configurée', 'Non configurée' ),
			'Authentification interne' => array( true, 'Session WordPress + jeton de formulaire', '—' ),
			'Jeton application'        => array( defined( 'POSTELIO_TAURI_BEARER' ) || defined( 'POSTELIO_APP_BEARER' ), 'Configuré (serveur)', 'Configuré côté serveur' ),
		);
		$checks = array();
		foreach ( $indicators as $label => $cfg ) {
			list( $ok, $on, $off ) = $cfg;
			$soft     = in_array( $off, array( 'Non implémentée', 'Configuré côté serveur', '—' ), true );
			$checks[] = array( $label, Ui::badge( $ok ? $on : $off, $ok ? 'success' : ( $soft ? 'info' : 'neutral' ), true ), '' );
		}
		return Ui::section_open( 'Indicateurs constatés', 'État observé, pas des interrupteurs.' ) . Ui::checks( $checks )
			. Ui::help( 'La configuration de sécurité s\'effectue au niveau du serveur et des modules ; cet écran ne fait que la constater.' ) . Ui::section_close();
	}

	/** Section Système : accès aux écrans techniques (retirés du menu principal). */
	private function system(): string {
		$global = Health::global_status();
		$out    = Ui::section_open( 'Santé du système', '', Ui::button( 'Ouvrir la page Santé', $this->url( 'postelio-health' ), 'primary', true ) )
			. Ui::kv( array( 'État global' => Ui::badge( Health::label( $global ), Health::variant( $global ), true ) ) ) . Ui::section_close();

		$out .= Ui::section_open( 'Écrans techniques' ) . Ui::rows_open();
		$out .= Ui::row( esc_html( 'Service e-mail' ), 'Transport, file d\'envoi et échecs de livraison.', '', Ui::button( 'Ouvrir', $this->url( 'postelio-notifications' ), '', true ) );
		$out .= Ui::row( esc_html( 'CV & fichiers' ), 'Stockage privé : compteurs et métadonnées.', '', Ui::button( 'Ouvrir', $this->url( 'postelio-files' ), '', true ) );
		$out .= Ui::row( esc_html( 'Favoris & Alertes' ), 'Compteurs agrégés et planificateur d\'alertes.', '', Ui::button( 'Ouvrir', $this->url( 'postelio-alerts' ), '', true ) );
		$out .= Ui::rows_close() . Ui::section_close();
		return $out;
	}
}
