<?php
/**
 * Messagerie : supervision PRIVACY-FIRST. Indicateurs globaux + conversations (contexte,
 * participants, état, dernière activité). Le CONTENU des messages n'est JAMAIS affiché, ni en
 * liste ni en détail : la modération d'un échange passe par les outils de modération dédiés.
 * Lecture seule via `Messaging\Api\MessagingAdminDirectory` — aucune lecture directe des tables.
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

final class MessagingScreen extends ListScreen {

	private const DIR = '\\Postelio\\Messaging\\Api\\MessagingAdminDirectory';

	/** @var array<string,array{0:string,1:string}> */
	private const STATUSES = array(
		'active'   => array( 'Active', 'success' ),
		'closed'   => array( 'Fermée', 'neutral' ),
		'archived' => array( 'Archivée', 'neutral' ),
	);

	protected function capability(): string {
		return Menu::CAP_ADMIN;
	}

	protected function slug(): string {
		return 'postelio-messaging';
	}

	protected function index(): string {
		if ( ! Data::has( self::DIR ) ) {
			return $this->module_missing( 'Messagerie', 'Messagerie' );
		}
		$tab    = $this->current( 'tab', 'all' );
		$counts = (array) call_user_func( array( self::DIR, 'counts' ) );

		$out  = Ui::page_header( 'Messagerie', 'Échanges entre candidats et entreprises.' );
		$out .= '<div class="bo-stats bo-stats--4">';
		$out .= Ui::stat( 'Conversations', (int) ( $counts['total'] ?? 0 ) );
		$out .= Ui::stat( 'Actives', (int) ( $counts['active'] ?? 0 ), '', true );
		$out .= Ui::stat( 'Messages échangés', (int) ( $counts['messages'] ?? 0 ) );
		$out .= Ui::stat( 'Messages (7 jours)', (int) ( $counts['messages_7d'] ?? 0 ) );
		$out .= '</div>';
		$out .= Ui::alert( 'Le contenu des messages n\'est pas accessible depuis la supervision : seul le contexte des conversations est affiché.', 'info' );

		$filters = array();
		if ( 'all' !== $tab && isset( self::STATUSES[ $tab ] ) ) {
			$filters['status'] = $tab;
		}
		$res  = (array) call_user_func( array( self::DIR, 'list' ), $filters, $this->paged(), static::PER_PAGE );
		$out .= $this->status_tabs( array_map( static fn( $m ) => $m[0], self::STATUSES ), $counts, $tab, 'Toutes' );

		$rows = array();
		foreach ( (array) $res['items'] as $c ) {
			$rows[] = $this->row( (array) $c );
		}
		$out .= Ui::table( array( 'Candidat', 'Entreprise', 'Statut', 'Dernière activité', 'Actions' ), $rows, 'Aucune conversation ne correspond.' );
		$out .= $this->pagination( (int) $res['total'], array( 'tab' => $tab ) );
		return $out;
	}

	/** @param array<string,mixed> $c @return array<int,string> */
	private function row( array $c ): array {
		$st      = (string) $c['status'];
		$meta    = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		$subject = trim( (string) ( $c['subject'] ?? '' ) );
		return array(
			Ui::entity( (string) $c['candidate'], '' !== $subject ? $subject : 'Conversation' ),
			Ui::entity( Fmt::or_dash( $c['company'] ), '', '', true ),
			Ui::badge( $meta[0], $meta[1], true ),
			Ui::text( Fmt::datetime( $c['last_message_at'] ?? ( $c['created_at'] ?? '' ) ), false, true ),
			$this->view_link( (string) $c['uuid'] ),
		);
	}

	protected function detail( string $uuid ): string {
		if ( ! Data::has( self::DIR ) ) {
			return $this->module_missing( 'Messagerie', 'Messagerie' );
		}
		$c = call_user_func( array( self::DIR, 'detail' ), $uuid );
		if ( ! is_array( $c ) ) {
			return $this->not_found( 'Conversation', 'Cette conversation n\'existe pas.' );
		}
		$st      = (string) $c['status'];
		$meta    = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		$subject = trim( (string) ( $c['subject'] ?? '' ) );

		$out  = Ui::page_header( '' !== $subject ? $subject : 'Conversation', (string) $c['candidate'] . ' ↔ ' . (string) $c['company'], Ui::badge( $meta[0], $meta[1], true ) . $this->back_link(), 'Postelio · Messagerie' );
		$out .= Ui::cols_open() . Ui::col_open();

		$out .= Ui::card_open( 'Contexte' ) . Ui::kv( array(
			'Statut'             => Ui::badge( $meta[0], $meta[1], true ),
			'Candidat'           => Ui::text( (string) $c['candidate'], true ),
			'Entreprise'         => Ui::text( Fmt::or_dash( $c['company'] ) ),
			'Messages échangés'  => Ui::text( (string) (int) ( $c['message_count'] ?? 0 ) ),
			'Ouverte le'         => Ui::text( Fmt::datetime( $c['created_at'] ?? '' ) ),
			'Dernière activité'  => Ui::text( Fmt::datetime( $c['last_message_at'] ?? '' ) ),
		) ) . Ui::card_close();

		$out .= Ui::card_open( 'Contenu des messages' )
			. Ui::protected_notice( 'Le contenu des échanges est protégé. Il n\'est consultable que dans le cadre d\'une modération, via les outils de modération dédiés.' )
			. Ui::card_close();

		$out .= Ui::col_close() . Ui::col_open();

		$roles = array( 'candidate' => 'Candidat', 'company' => 'Entreprise', 'recruiter' => 'Recruteur' );
		$rows  = array();
		foreach ( (array) ( $c['participants'] ?? array() ) as $p ) {
			$p      = (array) $p;
			$role   = (string) ( $p['role'] ?? '' );
			$rows[] = array(
				Ui::entity( Fmt::or_dash( $p['name'] ?? '' ), '' ),
				Ui::badge( $roles[ $role ] ?? Fmt::or_dash( $role ), 'candidate' === $role ? 'info' : 'neutral' ),
			);
		}
		$out .= Ui::card_open( 'Participants' ) . Ui::table( array( 'Participant', 'Rôle' ), $rows, 'Aucun participant.' ) . Ui::card_close();

		$app_uuid = (string) ( $c['application_uuid'] ?? '' );
		$out     .= Ui::card_open( 'Candidature liée' );
		$out     .= ( '' !== $app_uuid && Data::has( '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory' ) )
			? Ui::button( 'Ouvrir la candidature', $this->url( 'postelio-applications', array( 'view' => $app_uuid ) ), 'primary', true )
			: Ui::help( 'Cette conversation n\'est pas rattachée à une candidature accessible.' );
		$out     .= Ui::card_close();

		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
	}
}
