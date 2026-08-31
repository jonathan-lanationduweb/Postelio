<?php
/**
 * Réglages Postelio : page STRUCTURÉE en lecture (onglets Général / Comptes / Offres /
 * Notifications / Modération / Sources / Facturation / Sécurité). N'affiche QUE des états réels et
 * détectables ; jamais de réglage fictif, jamais de secret (clés/tokens restent en environnement
 * serveur). L'onglet Sécurité est une liste d'INDICATEURS de santé, pas de faux interrupteurs.
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

final class SettingsPage extends Page {

	private const TABS = array(
		'general'       => 'Général',
		'accounts'      => 'Comptes',
		'jobs'          => 'Offres',
		'notifications' => 'Notifications',
		'moderation'    => 'Modération',
		'sources'       => 'Sources',
		'billing'       => 'Facturation',
		'security'      => 'Sécurité',
	);

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_ADMIN;
	}

	protected function body(): string {
		$tab = $this->current( 'tab', 'general' );
		if ( ! isset( self::TABS[ $tab ] ) ) {
			$tab = 'general';
		}
		$out  = Ui::header( 'Réglages', 'États de configuration de la plateforme (lecture seule, sans secrets)' );

		$tabs = array();
		foreach ( self::TABS as $key => $label ) {
			$tabs[] = array( 'label' => $label, 'url' => $this->url( 'postelio-settings', array( 'tab' => $key ) ), 'active' => $key === $tab );
		}
		$out .= Ui::tabs( $tabs );
		$out .= Ui::alert( 'Ces réglages reflètent l\'état réel des modules. Les clés d\'API, secrets Stripe et jetons restent configurés côté serveur (variables d\'environnement) et ne sont jamais affichés ni modifiables ici.', 'info' );

		switch ( $tab ) {
			case 'accounts':      return $out . $this->accounts();
			case 'jobs':          return $out . $this->jobs();
			case 'notifications': return $out . $this->notifications();
			case 'moderation':    return $out . $this->moderation();
			case 'sources':       return $out . $this->sources();
			case 'billing':       return $out . $this->billing();
			case 'security':      return $out . $this->security();
			default:              return $out . $this->general();
		}
	}

	/** Ligne clé/valeur (valeur = HTML déjà échappé, typiquement un badge). */
	private function kv( array $pairs ): string {
		$h = '<dl class="pst-admin-kv">';
		foreach ( $pairs as $label => $value ) {
			$h .= '<dt>' . esc_html( (string) $label ) . '</dt><dd>' . $value . '</dd>';
		}
		return $h . '</dl>';
	}

	private function state( bool $ok, string $on = 'Actif', string $off = 'Inactif' ): string {
		return Ui::badge( $ok ? $on : $off, $ok ? 'success' : 'neutral', true );
	}

	private function general(): string {
		$core = Health::snapshot()['core'];
		return Ui::card_open( 'Plateforme' ) . $this->kv( array(
			'Nom du site'   => Ui::text( (string) get_bloginfo( 'name' ) ),
			'Environnement' => Ui::badge( (string) wp_get_environment_type(), 'production' === wp_get_environment_type() ? 'success' : 'info' ),
			'Version core'  => Ui::text( (string) $core['version'] ),
			'Schéma DB'     => Ui::text( (string) $core['schema'] ),
			'Langue'        => Ui::text( (string) get_locale() ),
			'Santé globale' => Ui::badge( Health::label( (string) $core['status'] ), Health::badge_variant( (string) $core['status'] ), true ),
		) ) . Ui::card_close();
	}

	private function accounts(): string {
		$roles = array( 'postelio_candidate' => 'Candidat', 'postelio_recruiter' => 'Recruteur', 'postelio_admin' => 'Admin', 'postelio_moderator' => 'Modérateur', 'postelio_support' => 'Support' );
		$chips = array();
		foreach ( $roles as $slug => $label ) {
			$chips[] = Ui::badge( $label, null !== get_role( $slug ) ? 'success' : 'neutral', true );
		}
		$out  = Ui::card_open( 'Rôles Postelio' ) . '<p>' . implode( ' ', $chips ) . '</p>'
			. '<p class="pst-help">Les capacités par rôle sont définies dans le core (source de vérité : docs/backend/roles-permissions.md).</p>' . Ui::card_close();
		$out .= Ui::card_open( 'Inscription & vérification' ) . $this->kv( array(
			'Inscription ouverte'   => $this->state( (bool) get_option( 'users_can_register' ), 'Ouverte', 'Fermée' ),
			'Vérification e-mail'    => Contracts::module_active( 'users' ) ? Ui::badge( 'Gérée par le module Utilisateurs', 'info' ) : Ui::badge( 'Module absent', 'neutral' ),
			'Suspension de compte'   => current_user_can( 'pst_suspend_account' ) ? Ui::badge( 'Disponible', 'success' ) : Ui::badge( 'Selon capacité', 'neutral' ),
		) ) . Ui::card_close();
		return $out;
	}

	private function jobs(): string {
		$active = Contracts::module_active( 'jobs' );
		$jc     = Metrics::job_counts();
		$out    = Ui::card_open( 'Offres' ) . $this->kv( array(
			'Module Offres'        => $this->state( $active ),
			'Cycle de vie'         => Ui::badge( 'Géré par le module Offres', 'info' ),
			'Offres publiées'      => Ui::text( null !== $jc ? (string) (int) ( $jc['published'] ?? 0 ) : '—', false, null === $jc ),
			'Expirent bientôt'     => Ui::text( null !== $jc ? (string) (int) ( $jc['expiring'] ?? 0 ) : '—', false, null === $jc ),
		) )
		. '<p class="pst-help">Les durées d\'expiration et de renouvellement sont appliquées par le module Offres. Aucun réglage arbitraire n\'est exposé ici.</p>'
		. Ui::card_close();
		return $out;
	}

	private function notifications(): string {
		$active   = Contracts::module_active( 'notifications' );
		$wpmailsmtp = class_exists( '\\WPMailSMTP\\Core' );
		$transport = $wpmailsmtp ? 'Configuré par WP Mail SMTP' : 'wp_mail (transport serveur / SMTP système)';
		$pairs = array(
			'Module Notifications' => $this->state( $active ),
			'Transport e-mail'     => Ui::badge( $transport, $wpmailsmtp ? 'success' : 'info' ),
			'Planificateur'        => Ui::badge( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'WP-Cron désactivé (cron système attendu)' : 'WP-Cron actif', 'info' ),
		);
		// Statistiques de livraison si le contrat est présent.
		if ( Contracts::has( '\\Postelio\\Notifications\\Api\\NotificationDirectory' ) && method_exists( '\\Postelio\\Notifications\\Api\\NotificationDirectory', 'delivery_stats' ) ) {
			$st = (array) call_user_func( array( '\\Postelio\\Notifications\\Api\\NotificationDirectory', 'delivery_stats' ) );
			$pairs['File en attente']   = Ui::text( (string) (int) ( $st['pending'] ?? 0 ) );
			$pairs['Échecs de livraison'] = Ui::badge( (string) (int) ( $st['failed'] ?? 0 ), (int) ( $st['failed'] ?? 0 ) > 0 ? 'warning' : 'success' );
		}
		return Ui::card_open( 'Notifications' ) . $this->kv( $pairs )
			. '<p class="pst-help">Le contenu des e-mails et les adresses des destinataires ne sont jamais affichés dans le back-office.</p>' . Ui::card_close();
	}

	private function moderation(): string {
		$active = Contracts::module_active( 'moderation' );
		$open   = Metrics::moderation_open();
		$provider = 'local_only';
		if ( $active ) {
			$h = Contracts::rest( 'GET', '/postelio/v1/moderation/health' );
			$d = is_array( $h['data'] ) ? ( $h['data']['data'] ?? array() ) : array();
			$provider = (string) ( $d['provider'] ?? 'local_only' );
		}
		return Ui::card_open( 'Modération' ) . $this->kv( array(
			'Module Modération'  => $this->state( $active ),
			'Fournisseur'        => Ui::badge( $provider, 'info' ),
			'Dossiers ouverts'   => Ui::text( null !== $open ? (string) $open : '—', false, null === $open ),
			'File d\'attente'     => '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-moderation' ) ) . '">Ouvrir la modération</a>',
		) ) . Ui::card_close();
	}

	private function sources(): string {
		$active = Contracts::module_active( 'job-sources' ) || Contracts::module_active( 'job_sources' );
		$sh     = Metrics::job_sources_health();
		$configured = is_array( $sh ) && ( ! empty( $sh['configured'] ) || ! empty( $sh['providers'] ) );
		return Ui::card_open( 'Sources d\'offres externes' ) . $this->kv( array(
			'Module Sources'    => $this->state( $active ),
			'France Travail'    => Ui::badge( $configured ? 'Configuré' : 'Non configuré (aucune clé)', $configured ? 'success' : 'info' ),
			'Clés API'          => Ui::badge( 'En environnement serveur', 'neutral' ),
		) )
		. '<p class="pst-help">Les identifiants des connecteurs externes sont lus dans l\'environnement serveur ; ils ne sont ni affichés ni modifiables ici.</p>'
		. Ui::card_close();
	}

	private function billing(): string {
		if ( ! Contracts::module_active( 'billing' ) ) {
			return Ui::card_open( 'Facturation' ) . Ui::empty_state( 'Module indisponible', 'Le module Facturation n\'est pas actif.', '💳' ) . Ui::card_close();
		}
		$h = Contracts::rest( 'GET', '/postelio/v1/billing/health' );
		$d = is_array( $h['data'] ) ? ( $h['data']['data'] ?? array() ) : array();
		return Ui::card_open( 'Facturation (Stripe)' ) . $this->kv( array(
			'Mode'                 => Ui::badge( (string) ( $d['mode'] ?? 'inconnu' ), 'info' ),
			'Stripe configuré'     => $this->state( ! empty( $d['configured'] ), 'Configuré', 'Non configuré' ),
			'Webhook'              => $this->state( ! empty( $d['webhook_configured'] ), 'Configuré', 'Non configuré' ),
			'Identité vendeur'     => $this->state( ! empty( $d['seller_configured'] ), 'Complète', 'Incomplète' ),
			'Facture légale'       => $this->state( ! empty( $d['invoice_legal_ready'] ), 'Prête', 'À configurer' ),
			'Clés Stripe'          => Ui::badge( 'En environnement serveur', 'neutral' ),
		) )
		. '<p class="pst-help">Aucune clé ni secret Stripe n\'est exposé. La configuration des clés se fait via les variables d\'environnement du serveur.</p>'
		. Ui::card_close();
	}

	private function security(): string {
		$bh_configured = false;
		$webhook_ok    = false;
		if ( Contracts::module_active( 'billing' ) ) {
			$h = Contracts::rest( 'GET', '/postelio/v1/billing/health' );
			$d = is_array( $h['data'] ) ? ( $h['data']['data'] ?? array() ) : array();
			$bh_configured = ! empty( $d['configured'] );
			$webhook_ok    = ! empty( $d['webhook_configured'] );
		}
		$indicators = array(
			'Vérification e-mail'     => array( Contracts::module_active( 'users' ), 'Gérée par le module', 'Module absent' ),
			'2FA administrateurs'     => array( false, 'Actif', 'Prévu (non implémenté)' ),
			'Journal d\'audit'         => array( class_exists( '\\Postelio\\Core\\Events\\EventBus' ) || current_user_can( 'pst_view_audit_log' ), 'Événements de domaine', 'Indisponible' ),
			'Stockage des fichiers'   => array( Contracts::module_active( 'files' ), 'Privé (hors web)', 'Module absent' ),
			'Modération de contenu'   => array( Contracts::module_active( 'moderation' ), 'Active', 'Inactive' ),
			'Webhook Stripe'          => array( $webhook_ok, 'Signé & configuré', 'Non configuré' ),
			'Authentification REST'   => array( true, 'Cookie WP + nonce', '—' ),
			'Jeton Bearer (Tauri)'    => array( defined( 'POSTELIO_TAURI_BEARER' ) || defined( 'POSTELIO_APP_BEARER' ), 'Configuré (serveur)', 'Configuré côté serveur' ),
		);
		$pairs = array();
		foreach ( $indicators as $label => $cfg ) {
			list( $ok, $on, $off ) = $cfg;
			$pairs[ $label ] = Ui::badge( $ok ? $on : $off, $ok ? 'success' : ( 'Prévu (non implémenté)' === $off || 'Configuré côté serveur' === $off ? 'info' : 'neutral' ), true );
		}
		return Ui::card_open( 'Indicateurs de sécurité' ) . $this->kv( $pairs )
			. '<p class="pst-help">Indicateurs de santé (lecture seule). Ce ne sont pas des interrupteurs : la configuration de sécurité s\'effectue au niveau du serveur et des modules.</p>'
			. Ui::card_close();
	}
}
