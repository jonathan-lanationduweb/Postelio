<?php
/**
 * Catalogue des capabilities (`pst_*`) et de leur affectation par rôle.
 *
 * Source de vérité : docs/backend/roles-permissions.md. SANS dépendance WordPress
 * (données pures) → testable. Note : ce sont uniquement des chaînes de capability
 * (le cadre de permission). AUCUNE logique métier n'est implémentée ici.
 *
 * @package Postelio\Core\Permissions
 */

namespace Postelio\Core\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class Capabilities {

	public const ROLE_CANDIDATE = 'postelio_candidate';
	public const ROLE_RECRUITER = 'postelio_recruiter';
	public const ROLE_ADMIN     = 'postelio_admin';
	public const ROLE_MODERATOR = 'postelio_moderator';
	public const ROLE_SUPPORT   = 'postelio_support';

	/** @return string[] */
	public static function candidate(): array {
		return array(
			'pst_edit_own_profile',
			'pst_manage_own_cv',
			'pst_upload_file',
			'pst_apply_job',
			'pst_view_own_applications',
			'pst_withdraw_own_application',
			'pst_manage_own_favorites',
			'pst_manage_own_alerts',
			'pst_follow_company',
			'pst_send_message',
			'pst_report_content',
			'pst_confirm_interview',
			'pst_reschedule_interview',
			'pst_reject_interview',
			'pst_publish_own_skill',
			'pst_manage_own_skill',
			'pst_export_own_data',
			'pst_delete_own_account',
		);
	}

	/** @return string[] */
	public static function recruiter(): array {
		return array(
			'pst_manage_own_company',
			'pst_request_company_verification',
			'pst_publish_job',
			'pst_edit_own_company_jobs',
			'pst_duplicate_job',
			'pst_renew_job',
			'pst_view_company_applications',
			'pst_change_application_status',
			'pst_manage_recruiter_notes',
			'pst_propose_interview',
			'pst_cancel_interview',
			'pst_send_message',
			'pst_report_content',
			'pst_manage_company_content',
			'pst_pay_renewal',
		);
	}

	/** Capabilities propres à l'administration (en plus des lectures globales). @return string[] */
	public static function admin_only(): array {
		return array(
			'pst_moderate_content',
			'pst_verify_company',
			'pst_suspend_account',
			'pst_suspend_company',
			'pst_manage_all_jobs',
			'pst_view_audit_log',
			'pst_manage_billing',
			'pst_manage_platform',
		);
	}

	/** @return string[] */
	public static function moderator(): array {
		return array(
			'pst_moderate_content',
			'pst_view_moderation_queue',
			'pst_decide_report',
		);
	}

	/** Lecture d'assistance, sans écriture métier ni accès CV/notes. @return string[] */
	public static function support(): array {
		return array(
			'pst_view_support',
		);
	}

	/** Toutes les capabilities connues (dédupliquées). @return string[] */
	public static function all(): array {
		$all = array_merge(
			self::candidate(),
			self::recruiter(),
			self::admin_only(),
			self::moderator(),
			self::support()
		);
		return array_values( array_unique( $all ) );
	}

	/**
	 * Capabilities affectées à un rôle Postelio donné.
	 *
	 * @return string[]
	 */
	public static function for_role( string $role ): array {
		switch ( $role ) {
			case self::ROLE_CANDIDATE:
				return self::candidate();
			case self::ROLE_RECRUITER:
				return self::recruiter();
			case self::ROLE_ADMIN:
				// L'admin cumule tout (lecture globale + administration).
				return self::all();
			case self::ROLE_MODERATOR:
				return self::moderator();
			case self::ROLE_SUPPORT:
				return self::support();
			default:
				return array();
		}
	}

	/** @return string[] Les rôles Postelio gérés par le core. */
	public static function roles(): array {
		return array(
			self::ROLE_CANDIDATE,
			self::ROLE_RECRUITER,
			self::ROLE_ADMIN,
			self::ROLE_MODERATOR,
			self::ROLE_SUPPORT,
		);
	}
}
