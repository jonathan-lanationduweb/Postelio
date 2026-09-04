<?php
/**
 * Traitement des actions admin (admin-post). Chaque action : vérifie le NONCE, vérifie la
 * CAPABILITY serveur, puis DÉLÈGUE au contrat/service du domaine propriétaire (jamais d'écriture
 * directe). Redirige ensuite vers la page d'origine avec une notice flash.
 *
 * @package Postelio\Admin\Actions
 */

namespace Postelio\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Actions {

	/** @var array<string, array{cap:string, handler:string, redirect:string}> */
	private const MAP = array(
		'pst_admin_user_suspend'      => array( 'cap' => 'pst_suspend_account', 'handler' => 'user_suspend', 'redirect' => 'postelio-users' ),
		'pst_admin_user_unsuspend'    => array( 'cap' => 'pst_suspend_account', 'handler' => 'user_unsuspend', 'redirect' => 'postelio-users' ),
		'pst_admin_company_verify'    => array( 'cap' => 'pst_verify_company', 'handler' => 'company_verify', 'redirect' => 'postelio-companies' ),
		'pst_admin_company_reject'    => array( 'cap' => 'pst_verify_company', 'handler' => 'company_reject', 'redirect' => 'postelio-companies' ),
		'pst_admin_company_suspend'   => array( 'cap' => 'pst_suspend_company', 'handler' => 'company_suspend', 'redirect' => 'postelio-companies' ),
		'pst_admin_company_unsuspend' => array( 'cap' => 'pst_suspend_company', 'handler' => 'company_unsuspend', 'redirect' => 'postelio-companies' ),
		'pst_admin_job_suspend'       => array( 'cap' => 'pst_manage_all_jobs', 'handler' => 'job_suspend', 'redirect' => 'postelio-jobs' ),
		'pst_admin_job_unsuspend'     => array( 'cap' => 'pst_manage_all_jobs', 'handler' => 'job_unsuspend', 'redirect' => 'postelio-jobs' ),
		'pst_admin_mod_assign'        => array( 'cap' => 'pst_decide_report', 'handler' => 'mod_assign', 'redirect' => 'postelio-moderation' ),
		'pst_admin_mod_resolve'       => array( 'cap' => 'pst_moderate_content', 'handler' => 'mod_resolve', 'redirect' => 'postelio-moderation' ),
		'pst_admin_mod_escalate'      => array( 'cap' => 'pst_moderate_content', 'handler' => 'mod_escalate', 'redirect' => 'postelio-moderation' ),
		'pst_admin_mod_hide'          => array( 'cap' => 'pst_moderate_content', 'handler' => 'mod_hide', 'redirect' => 'postelio-moderation' ),
		'pst_admin_mod_unhide'        => array( 'cap' => 'pst_moderate_content', 'handler' => 'mod_unhide', 'redirect' => 'postelio-moderation' ),
		'pst_admin_mod_warning'       => array( 'cap' => 'pst_moderate_content', 'handler' => 'mod_warning', 'redirect' => 'postelio-moderation' ),
		'pst_admin_mod_close'         => array( 'cap' => 'pst_moderate_content', 'handler' => 'mod_close', 'redirect' => 'postelio-moderation' ),
		'pst_admin_mod_dismiss'       => array( 'cap' => 'pst_moderate_content', 'handler' => 'mod_dismiss', 'redirect' => 'postelio-moderation' ),
		'pst_admin_mod_note'          => array( 'cap' => 'pst_moderate_content', 'handler' => 'mod_note', 'redirect' => 'postelio-moderation' ),
		'pst_admin_mod_suspend_job'   => array( 'cap' => 'pst_moderate_content', 'handler' => 'mod_suspend_job', 'redirect' => 'postelio-moderation' ),
		'pst_admin_mod_suspend_company' => array( 'cap' => 'pst_moderate_content', 'handler' => 'mod_suspend_company', 'redirect' => 'postelio-moderation' ),
		'pst_admin_mod_suspend_user'  => array( 'cap' => 'pst_moderate_content', 'handler' => 'mod_suspend_user', 'redirect' => 'postelio-moderation' ),
		'pst_admin_billing_retry'     => array( 'cap' => 'pst_manage_billing', 'handler' => 'billing_retry', 'redirect' => 'postelio-billing' ),
		'pst_admin_skill_hide'        => array( 'cap' => 'pst_moderate_content', 'handler' => 'skill_hide', 'redirect' => 'postelio-skills' ),
		'pst_admin_skill_unhide'      => array( 'cap' => 'pst_moderate_content', 'handler' => 'skill_unhide', 'redirect' => 'postelio-skills' ),
		'pst_admin_extjob_hide'       => array( 'cap' => 'pst_moderate_content', 'handler' => 'extjob_hide', 'redirect' => 'postelio-jobs' ),
		'pst_admin_extjob_unhide'     => array( 'cap' => 'pst_moderate_content', 'handler' => 'extjob_unhide', 'redirect' => 'postelio-jobs' ),
		'pst_admin_email_test'        => array( 'cap' => 'pst_manage_platform', 'handler' => 'email_test', 'redirect' => 'postelio-notifications' ),
	);

