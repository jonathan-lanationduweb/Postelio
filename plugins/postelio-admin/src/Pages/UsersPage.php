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
		$view = $this->current( 'view' );
		if ( '' !== $view ) {
			return $this->detail( $view );
		}
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
		$uuid = class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) ? \Postelio\Users\Api\UserDirectory::public_uuid( $id ) : '';
		$h    = '<div class="pst-admin-actions">';
		if ( '' !== $uuid ) {
			$h .= '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-users', array( 'view' => $uuid ) ) ) . '">Voir</a>';
		}
		if ( '' !== $uuid && 'deleted' !== $status && class_exists( '\\Postelio\\Users\\Api\\UserModeration' ) ) {
			if ( 'suspended' === $status ) {
				$h .= Ui::action_button( 'pst_admin_user_unsuspend', array( 'uuid' => $uuid ), 'Réactiver', 'primary' );
			} else {
				$h .= Ui::action_button( 'pst_admin_user_suspend', array( 'uuid' => $uuid ), 'Suspendre', 'danger', 'Suspendre ce compte ? Ses jetons et sessions seront révoqués (réversible).' );
			}
		}
		return $h . '</div>';
	}

	/** Détail utilisateur. */
	private function detail( string $uuid ): string {
		$id = class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) ? \Postelio\Users\Api\UserDirectory::id_from_public_uuid( $uuid ) : 0;
		$u  = $id > 0 ? get_userdata( $id ) : false;
		if ( ! $u ) {
			return Ui::header( 'Utilisateur', 'Back-office Postelio' ) . Ui::empty_state( 'Introuvable', 'Cet utilisateur n\'existe pas ou plus.', '👤' );
		}
		$status = class_exists( '\\Postelio\\Users\\Users\\AccountService' ) ? \Postelio\Users\Users\AccountService::status( $id ) : 'active';
		$role   = class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) ? \Postelio\Users\Api\UserDirectory::role( $id ) : '';
		$verif  = class_exists( '\\Postelio\\Users\\Api\\UserDirectory' ) && \Postelio\Users\Api\UserDirectory::email_verified( $id );

		$back = '<a class="pst-btn pst-btn--sm" href="' . esc_url( $this->url( 'postelio-users' ) ) . '">← Liste</a>';
		$out  = Ui::header( (string) $u->display_name, 'Fiche utilisateur', $back . ' ' . $this->actions( $id, $status, $role ) );
		$out .= '<div class="pst-admin-cols">';

		// Identité
		$out .= '<div>' . Ui::card_open( 'Identité' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Nom</dt><dd>' . esc_html( (string) $u->display_name ) . '</dd>';
		$out .= '<dt>E-mail</dt><dd>' . esc_html( (string) $u->user_email ) . '</dd>';
		$out .= '<dt>Rôle</dt><dd>' . esc_html( '' !== $role ? $role : '—' ) . '</dd>';
		$out .= '<dt>UUID public</dt><dd>' . esc_html( $uuid ) . '</dd>';
		$out .= '<dt>Statut</dt><dd>' . Ui::badge( ucfirst( $status ), 'active' === $status ? 'success' : ( 'suspended' === $status ? 'error' : 'neutral' ), true ) . '</dd>';
		$out .= '<dt>E-mail vérifié</dt><dd>' . Ui::badge( $verif ? 'Oui' : 'Non', $verif ? 'success' : 'warning' ) . '</dd>';
		$out .= '<dt>Inscription</dt><dd>' . esc_html( mysql2date( 'd/m/Y', (string) $u->user_registered ) ) . '</dd>';
		$out .= '</dl>' . Ui::card_close();

		// Profil
		$out .= $this->profile_card( $id, $role ) . '</div>';

		// Activité (compteurs accessibles proprement)
		$out .= '<div>' . Ui::card_open( 'Activité' ) . '<dl class="pst-admin-kv">';
		$out .= '<dt>Entretiens à venir</dt><dd>' . esc_html( $this->count_or_dash( class_exists( '\\Postelio\\Interviews\\Api\\InterviewDirectory' ) ? \Postelio\Interviews\Api\InterviewDirectory::upcoming_count( $id ) : null ) ) . '</dd>';
		$out .= '<dt>Notifications non lues</dt><dd>' . esc_html( $this->count_or_dash( class_exists( '\\Postelio\\Notifications\\Api\\NotificationDirectory' ) ? \Postelio\Notifications\Api\NotificationDirectory::unread_count( $id ) : null ) ) . '</dd>';
		$skills = class_exists( '\\Postelio\\Skills\\Api\\SkillDirectory' ) ? count( \Postelio\Skills\Api\SkillDirectory::published_for_user( $id ) ) : null;
		$out .= '<dt>Savoir-faire publiés</dt><dd>' . esc_html( $this->count_or_dash( $skills ) ) . '</dd>';
		$out .= '<dt>Candidatures</dt><dd>' . esc_html( '—' ) . '</dd>';
		$out .= '<dt>Conversations</dt><dd>' . esc_html( '—' ) . '</dd>';
		$out .= '</dl><p class="pst-admin-stat__sub">« — » : compteur non exposé par un contrat de lecture (phase ultérieure).</p>' . Ui::card_close();
		$out .= '</div>';

		return $out . '</div>';
	}

	private function profile_card( int $id, string $role ): string {
		$h = Ui::card_open( 'Profil' );
		if ( 'candidate' === $role && class_exists( '\\Postelio\\Users\\Profiles\\CandidateProfileRepository' ) ) {
			$p = ( new \Postelio\Users\Profiles\CandidateProfileRepository() )->get_by_user( $id );
			if ( $p ) {
				$h .= '<dl class="pst-admin-kv">';
				$h .= '<dt>Métier</dt><dd>' . esc_html( (string) ( $p['metier'] ?? '—' ) ) . '</dd>';
				$h .= '<dt>Ville</dt><dd>' . esc_html( (string) ( $p['ville'] ?? '—' ) ) . '</dd>';
				$h .= '<dt>Recherche</dt><dd>' . esc_html( (string) ( $p['statut_recherche'] ?? '—' ) ) . '</dd>';
				$h .= '<dt>Visibilité</dt><dd>' . esc_html( (string) ( $p['profile_visibility'] ?? '—' ) ) . '</dd>';
				$h .= '</dl>';
			} else {
				$h .= '<p class="pst-admin-stat__sub">Profil candidat non renseigné.</p>';
			}
		} elseif ( 'recruiter' === $role ) {
			$cid = class_exists( '\\Postelio\\Companies\\Api\\CompanyDirectory' ) ? \Postelio\Companies\Api\CompanyDirectory::company_of_user( $id ) : 0;
			$h  .= '<dl class="pst-admin-kv"><dt>Entreprise</dt><dd>' . ( $cid > 0 ? esc_html( (string) \Postelio\Companies\Api\CompanyDirectory::name_of( $cid ) ) : '—' ) . '</dd></dl>';
		} else {
			$h .= '<p class="pst-admin-stat__sub">Aucun profil détaillé pour ce rôle.</p>';
		}
		return $h . Ui::card_close();
	}

	private function count_or_dash( ?int $n ): string {
		return null === $n ? '—' : (string) $n;
	}

	private function search_form( string $base, string $tab, string $q ): string {
		return '<form method="get" class="pst-admin-filters">'
			. '<input type="hidden" name="page" value="postelio-users"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">'
			. '<input type="search" name="s" value="' . esc_attr( $q ) . '" placeholder="Nom ou e-mail…">'
			. '<button class="pst-btn pst-btn--sm pst-btn--primary" type="submit">Rechercher</button></form>';
	}
}
