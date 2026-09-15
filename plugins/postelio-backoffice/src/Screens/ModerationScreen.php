<?php
/**
 * Modération : file de traitement priorisée (barre de priorité, ressource, signalements,
 * ancienneté, « Examiner ») et dossier détaillé (contexte, historique, décision, note),
 * consommant l'API du module Modération (`/moderation/cases`) — donc sans dupliquer la logique ni
 * lire les tables. Toutes les décisions (assigner, résoudre, ignorer, escalader, avertir, masquer,
 * fermer, suspendre) sont DÉLÉGUÉES aux endpoints du domaine, qui appliquent eux-mêmes leurs gardes.
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

	/** @var array<string,array{0:string,1:string}> état vide par file : titre, explication */
	private const EMPTY = array(
		'open'      => array( 'Aucun dossier à traiter', 'Les signalements et les contenus qui nécessitent une intervention apparaîtront ici, du plus urgent au moins urgent.' ),
		'in_review' => array( 'Aucun dossier en cours', 'Les dossiers qu\'un modérateur s\'est assignés apparaîtront ici.' ),
		'escalated' => array( 'Aucun dossier escaladé', 'Les dossiers transmis à un niveau supérieur apparaîtront ici.' ),
		'resolved'  => array( 'Aucun dossier traité', 'L\'historique des décisions prises apparaîtra ici.' ),
		'dismissed' => array( 'Aucun dossier classé sans suite', 'Les signalements écartés après examen apparaîtront ici.' ),
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

	protected function eyebrow(): string {
		return 'Postelio · Activité';
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
		$out = $this->header( 'Modération', 'Signalements et contenus à examiner, du plus urgent au moins urgent.' );

		$res = Rest::call( 'GET', '/postelio/v1/moderation/cases', array( 'status' => $queue, 'page' => $this->paged(), 'per_page' => static::PER_PAGE ) );
		if ( 403 === $res['status'] ) {
			return $out . Ui::empty_state( 'Accès restreint', 'Votre profil ne permet pas d\'accéder à la file de modération.', '', 'shield', true );
		}
		if ( 200 !== $res['status'] || ! is_array( $res['data'] ) ) {
			return $out . Ui::alert( 'La file de modération est momentanément indisponible.', 'warning' );
		}

		$items = (array) ( $res['data']['data'] ?? array() );
		$total = (int) ( $res['data']['meta']['pagination']['total'] ?? count( $items ) );

		$tabs = array();
		foreach ( self::QUEUES as $key => $label ) {
			$tabs[] = array( 'label' => $label, 'url' => $this->url( $this->slug(), array( 'status' => $key ) ), 'active' => $key === $queue, 'count' => $key === $queue ? $total : null );
		}
		$out .= $this->toolbar( Ui::tabs( $tabs, 'Files de modération' ) );

		if ( empty( $items ) ) {
			$e      = self::EMPTY[ $queue ];
			$action = current_user_can( Menu::CAP_ADMIN ) ? Ui::button( 'Réglages de modération', $this->url( 'postelio-settings', array( 'tab' => 'moderation' ) ), '', true ) : '';
			return $out . Ui::empty_state( $e[0], $e[1], $action, 'shield', true );
		}

		// Tri visuel par priorité (la file reste celle de l'API : aucune logique métier).
		$order = array( 'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3 );
		usort( $items, static fn( $a, $b ) => ( $order[ (string) ( ( (array) $a )['priority'] ?? 'medium' ) ] ?? 2 ) <=> ( $order[ (string) ( ( (array) $b )['priority'] ?? 'medium' ) ] ?? 2 ) );

		$out .= Ui::queue_open();
		foreach ( $items as $c ) {
			$out .= $this->queue_item( (array) $c, $queue );
		}
		$out .= Ui::queue_close();
		$out .= Ui::pager( $this->url( $this->slug(), array( 'status' => $queue ) ), $this->paged(), static::PER_PAGE, $total );
		return $out;
	}

	/** @param array<string,mixed> $c */
	private function queue_item( array $c, string $queue ): string {
		$prio  = (string) ( $c['priority'] ?? 'medium' );
		$risk  = (string) ( $c['risk_level'] ?? 'medium' );
		$rtype = (string) ( $c['resource_type'] ?? '' );
		$pm    = self::LEVELS[ $prio ] ?? array( ucfirst( $prio ), 'neutral' );
		$rm    = self::LEVELS[ $risk ] ?? array( ucfirst( $risk ), 'neutral' );
		$n     = (int) ( $c['reports_count'] ?? 0 );
		$since = (string) ( $c['created_at'] ?? ( $c['opened_at'] ?? '' ) );

		$meta = Ui::text( $n . ( $n > 1 ? ' signalements' : ' signalement' ), false, true )
			. Ui::badge( 'Risque ' . mb_strtolower( $rm[0] ), $rm[1] )
			. ( '' !== $since ? Ui::text( 'Ouvert ' . Fmt::relative( $since ), false, true ) : '' )
			. Ui::text( '' !== (string) ( $c['assigned_to'] ?? '' ) ? 'Assigné : ' . (string) $c['assigned_to'] : 'Non assigné', false, true );

		return Ui::queue_item(
			$prio,
			$pm[0],
			self::RESOURCES[ $rtype ] ?? ucfirst( $rtype ),
			$this->origin_label( (string) ( $c['origin'] ?? '' ) ),
			$meta,
			$this->quick_actions( (string) ( $c['uuid'] ?? '' ), $queue )
		);
	}

	private function origin_label( string $origin ): string {
		$map = array( 'user_report' => 'Signalement d\'un utilisateur', 'automatic' => 'Détection automatique', 'admin' => 'Ouvert par l\'administration', 'provider' => 'Remonté par le partenaire' );
		return $map[ $origin ] ?? ( '' !== $origin ? ucfirst( $origin ) : 'Origine inconnue' );
	}

	private function quick_actions( string $uuid, string $queue ): string {
		$items = array();
		if ( '' !== $uuid && ! in_array( $queue, array( 'resolved', 'dismissed' ), true ) ) {
			if ( current_user_can( 'pst_decide_report' ) ) {
				$items[] = Ui::action_button( 'pst_admin_mod_assign', array( 'uuid' => $uuid ), 'M\'assigner' );
			}
			if ( current_user_can( 'pst_moderate_content' ) ) {
				$items[] = Ui::action_button( 'pst_admin_mod_resolve', array( 'uuid' => $uuid ), 'Traiter sans action', '', 'Marquer ce dossier comme traité, sans action sur le contenu ?' );
			}
		}
		return $this->view_link( $uuid, 'Examiner' ) . Ui::menu( $items );
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
		$out   = $this->header( $title, 'File : ' . ( self::QUEUES[ $status ] ?? ucfirst( $status ) ) . ' · priorité ' . mb_strtolower( $pm[0] ), $this->back_link( 'Retour à la file' ), 'Postelio · Modération' );
		$out  .= Ui::cols_open() . Ui::col_open();

		$out .= Ui::card_open( 'Contexte' ) . Ui::kv_present( array(
			'Ressource concernée' => self::RESOURCES[ $rtype ] ?? ucfirst( $rtype ),
			'Priorité'            => Ui::html( Ui::badge( $pm[0], $pm[1], true ) ),
			'Niveau de risque'    => Ui::html( Ui::badge( $rm[0], $rm[1] ) ),
			'Origine'             => $this->origin_label( (string) ( $c['origin'] ?? '' ) ),
			'Signalements reçus'  => (int) ( $c['reports_count'] ?? 0 ),
			'Assigné à'           => (string) ( $c['assigned_to'] ?? '' ),
			'Ouvert le'           => '' !== (string) ( $c['created_at'] ?? '' ) ? Fmt::datetime( $c['created_at'] ) : '',
		) ) . Ui::details( 'Détails techniques', Ui::kv( array(
			'Type de ressource'    => Ui::text( '' !== $rtype ? $rtype : 'inconnu', false, true ),
			'Référence ressource'  => Ui::text( (string) ( $c['resource_uuid'] ?? '' ), false, true ),
			'Référence dossier'    => Ui::text( $uuid, false, true ),
		), true ) ) . Ui::card_close();

		$rows = array();
		foreach ( (array) ( $c['events'] ?? array() ) as $e ) {
			$e      = (array) $e;
			$rows[] = array(
				Ui::meta( (string) ( $e['event'] ?? 'Événement' ), (string) ( $e['actor_role'] ?? '' ) ),
				Ui::text( trim( (string) ( $e['action'] ?? '' ) . ' ' . (string) ( $e['decision'] ?? '' ) ) ),
				Ui::text( (string) ( $e['note'] ?? '' ) ),
				Ui::text( Fmt::datetime( $e['at'] ?? '' ), false, true ),
			);
		}
		$out .= Ui::card_open( 'Historique', 'Journal non modifiable.', '', 'bo-card--flush' ) . Ui::table( array( 'Événement', 'Décision', 'Note', 'Quand' ), $rows, 'Aucun événement.' ) . Ui::card_close();

		$out .= Ui::col_close() . Ui::col_open();
		$out .= Ui::card_open( 'Décision', '', '', 'bo-card--aside' );
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
