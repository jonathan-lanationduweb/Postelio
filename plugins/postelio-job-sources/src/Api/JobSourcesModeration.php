<?php
/**
 * Contrat public de modération des offres EXTERNES (consommé par postelio-moderation) :
 * masquage/démasquage local. Réutilise `local_visibility` (Lot 10) — jamais de modification
 * du contenu source. Le masquage manuel survit aux resyncs (préservé côté repository).
 * Offre externe hidden → 404 public réversible ; removed → 410 (inchangé, Lot 10).
 *
 * @package Postelio\JobSources\Api
 */

namespace Postelio\JobSources\Api;

use Postelio\JobSources\Jobs\ExternalJobRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobSourcesModeration {

	public static function set_visibility( string $job_uuid, string $visibility ): bool {
		return ( new ExternalJobRepository() )->set_visibility( $job_uuid, 'hidden' === $visibility ? 'hidden' : 'visible' );
	}

	public static function hide( string $job_uuid ): bool {
		return self::set_visibility( $job_uuid, 'hidden' );
	}
	public static function unhide( string $job_uuid ): bool {
		return self::set_visibility( $job_uuid, 'visible' );
	}

	public static function is_visible( string $job_uuid ): bool {
		$row = ( new ExternalJobRepository() )->get_by_uuid( $job_uuid );
		return null !== $row && 'visible' === (string) $row['local_visibility'] && 'active' === (string) $row['sync_status'];
	}
}
