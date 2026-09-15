<?php
/**
 * Messagerie : supervision PRIVACY-FIRST en boîte de réception (colonne des conversations à gauche,
 * panneau contexte / participants / état à droite). Le CONTENU des messages n'est JAMAIS affiché,
 * ni en liste ni en détail : la modération d'un échange passe par les outils de modération dédiés.
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

	protected function eyebrow(): string {
		return 'Postelio · Activité';
	}

	protected function slug(): string {
		return 'postelio-messaging';
	}

	/** Liste et détail partagent la même boîte de réception ; le détail sélectionne une conversation. */
	protected function body(): string {
		if ( ! Data::has( self::DIR ) ) {
			return $this->module_missing( 'Messagerie', 'Messagerie' );
		}
		return $this->inbox( $this->current( 'view' ) );
	}

	protected function index(): string {
		return $this->inbox( '' );
	}

	private function inbox( string $selected ): string {
		$tab    = $this->current( 'tab', 'all' );
		$counts = (array) call_user_func( array( self::DIR, 'counts' ) );

		$filters = array();
		if ( 'all' !== $tab && isset( self::STATUSES[ $tab ] ) ) {
			$filters['status'] = $tab;
		}
		$res   = (array) call_user_func( array( self::DIR, 'list' ), $filters, $this->paged(), static::PER_PAGE );
		$items = (array) $res['items'];

		$out  = $this->header( 'Messagerie', 'Échanges entre candidats et entreprises. Le contenu des messages reste protégé.', Ui::badge( (int) ( $counts['messages_7d'] ?? 0 ) . ' messages sur 7 jours', 'neutral' ) );
		$out .= $this->toolbar( $this->status_tabs( array_map( static fn( $m ) => $m[0], self::STATUSES ), $counts, $tab, 'Toutes' ) );

		$out .= Ui::split_open();
		$out .= Ui::inbox_open( (int) $res['total'] . ( (int) $res['total'] > 1 ? ' conversations' : ' conversation' ), Ui::text( (int) ( $counts['messages'] ?? 0 ) . ' messages', false, true ) );
		if ( empty( $items ) ) {
			$out .= Ui::empty_state( 'Aucune conversation ne correspond' );
		}
		foreach ( $items as $c ) {
			$c       = (array) $c;
			$st      = (string) $c['status'];
			$meta    = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
			$subject = trim( (string) ( $c['subject'] ?? '' ) );
			$out    .= Ui::inbox_item(
				$this->url( $this->slug(), array( 'tab' => $tab, 'view' => (string) $c['uuid'] ) ),
				(string) $c['candidate'],
				Fmt::or_dash( $c['company'] ) . ( '' !== $subject ? ' · ' . $subject : '' ),
				(string) $c['candidate'],
				Fmt::date( $c['last_message_at'] ?? ( $c['created_at'] ?? '' ) ),
				Ui::badge( $meta[0], $meta[1], true ),
				$selected === (string) $c['uuid']
			);
		}
		$out .= Ui::inbox_close( $this->pagination( (int) $res['total'], array( 'tab' => $tab ) ) );

		$out .= '' !== $selected ? $this->pane( $selected ) : Ui::pane_placeholder( 'Sélectionnez une conversation pour afficher son contexte, ses participants et son état.' );
		$out .= Ui::split_close();
		return $out;
	}

	/** Panneau de droite : contexte protégé d'une conversation. */
	private function pane( string $uuid ): string {
		$c = call_user_func( array( self::DIR, 'detail' ), $uuid );
		if ( ! is_array( $c ) ) {
			return Ui::pane_placeholder( 'Cette conversation n\'existe pas.' );
		}
		$st      = (string) $c['status'];
		$meta    = self::STATUSES[ $st ] ?? array( ucfirst( $st ), 'neutral' );
		$subject = trim( (string) ( $c['subject'] ?? '' ) );

		$out  = Ui::pane_open( Ui::identity( (string) $c['candidate'], Fmt::or_dash( $c['company'] ) . ( '' !== $subject ? ' · ' . $subject : '' ) ), Ui::badge( $meta[0], $meta[1], true ) );
		$out .= Ui::section_open( 'Contexte' ) . Ui::kv( array(
			'Messages échangés' => Ui::text( (string) (int) ( $c['message_count'] ?? 0 ) ),
			'Ouverte le'        => Ui::text( Fmt::datetime( $c['created_at'] ?? '' ) ),
			'Dernière activité' => Ui::text( Fmt::datetime( $c['last_message_at'] ?? '' ) ),
		) ) . Ui::section_close();

		$roles = array( 'candidate' => 'Candidat', 'company' => 'Entreprise', 'recruiter' => 'Recruteur' );
		$out  .= Ui::section_open( 'Participants' ) . Ui::rows_open();
		$parts = (array) ( $c['participants'] ?? array() );
		if ( empty( $parts ) ) {
			$out .= Ui::help( 'Aucun participant.' );
		}
		foreach ( $parts as $p ) {
			$p    = (array) $p;
			$role = (string) ( $p['role'] ?? '' );
			$name = Fmt::or_dash( $p['name'] ?? '' );
			$out .= Ui::row( esc_html( $name ), '', Ui::badge( $roles[ $role ] ?? Fmt::or_dash( $role ), 'candidate' === $role ? 'info' : 'neutral' ), '', '', Ui::avatar( $name ) );
		}
		$out .= Ui::rows_close() . Ui::section_close();

		$app_uuid = (string) ( $c['application_uuid'] ?? '' );
		$out     .= Ui::section_open( 'Contenu et suivi' );
		$out     .= Ui::protected_notice( 'Le contenu des échanges est protégé. Il n\'est consultable que dans le cadre d\'une modération, via les outils dédiés.' );
		$out     .= '<div class="bo-actions bo-actions--wrap">' . ( ( '' !== $app_uuid && Data::has( '\\Postelio\\Applications\\Api\\ApplicationAdminDirectory' ) )
			? Ui::button( 'Ouvrir la candidature liée', $this->url( 'postelio-applications', array( 'view' => $app_uuid ) ), 'primary', true )
			: Ui::text( 'Aucune candidature accessible pour cette conversation.', false, true ) ) . '</div>';
		$out     .= Ui::section_close();
		return $out . Ui::pane_close();
	}
}
