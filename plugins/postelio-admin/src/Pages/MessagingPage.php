<?php
/**
 * Messagerie : supervision plateforme PRIVACY-FIRST. Métriques globales + liste de conversations
 * (contexte / participants / état / dernière activité). Le CONTENU des messages n'est JAMAIS
 * affiché (ni liste, ni détail) : la modération éventuelle passe par les outils de modération.
 * Lecture seule via MessagingAdminDirectory. Aucun ID SQL.
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Contracts;
use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MessagingPage extends Page {

	private const PER_PAGE = 20;
	private const DIR      = '\\Postelio\\Messaging\\Api\\MessagingAdminDirectory';

	/** @var array<string,array{0:string,1:string}> */
	private const STATUSES = array(
		'active'   => array( 'Active', 'success' ),
		'closed'   => array( 'Fermée', 'neutral' ),
		'archived' => array( 'Archivée', 'neutral' ),
	);

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_ADMIN;
	}

	protected function body(): string {
		if ( ! Contracts::has( self::DIR ) ) {
			return Ui::header( 'Messagerie', 'Back-office Postelio' )
				. Ui::empty_state( 'Module indisponible', 'Le module Messagerie n\'est pas actif.', '💬' );
		}
		$view = $this->current( 'view' );
		if ( '' !== $view ) {
			return $this->detail( $view );
		}
		$tab    = $this->current( 'tab', 'all' );
		$paged  = $this->paged();
		$counts = call_user_func( array( self::DIR, 'counts' ) );

		$out  = Ui::header( 'Messagerie', 'Supervision des échanges candidat ↔ entreprise' );

		// KPI (aucun contenu).
		$out .= '<div class="pst-admin-grid">';
		$out .= Ui::stat( 'Conversations', (string) (int) ( $counts['total'] ?? 0 ), 'total' );
		$out .= Ui::stat( 'Actives', (string) (int) ( $counts['active'] ?? 0 ), '', true );
		$out .= Ui::stat( 'Fermées', (string) (int) ( $counts['closed'] ?? 0 ) );
		$out .= Ui::stat( 'Messages', (string) (int) ( $counts['messages'] ?? 0 ), 'total' );
		$out .= Ui::stat( 'Messages (7 j)', (string) (int) ( $counts['messages_7d'] ?? 0 ), 'activité récente' );
		$out .= '</div>';

		$out .= Ui::alert( 'Confidentialité : le contenu des messages n\'est pas accessible depuis la supervision. Seul le contexte des conversations est affiché.', 'info' );

		$filters = array();
		if ( 'all' !== $tab && isset( self::STATUSES[ $tab ] ) ) {
			$filters['status'] = $tab;
		}
		$res = call_user_func( array( self::DIR, 'list' ), $filters, $paged, self::PER_PAGE );

		$tabs = array( array( 'label' => 'Toutes', 'url' => $this->url( 'postelio-messaging', array( 'tab' => 'all' ) ), 'count' => (int) ( $counts['total'] ?? 0 ), 'active' => 'all' === $tab ) );
		foreach ( self::STATUSES as $st => $meta ) {
			$tabs[] = array( 'label' => $meta[0], 'url' => $this->url( 'postelio-messaging', array( 'tab' => $st ) ), 'count' => (int) ( $counts[ $st ] ?? 0 ), 'active' => $st === $tab );
		}
		$out .= Ui::tabs( $tabs );

		$rows = array();
		foreach ( $res['items'] as $c ) {
			$rows[] = $this->row( (array) $c );
		}
		$out .= Ui::table( array( 'Sujet', 'Candidat', 'Entreprise', 'Statut', 'Dernière activité', 'Actions' ), $rows, 'Aucune conversation.' );
		$out .= Ui::pager( $this->url( 'postelio-messaging', array( 'tab' => $tab ) ), $paged, self::PER_PAGE, (int) $res['total'] );
		return $out;
	}

	/** @param array<string,mixed> $c @return array<int,string> */
	private function row( array $c ): array {
		$st  = (string) $c['status'];
		$m   = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		$sub = trim( (string) ( $c['subject'] ?? '' ) );
		return array(
			Ui::text( '' !== $sub ? $sub : 'Conversation', true ),
			Ui::text( (string) $c['candidate'], false, true ),
			Ui::text( (string) $c['company'], false, true ),
			Ui::badge( $m[0], $m[1], true ),
			Ui::text( $this->fmt( (string) ( $c['last_message_at'] ?? $c['created_at'] ?? '' ) ), false, true ),
			'<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-messaging', array( 'view' => (string) $c['uuid'] ) ) ) . '">Voir</a>',
		);
	}

	private function detail( string $uuid ): string {
		$c    = call_user_func( array( self::DIR, 'detail' ), $uuid );
		$back = '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-messaging' ) ) . '">← Liste</a>';
		if ( null === $c ) {
			return Ui::header( 'Conversation', 'Back-office Postelio', $back ) . Ui::empty_state( 'Introuvable', 'Cette conversation n\'existe pas.', '💬' );
		}
		$st  = (string) $c['status'];
		$m   = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		$sub = trim( (string) ( $c['subject'] ?? '' ) );

		$out  = Ui::header( '' !== $sub ? $sub : 'Conversation', (string) $c['candidate'] . ' ↔ ' . (string) $c['company'], $back . ' ' . Ui::badge( $m[0], $m[1], true ) );
		$out .= '<div class="pst-admin-cols"><div>';

		// Contexte.
		$out .= Ui::card_open( 'Contexte' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Statut</dt><dd>' . Ui::badge( $m[0], $m[1], true ) . '</dd>';
		$out .= '<dt>Candidat</dt><dd>' . esc_html( (string) $c['candidate'] ) . '</dd>';
		$out .= '<dt>Entreprise</dt><dd>' . esc_html( (string) $c['company'] ) . '</dd>';
		$out .= '<dt>Messages</dt><dd>' . esc_html( (string) (int) ( $c['message_count'] ?? 0 ) ) . '</dd>';
		$out .= '<dt>Créée le</dt><dd>' . esc_html( $this->fmt( (string) ( $c['created_at'] ?? '' ) ) ) . '</dd>';
		$out .= '<dt>Dernière activité</dt><dd>' . esc_html( $this->fmt( (string) ( $c['last_message_at'] ?? '' ) ) ) . '</dd>';
		$out .= '</dl>' . Ui::card_close();

		// Candidature liée.
		$app_uuid = (string) ( $c['application_uuid'] ?? '' );
		$out .= Ui::card_open( 'Candidature liée' );
		if ( '' !== $app_uuid && Contracts::has( '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory' ) ) {
			$out .= '<p><a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-applications', array( 'view' => $app_uuid ) ) ) . '">Ouvrir la candidature</a></p>';
		} else {
			$out .= '<p class="pst-help">Référence : ' . esc_html( '' !== $app_uuid ? substr( $app_uuid, 0, 8 ) . '…' : '—' ) . '</p>';
		}
		$out .= Ui::card_close();
		$out .= '</div><div>';

		// Participants.
		$rows = array();
		foreach ( (array) ( $c['participants'] ?? array() ) as $p ) {
			$role = (string) ( $p['role'] ?? '' );
			$rl   = array( 'candidate' => 'Candidat', 'company' => 'Entreprise', 'recruiter' => 'Recruteur' );
			$rows[] = array( Ui::text( (string) ( $p['name'] ?? '—' ), true ), Ui::badge( $rl[ $role ] ?? ( '' !== $role ? $role : '—' ), 'candidate' === $role ? 'info' : 'neutral' ) );
		}
		$out .= Ui::card_open( 'Participants' ) . Ui::table( array( 'Participant', 'Rôle' ), $rows, 'Aucun participant.' ) . Ui::card_close();

		// Contenu — protégé.
		$out .= Ui::card_open( 'Contenu des messages' );
		$out .= '<p class="pst-help">' . Ui::badge( 'Protégé', 'neutral', true ) . ' Contenu protégé — accessible uniquement dans le cadre d\'une modération, via les outils de modération dédiés.</p>';
		$out .= Ui::card_close();
		$out .= '</div></div>';
		return $out;
	}

	private function fmt( string $v ): string {
		return ( '' !== $v && '0000-00-00 00:00:00' !== $v ) ? mysql2date( 'd/m/Y H:i', $v ) : '—';
	}
}
