<?php
/**
 * Modération : file de traitement et dossier détaillé, consommant l'API du module Modération
 * (`/moderation/cases`) — donc sans dupliquer la logique ni lire les tables. Toutes les décisions
 * (assigner, résoudre, ignorer, escalader, avertir, masquer, fermer, suspendre) sont DÉLÉGUÉES aux
 * endpoints du domaine, qui appliquent eux-mêmes leurs gardes de capability.
 *
 * @package Postelio\Backoffice\Screens
 */

namespace Postelio\Backoffice\Screens;

use Postelio\Backoffice\Menu;
use Postelio\Backoffice\Support\Data;
use Postelio\Backoffice\Support\Fmt;
use Postelio\Backoffice\Support\Rest;
use Postelio\Backoffice\Ui\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ModerationScreen extends ListScreen {

	/** @var array<string,string> file => libellé */
	private const QUEUES = array(
		'open'      => 'À traiter',
		'in_review' => 'En cours',
		'escalated' => 'Escaladés',
		'resolved'  => 'Traités',
		'dismissed' => 'Sans suite',
	);

	/** @var array<string,array{0:string,1:string}> priorité/risque => [libellé, variante] */
	private const LEVELS = array(
		'critical' => array( 'Critique', 'error' ),
		'high'     => array( 'Élevé', 'warning' ),
		'medium'   => array( 'Moyen', 'info' ),
		'low'      => array( 'Faible', 'neutral' ),
	);

	/** @var array<string,string> type de ressource => libellé humain */
	private const RESOURCES = array(
		'skill'        => 'Savoir-faire',
		'job'          => 'Offre',
		'external_job' => 'Offre partenaire',
		'company'      => 'Entreprise',
		'conversation' => 'Conversation',
		'user'         => 'Compte',
		'comment'      => 'Commentaire',
	);

	protected function capability(): string {
		return Menu::CAP_VIEW;
	}

	protected function slug(): string {
		return 'postelio-moderation';
	}

	protected function index(): string {
		if ( ! Data::module_active( 'moderation' ) ) {
			return $this->module_missing( 'Modération', 'Modération' );
		}
		$queue = $this->current( 'status', 'open' );
		if ( ! isset( self::QUEUES[ $queue ] ) ) {
			$queue = 'open';
		}
		$out = Ui::page_header( 'Modération', 'Signalements et contenus à examiner.' );

		$res = Rest::call( 'GET', '/postelio/v1/moderation/cases', array( 'status' => $queue, 'page' => $this->paged(), 'per_page' => static::PER_PAGE ) );
		if ( 403 === $res['status'] ) {
			return $out . Ui::empty_state( 'Accès restreint', 'Votre profil ne permet pas d\'accéder à la file de modération.' );
		}
		if ( 200 !== $res['status'] || ! is_array( $res['data'] ) ) {
			return $out . Ui::alert( 'La file de modération est momentanément indisponible.', 'warning' );
		}

		$tabs = array();
		foreach ( self::QUEUES as $key => $label ) {
			$tabs[] = array( 'label' => $label, 'url' => $this->url( $this->slug(), array( 'status' => $key ) ), 'active' => $key === $queue );
		}
		$out .= Ui::tabs( $tabs, 'Files de modération' );

		$items = (array) ( $res['data']['data'] ?? array() );
		$total = (int) ( $res['data']['meta']['pagination']['total'] ?? count( $items ) );

		$rows = array();
		foreach ( $items as $c ) {
			$rows[] = $this->row( (array) $c, $queue );
		}
		$out .= Ui::table( array( 'Ressource', 'Priorité', 'Risque', 'Origine', 'Signalements', 'Assigné à', 'Actions' ), $rows, 'Aucun dossier dans cette file.' );
		$out .= Ui::pager( $this->url( $this->slug(), array( 'status' => $queue ) ), $this->paged(), static::PER_PAGE, $total );
		return $out;
	}

	/** @param array<string,mixed> $c @return array<int,string> */
	private function row( array $c, string $queue ): array {
		$prio  = (string) ( $c['priority'] ?? 'medium' );
		$risk  = (string) ( $c['risk_level'] ?? 'medium' );
		$rtype = (string) ( $c['resource_type'] ?? '' );
		$pm    = self::LEVELS[ $prio ] ?? array( ucfirst( $prio ), 'neutral' );
		$rm    = self::LEVELS[ $risk ] ?? array( ucfirst( $risk ), 'neutral' );

		return array(
			Ui::entity( self::RESOURCES[ $rtype ] ?? Fmt::or_dash( $rtype ), Fmt::ref( (string) ( $c['resource_uuid'] ?? '' ) ), '', true ),
			Ui::badge( $pm[0], $pm[1], true ),
			Ui::badge( $rm[0], $rm[1] ),
			Ui::text( $this->origin_label( (string) ( $c['origin'] ?? '' ) ), false, true ),
			Ui::text( (string) (int) ( $c['reports_count'] ?? 0 ), false, true ),
			Ui::text( Fmt::or_dash( $c['assigned_to'] ?? '' ), false, true ),
			$this->quick_actions( (string) ( $c['uuid'] ?? '' ), $queue ),
		);
	}

	private function origin_label( string $origin ): string {
		$map = array( 'user_report' => 'Signalement', 'automatic' => 'Détection automatique', 'admin' => 'Administration', 'provider' => 'Fournisseur' );
		return $map[ $origin ] ?? Fmt::or_dash( $origin );
	}

	private function quick_actions( string $uuid, string $queue ): string {
		$h = '<div class="bo-actions">' . $this->view_link( $uuid );
		if ( '' !== $uuid && ! in_array( $queue, array( 'resolved', 'dismissed' ), true ) ) {
			if ( current_user_can( 'pst_decide_report' ) ) {
				$h .= Ui::action_button( 'pst_admin_mod_assign', array( 'uuid' => $uuid ), 'M\'assigner' );
			}
			if ( current_user_can( 'pst_moderate_content' ) ) {
				$h .= Ui::action_button( 'pst_admin_mod_resolve', array( 'uuid' => $uuid ), 'Traiter', 'primary', 'Marquer ce dossier comme traité, sans action sur le contenu ?' );
			}
		}
		return $h . '</div>';
	}

	protected function detail( string $uuid ): string {
		if ( ! Data::module_active( 'moderation' ) ) {
			return $this->module_missing( 'Modération', 'Modération' );
		}
		$res = Rest::call( 'GET', '/postelio/v1/moderation/cases/' . $uuid );
		if ( 200 !== $res['status'] || ! is_array( $res['data'] ) ) {
			return $this->not_found( 'Dossier de modération', 'Dossier introuvable ou accès refusé.' );
		}
		$c      = (array) ( $res['data']['data'] ?? array() );
		$status = (string) ( $c['status'] ?? '' );
		$rtype  = (string) ( $c['resource_type'] ?? '' );
		$prio   = (string) ( $c['priority'] ?? 'medium' );
		$risk   = (string) ( $c['risk_level'] ?? 'medium' );
		$pm     = self::LEVELS[ $prio ] ?? array( ucfirst( $prio ), 'neutral' );
		$rm     = self::LEVELS[ $risk ] ?? array( ucfirst( $risk ), 'neutral' );
		$closed = in_array( $status, array( 'resolved', 'dismissed' ), true );

		$title = ( self::RESOURCES[ $rtype ] ?? 'Dossier' ) . ' signalé';
		$out   = Ui::page_header( $title, self::QUEUES[ $status ] ?? Fmt::or_dash( $status ), Ui::badge( $pm[0], $pm[1], true ) . $this->back_link( '← File' ), 'Postelio · Modération' );
		$out  .= Ui::cols_open() . Ui::col_open();

		$out .= Ui::card_open( 'Dossier' ) . Ui::kv( array(
			'Ressource concernée' => Ui::text( self::RESOURCES[ $rtype ] ?? Fmt::or_dash( $rtype ), true ),
			'Priorité'            => Ui::badge( $pm[0], $pm[1], true ),
			'Niveau de risque'    => Ui::badge( $rm[0], $rm[1] ),
			'Origine'             => Ui::text( $this->origin_label( (string) ( $c['origin'] ?? '' ) ) ),
			'Signalements reçus'  => Ui::text( (string) (int) ( $c['reports_count'] ?? 0 ) ),
			'Assigné à'           => Ui::text( Fmt::or_dash( $c['assigned_to'] ?? '' ) ),
		) ) . Ui::details( 'Détails techniques', Ui::kv( array(
			'Type de ressource'    => Ui::text( Fmt::or_dash( $rtype ), false, true ),
			'Référence ressource'  => Ui::text( Fmt::ref( (string) ( $c['resource_uuid'] ?? '' ), 12 ), false, true ),
			'Référence dossier'    => Ui::text( Fmt::ref( $uuid, 12 ), false, true ),
		) ) ) . Ui::card_close();

		$rows = array();
		foreach ( (array) ( $c['events'] ?? array() ) as $e ) {
			$e      = (array) $e;
			$rows[] = array(
				Ui::text( Fmt::or_dash( $e['event'] ?? '' ), true ),
				Ui::text( Fmt::or_dash( $e['actor_role'] ?? '' ), false, true ),
				Ui::text( Fmt::or_dash( trim( (string) ( $e['action'] ?? '' ) . ' ' . (string) ( $e['decision'] ?? '' ) ) ) ),
				Ui::text( Fmt::or_dash( $e['note'] ?? '' ) ),
				Ui::text( Fmt::datetime( $e['at'] ?? '' ), false, true ),
			);
		}
		$out .= Ui::card_open( 'Historique', 'Journal non modifiable.' ) . Ui::table( array( 'Événement', 'Rôle', 'Décision', 'Note', 'Quand' ), $rows, 'Aucun événement.' ) . Ui::card_close();

		$out .= Ui::col_close() . Ui::col_open();
		$out .= Ui::card_open( 'Décision' );
		if ( $closed ) {
			$out .= Ui::alert( 'Ce dossier est clôturé. L\'historique reste consultable.', 'success' );
		} else {
			$out .= Ui::action_stack( $this->decision_buttons( $uuid, $rtype ) );
			if ( current_user_can( 'pst_moderate_content' ) ) {
				$out .= Ui::note_form( 'pst_admin_mod_note', array( 'uuid' => $uuid ), 'note', 'Note interne sur ce dossier…', 'Ajouter la note' );
			}
		}
		$out .= Ui::card_close();
		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
	}

	/** Actions contextuelles selon la ressource et les capacités (l'API revérifie tout). */
	private function decision_buttons( string $uuid, string $rtype ): string {
		$h = '';
		if ( current_user_can( 'pst_decide_report' ) ) {
			$h .= Ui::action_button( 'pst_admin_mod_assign', array( 'uuid' => $uuid ), 'M\'assigner ce dossier' );
		}
		if ( ! current_user_can( 'pst_moderate_content' ) ) {
			return '' !== $h ? $h : Ui::help( 'Votre profil permet de consulter ce dossier, pas de le décider.' );
		}
		$h .= Ui::action_button( 'pst_admin_mod_resolve', array( 'uuid' => $uuid ), 'Traiter sans action', 'primary' );
		$h .= Ui::action_button( 'pst_admin_mod_dismiss', array( 'uuid' => $uuid ), 'Classer sans suite' );
		$h .= Ui::action_button( 'pst_admin_mod_escalate', array( 'uuid' => $uuid ), 'Escalader' );
		$h .= Ui::action_button( 'pst_admin_mod_warning', array( 'uuid' => $uuid ), 'Avertir l\'auteur' );

		if ( in_array( $rtype, array( 'skill', 'external_job', 'job' ), true ) ) {
			$h .= Ui::action_button( 'pst_admin_mod_hide', array( 'uuid' => $uuid ), 'Masquer le contenu', 'danger', 'Masquer ce contenu du public ?' );
			$h .= Ui::action_button( 'pst_admin_mod_unhide', array( 'uuid' => $uuid ), 'Restaurer le contenu' );
		}
		if ( 'conversation' === $rtype ) {
			$h .= Ui::action_button( 'pst_admin_mod_close', array( 'uuid' => $uuid ), 'Fermer la conversation', 'danger', 'Fermer définitivement cette conversation ?' );
		}
		if ( 'job' === $rtype ) {
			$h .= Ui::action_button( 'pst_admin_mod_suspend_job', array( 'uuid' => $uuid ), 'Suspendre l\'offre', 'danger', 'Suspendre l\'offre concernée ?' );
		}
		if ( 'company' === $rtype ) {
			$h .= Ui::action_button( 'pst_admin_mod_suspend_company', array( 'uuid' => $uuid ), 'Suspendre l\'entreprise', 'danger', 'Suspendre l\'entreprise concernée ?' );
		}
		return $h;
	}
}
