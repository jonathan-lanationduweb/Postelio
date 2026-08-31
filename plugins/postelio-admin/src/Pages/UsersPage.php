<?php
/**
 * Utilisateurs : liste filtrable (WordPress natif) + statut/vérification via les contrats users.
 * Les actions sensibles (suspendre/réactiver) passent par UserModeration — jamais d'écriture
 * directe dans wp_users.
 *
 * @package Postelio\Admin\Pages
 */

namespace Postelio\Admin\Pages;

use Postelio\Admin\Support\Ui;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UsersPage extends Page {

	private const PER_PAGE = 20;

	protected function capability(): string {
		return \Postelio\Admin\Menu::CAP_ADMIN;
	}

	protected function body(): string {
		$tab   = $this->current( 'tab', 'all' );
		$q     = $this->current( 's' );
		$paged = $this->paged();

		$args = array( 'number' => self::PER_PAGE, 'paged' => $paged, 'count_total' => true, 'orderby' => 'registered', 'order' => 'DESC' );
		if ( '' !== $q ) {
			$args['search']         = '*' . $q . '*';
			$args['search_columns'] = array( 'user_email', 'display_name', 'user_login' );
		}
		switch ( $tab ) {
			case 'candidates':
				$args['role'] = 'postelio_candidate';
				break;
			case 'recruiters':
				$args['role'] = 'postelio_recruiter';
				break;
			case 'suspended':
				$args['role__in']   = array( 'postelio_candidate', 'postelio_recruiter' );
				$args['meta_key']   = 'postelio_account_status';
				$args['meta_value'] = 'suspended';
				break;
			default:
				$args['role__in'] = array( 'postelio_candidate', 'postelio_recruiter' );
		}
		// La meta de statut réelle vient d'AccountService si présente.
		if ( 'suspended' === $tab && class_exists( '\\Postelio\\Users\\Users\\AccountService' ) ) {
			$args['meta_key']   = \Postelio\Users\Users\AccountService::META_STATUS;
			$args['meta_value'] = \Postelio\Users\Users\AccountService::STATUS_SUSPENDED;
		}

		$query = new \WP_User_Query( $args );
		$users = $query->get_results();
		$total = (int) $query->get_total();

		$base = $this->url( 'postelio-users' );
		$out  = Ui::header( 'Utilisateurs', 'Candidats et recruteurs de la plateforme' );
		$out .= Ui::tabs( array(
			array( 'label' => 'Tous', 'url' => $this->url( 'postelio-users', array( 'tab' => 'all' ) ), 'active' => 'all' === $tab ),
			array( 'label' => 'Candidats', 'url' => $this->url( 'postelio-users', array( 'tab' => 'candidates' ) ), 'active' => 'candidates' === $tab ),
			array( 'label' => 'Recruteurs', 'url' => $this->url( 'postelio-users', array( 'tab' => 'recruiters' ) ), 'active' => 'recruiters' === $tab ),
			array( 'label' => 'Suspendus', 'url' => $this->url( 'postelio-users', array( 'tab' => 'suspended' ) ), 'active' => 'suspended' === $tab ),
		) );
		$out .= $this->search_form( $base, $tab, $q );

		$rows = array();
		foreach ( $users as $u ) {
			$rows[] = $this->row( $u );
		}
		$out .= Ui::table(
			array( 'Utilisateur', 'Type', 'Statut', 'E-mail vérifié', 'Inscription', 'Actions' ),
			$rows,
			'Aucun utilisateur.'
		);
		$out .= Ui::pager( $this->url( 'postelio-users', array( 'tab' => $tab ) ), $paged, self::PER_PAGE, $total );
		return $out;
	}

	/** @return array<int,string> */
	private function row( \WP_User $u ): array {
		$id     = (int) $u->ID;
		$status = class_exists( '\\Postelio\\Users\\Users\\AccountService' ) ? \Postelio\Users\Users\AccountService::status( $id ) : 'active';
		$role   = class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) ? \Postelio\Users\Api\UserDirectory::role( $id ) : '';
		$verif  = class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) && \Postelio\Users\Api\UserDirectory::email_verified( $id );

		$name = '<span class="pst-admin-table__strong">' . esc_html( (string) $u->display_name ) . '</span><br><span class="pst-admin-table__muted">' . esc_html( (string) $u->user_email ) . '</span>';

		$type_map = array( 'candidate' => 'Candidat', 'recruiter' => 'Recruteur' );
		$type     = Ui::badge( $type_map[ $role ] ?? ( '' !== $role ? $role : '—' ), 'candidate' === $role ? 'info' : 'neutral' );

		$status_var = array( 'active' => 'success', 'suspended' => 'error', 'deleted' => 'neutral' );
		$status_lbl = array( 'active' => 'Actif', 'suspended' => 'Suspendu', 'deleted' => 'Supprimé' );
		$status_b   = Ui::badge( $status_lbl[ $status ] ?? $status, $status_var[ $status ] ?? 'neutral', true );

		$verif_b = Ui::badge( $verif ? 'Vérifié' : 'Non vérifié', $verif ? 'success' : 'warning' );
		$reg     = Ui::text( mysql2date( 'd/m/Y', (string) $u->user_registered ), false, true );

		$actions = $this->actions( $id, $status, $role );
		return array( $name, $type, $status_b, $verif_b, $reg, $actions );
	}

	private function actions( int $id, string $status, string $role ): string {
		if ( 'deleted' === $status || ! class_exists( '\\Postelio\\Users\\Api\\UserModeration' ) ) {
			return Ui::text( '—', false, true );
		}
		$uuid = class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) ? \Postelio\Users\Api\UserDirectory::public_uuid( $id ) : '';
		if ( '' === $uuid ) {
			return Ui::text( '—', false, true );
		}
		if ( 'suspended' === $status ) {
			return Ui::action_button( 'pst_admin_user_unsuspend', array( 'uuid' => $uuid ), 'Réactiver', 'primary' );
		}
		return Ui::action_button( 'pst_admin_user_suspend', array( 'uuid' => $uuid ), 'Suspendre', 'danger', 'Suspendre ce compte ? Ses jetons et sessions seront révoqués (réversible).' );
	}

	private function search_form( string $base, string $tab, string $q ): string {
		return '<form method="get" class="pst-admin-filters">'
			. '<input type="hidden" name="page" value="postelio-users"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">'
			. '<input type="search" name="s" value="' . esc_attr( $q ) . '" placeholder="Nom ou e-mail…">'
			. '<button class="pst-btn pst-btn--sm pst-btn--primary" type="submit">Rechercher</button></form>';
	}
}
