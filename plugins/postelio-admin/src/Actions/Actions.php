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
		$r = \Postelio\Admin\Support\Contracts::rest( 'POST', '/postelio/v1/moderation/cases/' . $uuid . '/decision', array(), array( 'action' => 'escalate' ) );
		return 200 === $r['status'] ? array( 'pst_msg' => 'moderated' ) : array( 'pst_err' => 'failed' );
	}

	/** @param array<string,string> $args */
	private function redirect( string $page, array $args ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