	public function register(): void {
		foreach ( array_keys( self::MAP ) as $action ) {
			add_action( 'admin_post_' . $action, function () use ( $action ) {
				$this->dispatch( $action );
			} );
		}
	}

	private function dispatch( string $action ): void {
		$def = self::MAP[ $action ] ?? null;
		if ( null === $def ) {
			$this->redirect( 'postelio-admin', array( 'pst_err' => 'invalid' ) );
		}
		// Nonce + capability.
		if ( ! isset( $_POST['_pstnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_pstnonce'] ) ), $action ) ) {
			$this->redirect( $def['redirect'], array( 'pst_err' => 'invalid' ) );
		}
		if ( ! current_user_can( $def['cap'] ) ) {
			$this->redirect( $def['redirect'], array( 'pst_err' => 'forbidden' ) );
		}
		$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['uuid'] ) ) : '';
		$back = array( $this, $def['handler'] );
		try {
			$result = call_user_func( $back, $uuid );
		} catch ( \Throwable $e ) {
			$this->redirect( $def['redirect'], array( 'pst_err' => 'failed' ) );
		}
		$this->redirect( $def['redirect'], $result );
	}

	// --- Handlers (délégation aux contrats propriétaires) --------------------

	/** @return array<string,string> notice */
	private function user_suspend( string $uuid ): array {
		if ( ! class_exists( '\\Postelio\\Users\\Api\\UserModeration' ) ) {
			return array( 'pst_err' => 'module_absent' );
		}
		return \Postelio\Users\Api\UserModeration::suspend( $uuid, get_current_user_id() )
			? array( 'pst_msg' => 'suspended' ) : array( 'pst_err' => 'failed' );
	}
	private function user_unsuspend( string $uuid ): array {
		if ( ! class_exists( '\\Postelio\\Users\\Api\\UserModeration' ) ) {
			return array( 'pst_err' => 'module_absent' );
		}
		return \Postelio\Users\Api\UserModeration::unsuspend( $uuid, get_current_user_id() )
			? array( 'pst_msg' => 'reactivated' ) : array( 'pst_err' => 'failed' );
	}

	private function company_verify( string $uuid ): array {
		return $this->company_decision( $uuid, 'verified', 'verified' );
	}
	private function company_reject( string $uuid ): array {
		return $this->company_decision( $uuid, 'rejected', 'rejected' );
	}
	/** @return array<string,string> */
	private function company_decision( string $uuid, string $decision, string $ok_code ): array {
		if ( ! class_exists( '\\Postelio\\Companies\\Api\\CompanyDirectory' ) || ! class_exists( '\\Postelio\\Companies\\Verification\\VerificationService' ) || ! class_exists( '\\Postelio\\Companies\\Companies\\CompanyRepository' ) || ! class_exists( '\\Postelio\\Companies\\Verification\\ManualVerificationProvider' ) ) {
			return array( 'pst_err' => 'module_absent' );
		}
		$id = \Postelio\Companies\Api\CompanyDirectory::id_from_uuid( $uuid );
		if ( $id <= 0 ) {
			return array( 'pst_err' => 'failed' );
		}
		( new \Postelio\Companies\Verification\VerificationService( new \Postelio\Companies\Companies\CompanyRepository(), new \Postelio\Companies\Verification\ManualVerificationProvider() ) )
			->decide( $id, get_current_user_id(), $decision );
		return array( 'pst_msg' => $ok_code );
	}
	private function company_suspend( string $uuid ): array {
		if ( ! class_exists( '\\Postelio\\Companies\\Api\\CompanyModeration' ) ) {
			return array( 'pst_err' => 'module_absent' );
		}
		return \Postelio\Companies\Api\CompanyModeration::suspend( get_current_user_id(), $uuid, 'admin' )
			? array( 'pst_msg' => 'suspended' ) : array( 'pst_err' => 'failed' );
	}
	private function company_unsuspend( string $uuid ): array {
		if ( ! class_exists( '\\Postelio\\Companies\\Api\\CompanyModeration' ) ) {
			return array( 'pst_err' => 'module_absent' );
		}
		return \Postelio\Companies\Api\CompanyModeration::unsuspend( get_current_user_id(), $uuid )
			? array( 'pst_msg' => 'reactivated' ) : array( 'pst_err' => 'failed' );
	}

	private function job_suspend( string $uuid ): array {
		if ( ! class_exists( '\\Postelio\\Jobs\\Api\\JobModeration' ) ) {
			return array( 'pst_err' => 'module_absent' );
		}
		return \Postelio\Jobs\Api\JobModeration::suspend( get_current_user_id(), $uuid )
			? array( 'pst_msg' => 'suspended' ) : array( 'pst_err' => 'failed' );
	}
	private function job_unsuspend( string $uuid ): array {
		if ( ! class_exists( '\\Postelio\\Jobs\\Api\\JobModeration' ) ) {
			return array( 'pst_err' => 'module_absent' );
		}
		return \Postelio\Jobs\Api\JobModeration::unsuspend( get_current_user_id(), $uuid )
			? array( 'pst_msg' => 'reactivated' ) : array( 'pst_err' => 'failed' );
	}

	/** Modération : délégation via l'API REST interne (capabilities appliquées). */
	private function mod_assign( string $uuid ): array {
		$r = \Postelio\Admin\Support\Contracts::rest( 'POST', '/postelio/v1/moderation/cases/' . $uuid . '/assign' );
		return 200 === $r['status'] ? array( 'pst_msg' => 'assigned' ) : array( 'pst_err' => 'failed' );
	}
	private function mod_resolve( string $uuid ): array {
		$r = \Postelio\Admin\Support\Contracts::rest( 'POST', '/postelio/v1/moderation/cases/' . $uuid . '/decision', array(), array( 'action' => 'no_action' ) );
		return 200 === $r['status'] ? array( 'pst_msg' => 'moderated' ) : array( 'pst_err' => 'failed' );
	}
	private function mod_escalate( string $uuid ): array {
		return $this->mod_decision( $uuid, 'escalate' );
	}
	private function mod_hide( string $uuid ): array {
		return $this->mod_decision( $uuid, 'hide' );
	}
	private function mod_unhide( string $uuid ): array {
		return $this->mod_decision( $uuid, 'unhide' );
	}
	private function mod_warning( string $uuid ): array {
		return $this->mod_decision( $uuid, 'warning' );
	}
	private function mod_close( string $uuid ): array {
		return $this->mod_decision( $uuid, 'close_conversation' );
	}
	private function mod_dismiss( string $uuid ): array {
		return $this->mod_decision( $uuid, 'dismiss' );
	}
	private function mod_suspend_job( string $uuid ): array {
		return $this->mod_decision( $uuid, 'suspend_job' );
	}
	private function mod_suspend_company( string $uuid ): array {
		return $this->mod_decision( $uuid, 'suspend_company' );
	}
	private function mod_suspend_user( string $uuid ): array {
		$target = $this->target_from_post();
		return $this->mod_decision( $uuid, 'suspend_user', $target );
	}
	private function mod_note( string $uuid ): array {
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['note'] ) ) : '';
		if ( '' === trim( $note ) ) {
			return array( 'pst_err' => 'invalid' );
		}
		$r = \Postelio\Admin\Support\Contracts::rest( 'POST', '/postelio/v1/moderation/cases/' . $uuid . '/note', array(), array( 'note' => $note ) );
		return 200 === $r['status'] ? array( 'pst_msg' => 'done' ) : array( 'pst_err' => 'failed' );
	}

	/** @param array<string,string>|null $target @return array<string,string> */
	private function mod_decision( string $uuid, string $action, ?array $target = null ): array {
		$body = array( 'action' => $action );
		if ( null !== $target ) {
			$body['target'] = $target;
		}
		$r = \Postelio\Admin\Support\Contracts::rest( 'POST', '/postelio/v1/moderation/cases/' . $uuid . '/decision', array(), $body );
		if ( 200 === $r['status'] ) {
			return array( 'pst_msg' => 'moderated' );
		}
		if ( 403 === $r['status'] ) {
			return array( 'pst_err' => 'forbidden' );
		}
		return array( 'pst_err' => 'failed' );
	}

	/** @return array<string,string>|null */
	private function target_from_post(): ?array {
		$type = isset( $_POST['target_type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_type'] ) ) : '';
		$uuid = isset( $_POST['target_uuid'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_uuid'] ) ) : '';
		return ( '' !== $type && '' !== $uuid ) ? array( 'type' => $type, 'uuid' => $uuid ) : null;
	}

	private function billing_retry( string $uuid ): array {
		$r = \Postelio\Admin\Support\Contracts::rest( 'POST', '/postelio/v1/billing/admin/orders/' . $uuid . '/retry-fulfillment' );
		return 200 === $r['status'] ? array( 'pst_msg' => 'retried' ) : array( 'pst_err' => 'failed' );
	}

	private function skill_hide( string $uuid ): array {
		if ( ! class_exists( '\\Postelio\\Skills\\Api\\SkillModeration' ) ) {
			return array( 'pst_err' => 'module_absent' );
		}
		return \Postelio\Skills\Api\SkillModeration::hide( $uuid ) ? array( 'pst_msg' => 'moderated' ) : array( 'pst_err' => 'failed' );
	}
	private function skill_unhide( string $uuid ): array {
		if ( ! class_exists( '\\Postelio\\Skills\\Api\\SkillModeration' ) ) {
			return array( 'pst_err' => 'module_absent' );
		}
		return \Postelio\Skills\Api\SkillModeration::unhide( $uuid ) ? array( 'pst_msg' => 'reactivated' ) : array( 'pst_err' => 'failed' );
	}
	private function extjob_hide( string $uuid ): array {
		if ( ! class_exists( '\\Postelio\\JobSources\\Api\\JobSourcesModeration' ) ) {
			return array( 'pst_err' => 'module_absent' );
		}
		return \Postelio\JobSources\Api\JobSourcesModeration::hide( $uuid ) ? array( 'pst_msg' => 'moderated' ) : array( 'pst_err' => 'failed' );
	}
	private function extjob_unhide( string $uuid ): array {
		if ( ! class_exists( '\\Postelio\\JobSources\\Api\\JobSourcesModeration' ) ) {
			return array( 'pst_err' => 'module_absent' );
		}
		return \Postelio\JobSources\Api\JobSourcesModeration::unhide( $uuid ) ? array( 'pst_msg' => 'reactivated' ) : array( 'pst_err' => 'failed' );
	}

	/**
	 * E-mail de test : MÊME provider que les notifications, destinataire = adresse de l'admin
	 * courant (jamais une adresse arbitraire), hors file (aucun retry). Le détail est affiché dans
	 * « Service e-mail » (résultat conservé par le module Notifications).
	 */
	private function email_test( string $uuid ): array {
		unset( $uuid );
		if ( ! class_exists( '\\Postelio\\Notifications\\Api\\NotificationDirectory' ) || ! method_exists( '\\Postelio\\Notifications\\Api\\NotificationDirectory', 'send_test' ) ) {
			return array( 'pst_err' => 'module_absent' );
		}
		$res = \Postelio\Notifications\Api\NotificationDirectory::send_test( get_current_user_id() );
		return $res->ok ? array( 'pst_msg' => 'email_test_ok' ) : array( 'pst_err' => 'email_test_failed' );
	}

	/** @param array<string,string> $args */
	private function redirect( string $page, array $args ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
