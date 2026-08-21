<?php
/**
 * Maintient à jour le NOM d'entreprise dénormalisé dans les offres lors d'un
 * `company.updated`.
 *
 * Stratégie retenue (documentée) : le presenter lit TOUJOURS le nom courant via
 * `CompanyDirectory` (source de vérité) ; `pst_company_name` n'est qu'un cache/repli.
 * Cet écouteur rafraîchit ce cache pour éviter toute divergence dans des lectures
 * directes de meta. `pst_company_id`/`pst_company_uuid` (identifiants) ne changent
 * jamais.
 *
 * @package Postelio\Jobs\Integration
 */

namespace Postelio\Jobs\Integration;

use Postelio\Companies\Api\CompanyDirectory;
use Postelio\Core\Events;
use Postelio\Jobs\Jobs\JobRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CompanyRenameSync {

	private Events $events;

	public function __construct( Events $events ) {
		$this->events = $events;
	}

	public function register(): void {
		$this->events->on( 'company.updated', array( $this, 'on_company_updated' ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function on_company_updated( $payload = array() ): void {
		$payload    = is_array( $payload ) ? $payload : array();
		$company_id = (int) ( $payload['company_id'] ?? 0 );
		if ( $company_id <= 0 ) {
			return;
		}
		$name = CompanyDirectory::name_of( $company_id );
		if ( null === $name ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'      => \Postelio\Jobs\Cpt\JobPostType::TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => JobRepository::META_COMPANY_ID,
				'meta_value'     => $company_id,
			)
		);
		foreach ( $ids as $id ) {
			update_post_meta( (int) $id, 'pst_company_name', $name );
		}
	}
}
