<?php
/**
 * Modération : file visuelle consommant l'API de modération existante (GET /moderation/cases),
 * donc SANS dupliquer la logique ni lire les tables. Les actions (assigner / résoudre /
 * escalader) passent par les endpoints de modération (capabilities appliquées).
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Contracts;
use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ModerationPage extends Page {

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_VIEW;
	}

	protected function body(): string {
		if ( ! Contracts::module_active( 'moderation' ) ) {
			return Ui::header( 'Modération', 'Back-office Postelio' )
				. Ui::empty_state( 'Module indisponible', 'Le module Modération n\'est pas actif.', '🛡️' );
		}
		$view = $this->current( 'view' );
		if ( '' !== $view ) {
			return $this->detail( $view );
		}
		$status   = $this->current( 'status', 'open' );
		$priority = $this->current( 'priority' );
		$paged    = $this->paged();

		$query = array( 'status' => $status, 'page' => $paged, 'per_page' => 20 );
		if ( '' !== $priority ) {
			$query['priority'] = $priority;
		}
		$res = Contracts::rest( 'GET', '/postelio/v1/moderation/cases', $query );

		$out = Ui::toolbar( 'Modération', 'File de traitement des signalements et contenus à examiner.' );

		if ( 403 === $res['status'] ) {
			return $out . Ui::empty_state( 'Accès restreint', 'Votre profil ne permet pas d\'accéder à la file de modération.', '🔒' );
		}
		if ( 200 !== $res['status'] || ! is_array( $res['data'] ) ) {
			return $out . Ui::alert( 'La file de modération est momentanément indisponible.', 'warning' );
		}

		$out .= Ui::tabs( array(
			array( 'label' => 'À traiter', 'url' => $this->url( 'postelio-moderation', array( 'status' => 'open' ) ), 'active' => 'open' === $status ),
			array( 'label' => 'En cours', 'url' => $this->url( 'postelio-moderation', array( 'status' => 'in_review' ) ), 'active' => 'in_review' === $status ),
			array( 'label' => 'Escaladés', 'url' => $this->url( 'postelio-moderation', array( 'status' => 'escalated' ) ), 'active' => 'escalated' === $status ),
			array( 'label' => 'Résolus', 'url' => $this->url( 'postelio-moderation', array( 'status' => 'resolved' ) ), 'active' => 'resolved' === $status ),
			array( 'label' => 'Ignorés', 'url' => $this->url( 'postelio-moderation', array( 'status' => 'dismissed' ) ), 'active' => 'dismissed' === $status ),
		) );

		$items = (array) ( $res['data']['data'] ?? array() );
		$total = (int) ( $res['data']['meta']['pagination']['total'] ?? count( $items ) );

		$rows = array();
		foreach ( $items as $c ) {
			$rows[] = $this->row( (array) $c, $status );
		}
		$out .= Ui::table( array( 'Ressource', 'Priorité', 'Risque', 'Origine', 'Signalements', 'Assigné à', 'Actions' ), $rows, 'Aucun dossier dans cette file.' );
		$out .= Ui::pager( $this->url( 'postelio-moderation', array( 'status' => $status ) ), $paged, 20, $total );
		return $out;
	}

	/** @param array<string,mixed> $c @return array<int,string> */
	private function row( array $c, string $status ): array {
		$prio  = (string) ( $c['priority'] ?? 'medium' );
		$risk  = (string) ( $c['risk_level'] ?? 'medium' );
		$res_t = (string) ( $c['resource_type'] ?? '' );
		$assigned = $c['assigned_to'] ?? null;

		$resource = Ui::text( $res_t, true ) . '<br><span class="pst-admin-table__muted">' . esc_html( mb_substr( (string) ( $c['resource_uuid'] ?? '' ), 0, 8 ) ) . '…</span>';
		return array(
			$resource,
			Ui::badge( ucfirst( $prio ), $prio ),
			Ui::badge( ucfirst( $risk ), $risk ),
			Ui::text( (string) ( $c['origin'] ?? '—' ), false, true ),
			Ui::text( (string) ( (int) ( $c['reports_count'] ?? 0 ) ), false, true ),
			Ui::text( $assigned ? (string) $assigned : '—', false, true ),
			$this->actions( (string) ( $c['uuid'] ?? '' ), $status ),
		);
	}

	private function actions( string $uuid, string $status ): string {
		$h = '<div class="pst-admin-actions"><a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-moderation', array( 'view' => $uuid ) ) ) . '">Voir</a>';
		if ( '' !== $uuid && ! in_array( $status, array( 'resolved', 'dismissed' ), true ) ) {
			if ( current_user_can( 'pst_decide_report' ) ) {
				$h .= Ui::action_button( 'pst_admin_mod_assign', array( 'uuid' => $uuid ), 'M\'assigner', '' );
			}
			if ( current_user_can( 'pst_moderate_content' ) ) {
				$h .= Ui::action_button( 'pst_admin_mod_resolve', array( 'uuid' => $uuid ), 'Résoudre', 'primary', 'Résoudre ce dossier sans action supplémentaire ?' );
			}
		}
		return $h . '</div>';
	}

	/** Détail complet d'une case : contexte + historique + actions contextuelles. */
	private function detail( string $uuid ): string {
		$back = '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-moderation' ) ) . '">← File</a>';
		$res  = Contracts::rest( 'GET', '/postelio/v1/moderation/cases/' . $uuid );
		if ( 200 !== $res['status'] || ! is_array( $res['data'] ) ) {
			return Ui::header( 'Dossier de modération', 'Modération', $back ) . Ui::empty_state( 'Introuvable', 'Dossier introuvable ou accès refusé.', '🛡️' );
		}
		$c      = (array) ( $res['data']['data'] ?? array() );
		$status = (string) ( $c['status'] ?? '' );
		$rtype  = (string) ( $c['resource_type'] ?? '' );

		$out  = Ui::header( 'Dossier ' . mb_substr( $uuid, 0, 8 ), 'Ressource : ' . $rtype, $back );
		$out .= '<div class="pst-admin-cols"><div>';

		// Contexte
		$out .= Ui::card_open( 'Contexte' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Type de ressource</dt><dd>' . esc_html( $rtype ) . '</dd>';
		$out .= '<dt>Ressource</dt><dd>' . esc_html( mb_substr( (string) ( $c['resource_uuid'] ?? '' ), 0, 12 ) ) . '…</dd>';
		$out .= '<dt>Statut</dt><dd>' . Ui::badge( $status, 'open' === $status ? 'info' : 'neutral', true ) . '</dd>';
		$out .= '<dt>Priorité</dt><dd>' . Ui::badge( (string) ( $c['priority'] ?? 'medium' ), (string) ( $c['priority'] ?? 'medium' ) ) . '</dd>';
		$out .= '<dt>Risque</dt><dd>' . Ui::badge( (string) ( $c['risk_level'] ?? 'medium' ), (string) ( $c['risk_level'] ?? 'medium' ) ) . '</dd>';
		$out .= '<dt>Origine</dt><dd>' . esc_html( (string) ( $c['origin'] ?? '—' ) ) . '</dd>';
		$out .= '<dt>Signalements</dt><dd>' . esc_html( (string) (int) ( $c['reports_count'] ?? 0 ) ) . '</dd>';
		$out .= '<dt>Assigné à</dt><dd>' . esc_html( $c['assigned_to'] ? (string) $c['assigned_to'] : '—' ) . '</dd>';
		$out .= '</dl>' . Ui::card_close();

		// Historique (append-only)
		$erows = array();
		foreach ( (array) ( $c['events'] ?? array() ) as $e ) {
			$e       = (array) $e;
			$erows[] = array(
				Ui::text( (string) ( $e['event'] ?? '' ), true ),
				Ui::text( (string) ( $e['actor_role'] ?? '' ), false, true ),
				Ui::text( trim( (string) ( $e['action'] ?? '' ) . ' ' . (string) ( $e['decision'] ?? '' ) ) ?: '—', false, true ),
				Ui::text( (string) ( $e['note'] ?? '' ) ?: '—' ),
				Ui::text( substr( (string) ( $e['at'] ?? '' ), 0, 16 ), false, true ),
			);
		}
		$out .= Ui::card_open( 'Historique' ) . Ui::table( array( 'Événement', 'Rôle', 'Décision', 'Note', 'Quand' ), $erows, 'Aucun événement.' ) . Ui::card_close();
		$out .= '</div><div>';

		// Actions contextuelles
		$out .= Ui::card_open( 'Actions' );
		if ( in_array( $status, array( 'resolved', 'dismissed' ), true ) ) {
			$out .= '<p class="pst-admin-stat__sub">Dossier clôturé.</p>';
		} else {
			$out .= '<div class="pst-admin-actions" style="flex-direction:column;align-items:stretch">';
			if ( current_user_can( 'pst_decide_report' ) ) {
				$out .= Ui::action_button( 'pst_admin_mod_assign', array( 'uuid' => $uuid ), 'M\'assigner', '' );
			}
			if ( current_user_can( 'pst_moderate_content' ) ) {
				// Actions modérateur communes
				$out .= Ui::action_button( 'pst_admin_mod_resolve', array( 'uuid' => $uuid ), 'Résoudre (sans action)', 'primary' );
				$out .= Ui::action_button( 'pst_admin_mod_dismiss', array( 'uuid' => $uuid ), 'Ignorer', '' );
				$out .= Ui::action_button( 'pst_admin_mod_escalate', array( 'uuid' => $uuid ), 'Escalader', '' );
				$out .= Ui::action_button( 'pst_admin_mod_warning', array( 'uuid' => $uuid ), 'Avertir', '' );
				// Actions contextuelles selon la ressource
				if ( in_array( $rtype, array( 'skill', 'external_job', 'job' ), true ) ) {
					$out .= Ui::action_button( 'pst_admin_mod_hide', array( 'uuid' => $uuid ), 'Masquer le contenu', 'danger', 'Masquer ce contenu ?' );
					$out .= Ui::action_button( 'pst_admin_mod_unhide', array( 'uuid' => $uuid ), 'Restaurer le contenu', '' );
				}
				if ( 'conversation' === $rtype ) {
					$out .= Ui::action_button( 'pst_admin_mod_close', array( 'uuid' => $uuid ), 'Fermer la conversation', 'danger' );
				}
				// Actions admin (l'endpoint applique la garde de capability)
				if ( current_user_can( 'pst_manage_platform' ) ) {
					if ( 'job' === $rtype ) {
						$out .= Ui::action_button( 'pst_admin_mod_suspend_job', array( 'uuid' => $uuid ), 'Suspendre l\'offre', 'danger', 'Suspendre l\'offre concernée ?' );
					}
					if ( 'company' === $rtype ) {
						$out .= Ui::action_button( 'pst_admin_mod_suspend_company', array( 'uuid' => $uuid ), 'Suspendre l\'entreprise', 'danger', 'Suspendre l\'entreprise ?' );
					}
				}
			}
			$out .= '</div>';

			// Note interne
			if ( current_user_can( 'pst_moderate_content' ) ) {
				$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
				$out .= '<input type="hidden" name="action" value="pst_admin_mod_note"><input type="hidden" name="uuid" value="' . esc_attr( $uuid ) . '">';
				$out .= wp_nonce_field( 'pst_admin_mod_note', '_pstnonce', true, false );
				$out .= '<textarea name="note" rows="2" style="width:100%;border:1px solid var(--pst-border);border-radius:8px;padding:8px" placeholder="Note interne…"></textarea>';
				$out .= '<button class="pst-btn pst-btn--sm" type="submit" style="margin-top:6px">Ajouter une note</button></form>';
			}
		}
		$out .= Ui::card_close();
		$out .= '</div></div>';
		return $out;
	}
}
