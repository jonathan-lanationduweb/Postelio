<?php
/**
 * Utilisateurs : liste filtrable (requête WordPress native) enrichie par les contrats du module
 * Users (statut de compte, rôle, vérification d'e-mail, UUID public), et détail. Les actions
 * sensibles (suspendre / réactiver) sont déléguées à `Users\Api\UserModeration` — jamais d'écriture
 * directe dans `wp_users`. En liste, l'e-mail est masqué ; les compteurs d'activité non exposés par
 * une façade affichent « — ».
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

final class UsersScreen extends ListScreen {

	private const ACCOUNT = '\\Postelio\\Users\\Users\\AccountService';
	private const DIR     = '\\Postelio\\Users\\Api\\UserDirectory';
	private const MOD     = '\\Postelio\\Users\\Api\\UserModeration';

	/** @var array<string,array{0:string,1:string}> statut => [libellé, variante] */
	private const STATUSES = array(
		'active'    => array( 'Actif', 'success' ),
		'suspended' => array( 'Suspendu', 'error' ),
		'deleted'   => array( 'Supprimé', 'neutral' ),
	);

	protected function capability(): string {
		return Menu::CAP_ADMIN;
	}

	protected function eyebrow(): string {
		return 'Postelio · Gestion';
	}

	protected function slug(): string {
		return 'postelio-users';
	}

	protected function index(): string {
		$tab   = $this->current( 'tab', 'all' );
		$q     = $this->current( 's' );
		$paged = $this->paged();
		$users = Data::user_counts();

		$query = new \WP_User_Query( $this->query_args( $tab, $q, $paged ) );
		$total = (int) $query->get_total();

		$out  = $this->header( 'Utilisateurs', 'Candidats et recruteurs inscrits sur la plateforme.' );
		$out .= $this->toolbar(
			$this->status_tabs(
				array( 'candidates' => 'Candidats', 'recruiters' => 'Recruteurs', 'suspended' => 'Suspendus' ),
				array(
					'total'      => $users['candidates'] + $users['recruiters'],
					'candidates' => $users['candidates'],
					'recruiters' => $users['recruiters'],
					'suspended'  => Data::suspended_users(),
				),
				$tab
			),
			$this->search( $q, 'Nom ou e-mail…', array( 'tab' => $tab ) )
		);

		$rows = array();
		foreach ( $query->get_results() as $u ) {
			$rows[] = $this->row( $u );
		}
		$out .= Ui::table( array( 'Utilisateur', 'E-mail', 'Statut', 'Inscription', '' ), $rows, 'Aucun utilisateur ne correspond', '' !== $q ? 'Essayez un autre nom ou une autre adresse.' : '' );
		$out .= $this->pagination( $total, array( 'tab' => $tab, 's' => $q ) );
		return $out;
	}

	/** @return array<string,mixed> */
	private function query_args( string $tab, string $q, int $paged ): array {
		$args = array(
			'number'      => static::PER_PAGE,
			'paged'       => $paged,
			'count_total' => true,
			'orderby'     => 'registered',
			'order'       => 'DESC',
			'role__in'    => array( 'postelio_candidate', 'postelio_recruiter' ),
		);
		if ( '' !== $q ) {
			$args['search']         = '*' . $q . '*';
			$args['search_columns'] = array( 'user_email', 'display_name', 'user_login' );
		}
		if ( 'candidates' === $tab ) {
			$args['role'] = 'postelio_candidate';
			unset( $args['role__in'] );
		} elseif ( 'recruiters' === $tab ) {
			$args['role'] = 'postelio_recruiter';
			unset( $args['role__in'] );
		} elseif ( 'suspended' === $tab && class_exists( self::ACCOUNT ) ) {
			$args['meta_key']   = constant( self::ACCOUNT . '::META_STATUS' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value'] = constant( self::ACCOUNT . '::STATUS_SUSPENDED' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}
		return $args;
	}

	/** @return array<int,string> */
	private function row( \WP_User $u ): array {
		$id     = (int) $u->ID;
		$status = $this->status_of( $id );
		$role   = (string) Data::facade( self::DIR, 'role', array( $id ), '' );
		$verif  = (bool) Data::facade( self::DIR, 'email_verified', array( $id ), false );
		$meta   = self::STATUSES[ $status ] ?? array( ucfirst( $status ), 'neutral' );

		return array(
			Ui::entity( (string) $u->display_name, $this->role_label( $role ) ),
			Ui::meta( Ui::mask_email( (string) $u->user_email ), $verif ? 'Vérifié' : 'Non vérifié' ),
			Ui::badge( $meta[0], $meta[1], true ),
			Ui::text( Fmt::date( (string) $u->user_registered ), false, true ),
			$this->actions( $id, $status, true ),
		);
	}

	private function status_of( int $id ): string {
		return (string) Data::facade( self::ACCOUNT, 'status', array( $id ), 'active' );
	}

	private function role_label( string $role ): string {
		$map = array( 'candidate' => 'Candidat', 'recruiter' => 'Recruteur', 'moderator' => 'Modérateur', 'admin' => 'Administrateur' );
		return $map[ $role ] ?? Fmt::or_dash( $role );
	}

	/** Liste : « Voir » + menu ⋯ ; détail : boutons en en-tête. */
	private function actions( int $id, string $status, bool $in_list ): string {
		$uuid = (string) Data::facade( self::DIR, 'public_uuid', array( $id ), '' );
		if ( '' === $uuid ) {
			return '';
		}
		$mod = '';
		if ( 'deleted' !== $status && Data::has( self::MOD ) && current_user_can( 'pst_suspend_account' ) ) {
			$mod = 'suspended' === $status
				? Ui::action_button( 'pst_admin_user_unsuspend', array( 'uuid' => $uuid ), 'Réactiver le compte', $in_list ? '' : 'primary' )
				: Ui::action_button( 'pst_admin_user_suspend', array( 'uuid' => $uuid ), 'Suspendre le compte', 'danger', 'Suspendre ce compte ? Ses jetons et sessions seront révoqués (action réversible).' );
		}
		if ( $in_list ) {
			return '<div class="bo-actions">' . $this->view_link( $uuid ) . Ui::menu( array( $mod ) ) . '</div>';
		}
		return $mod;
	}

	protected function detail( string $uuid ): string {
		$id = (int) Data::facade( self::DIR, 'id_from_public_uuid', array( $uuid ), 0 );
		$u  = $id > 0 ? get_userdata( $id ) : false;
		if ( ! $u ) {
			return $this->not_found( 'Utilisateur', 'Ce compte n\'existe pas ou plus.' );
		}
		$status = $this->status_of( $id );
		$role   = (string) Data::facade( self::DIR, 'role', array( $id ), '' );
		$verif  = (bool) Data::facade( self::DIR, 'email_verified', array( $id ), false );
		$meta   = self::STATUSES[ $status ] ?? array( ucfirst( $status ), 'neutral' );

		$out  = $this->header( (string) $u->display_name, $this->role_label( $role ) . ' · inscrit le ' . Fmt::date( (string) $u->user_registered ), $this->back_link() . $this->actions( $id, $status, false ), 'Postelio · Utilisateur' );
		$out .= Ui::cols_open() . Ui::col_open();

		$out .= Ui::card_open( 'Compte' );
		$out .= Ui::identity( (string) $u->display_name, (string) $u->user_email, '', false, Ui::badge( $this->role_label( $role ), 'candidate' === $role ? 'info' : 'neutral' ) . Ui::badge( $meta[0], $meta[1], true ) . ( $verif ? Ui::badge( 'E-mail vérifié', 'success' ) : Ui::badge( 'E-mail non vérifié', 'warning' ) ) );
		$out .= Ui::details( 'Détails techniques', Ui::kv( array( 'Référence publique' => Ui::text( $uuid, false, true ), 'Identifiant WordPress' => Ui::text( (string) $id, false, true ) ), true ) ) . Ui::card_close();

		$out .= $this->profile_card( $id, $role );
		$out .= Ui::col_close() . Ui::col_open();
		$out .= $this->activity_card( $id );
		$out .= Ui::col_close() . Ui::cols_close();
		return $out;
	}

	private function profile_card( int $id, string $role ): string {
		if ( 'candidate' === $role && Data::has( '\\Postelio\\Users\\Profiles\\CandidateProfileRepository' ) ) {
			$p = ( new \Postelio\Users\Profiles\CandidateProfileRepository() )->get_by_user( $id );
			if ( ! $p ) {
				return Ui::card_open( 'Profil candidat' ) . Ui::help( 'Profil non renseigné par le candidat.' ) . Ui::card_close();
			}
			$vis = array( 'public' => 'Public', 'recruiters' => 'Recruteurs vérifiés', 'private' => 'Privé' );
			return Ui::card_open( 'Profil candidat' ) . Ui::kv( array(
				'Métier'      => Ui::text( Fmt::or_dash( $p['metier'] ?? '' ) ),
				'Ville'       => Ui::text( Fmt::or_dash( $p['ville'] ?? '' ) ),
				'Recherche'   => Ui::text( Fmt::or_dash( $p['statut_recherche'] ?? '' ) ),
				'Visibilité'  => Ui::text( $vis[ (string) ( $p['profile_visibility'] ?? '' ) ] ?? Fmt::or_dash( $p['profile_visibility'] ?? '' ) ),
			) ) . Ui::card_close();
		}
		if ( 'recruiter' === $role ) {
			$cid  = (int) Data::facade( '\\Postelio\\Companies\\Api\\CompanyDirectory', 'company_of_user', array( $id ), 0 );
			$name = $cid > 0 ? (string) Data::facade( '\\Postelio\\Companies\\Api\\CompanyDirectory', 'name_of', array( $cid ), '' ) : '';
			$val  = '' !== $name ? Ui::text( $name, true ) : Ui::text( '—', false, true );
			return Ui::card_open( 'Entreprise' ) . Ui::kv( array( 'Rattachement' => $val ) )
				. ( '' !== $name ? '' : Ui::help( 'Ce recruteur n\'est rattaché à aucune entreprise.' ) ) . Ui::card_close();
		}
		return Ui::card_open( 'Profil' ) . Ui::help( 'Aucun profil détaillé pour ce type de compte.' ) . Ui::card_close();
	}

	private function activity_card( int $id ): string {
		$interviews = Data::facade( '\\Postelio\\Interviews\\Api\\InterviewDirectory', 'upcoming_count', array( $id ), null );
		$notifs     = Data::facade( '\\Postelio\\Notifications\\Api\\NotificationDirectory', 'unread_count', array( $id ), null );
		$skills     = Data::facade( '\\Postelio\\Skills\\Api\\SkillDirectory', 'published_for_user', array( $id ), null );

		$out = Ui::card_open( 'Activité', '', '', 'bo-card--aside' ) . Ui::kv( array(
			'Entretiens à venir'      => Ui::text( Fmt::count( null === $interviews ? null : (int) $interviews ) ),
			'Notifications non lues'  => Ui::text( Fmt::count( null === $notifs ? null : (int) $notifs ) ),
			'Savoir-faire publiés'    => Ui::text( Fmt::count( is_array( $skills ) ? count( $skills ) : null ) ),
			'Candidatures'            => Ui::text( '—', false, true ),
			'Conversations'           => Ui::text( '—', false, true ),
		), true );
		$out .= Ui::help( '« — » : compteur non exposé par une façade de lecture.' );
		return $out . Ui::card_close();
	}
}
