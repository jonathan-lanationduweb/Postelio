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
		$status   = $this->current( 'status', 'open' );
		$priority = $this->current( 'priority' );
		$paged    = $this->paged();

		$query = array( 'status' => $status, 'page' => $paged, 'per_page' => 20 );
		if ( '' !== $priority ) {
			$query['priority'] = $priority;
		}
		$res = Contracts::rest( 'GET', '/postelio/v1/moderation/cases', $query );

		$out = Ui::header( 'Modération', 'File de traitement des signalements et cases préventives' );

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
		if ( '' === $uuid || in_array( $status, array( 'resolved', 'dismissed' ), true ) ) {
			return Ui::text( '—', false, true );
		}
		$h = '<div class="pst-admin-actions">';
		if ( current_user_can( 'pst_decide_report' ) ) {
			$h .= Ui::action_button( 'pst_admin_mod_assign', array( 'uuid' => $uuid ), 'M\'assigner', '' );
		}
		if ( current_user_can( 'pst_moderate_content' ) ) {
			$h .= Ui::action_button( 'pst_admin_mod_resolve', array( 'uuid' => $uuid ), 'Résoudre', 'primary', 'Résoudre ce dossier sans action supplémentaire ?' );
			$h .= Ui::action_button( 'pst_admin_mod_escalate', array( 'uuid' => $uuid ), 'Escalader', 'danger' );
		}
		return $h . '</div>';
	}
}
