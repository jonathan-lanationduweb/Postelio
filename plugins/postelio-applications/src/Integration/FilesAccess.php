<?php
/**
 * Pont applications → files, par FILTRES (pas de dépendance de classe files→apps, ni
 * de cycle). `postelio-files` demande :
 *  - `postelio/files/file_is_referenced` : ce CV est-il utilisé par une candidature ?
 *    (empêche la suppression physique d'une pièce encore référencée) ;
 *  - `postelio/files/authorize_download` : ce recruteur peut-il lire ce CV ? (oui si une
 *    candidature de SON entreprise référence ce CV et qu'il a la capability requise).
 *
 * @package Postelio\Applications\Integration
 */

namespace Postelio\Applications\Integration;

use Postelio\Companies\Api\CompanyDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FilesAccess {

	public function register(): void {
		add_filter( 'postelio/files/file_is_referenced', array( $this, 'is_referenced' ), 10, 2 );
		add_filter( 'postelio/files/authorize_download', array( $this, 'authorize_download' ), 10, 3 );
	}

	/**
	 * @param bool   $referenced
	 * @param string $cv_uuid
	 */
	public function is_referenced( $referenced, $cv_uuid ): bool {
		if ( $referenced ) {
			return true;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'postelio_applications';
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE cv_reference = %s LIMIT 1", (string) $cv_uuid ) );
	}

	/**
	 * @param bool   $allowed
	 * @param string $cv_uuid
	 * @param int    $user_id
	 */
	public function authorize_download( $allowed, $cv_uuid, $user_id ): bool {
		if ( $allowed ) {
			return true;
		}
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! user_can( $user_id, 'pst_view_company_applications' ) ) {
			return false;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'postelio_applications';
		$company_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT company_id FROM {$table} WHERE cv_reference = %s", (string) $cv_uuid ) );
		foreach ( $company_ids as $cid ) {
			if ( CompanyDirectory::is_member( (int) $cid, $user_id ) ) {
				return true;
			}
		}
		return false;
	}
}
