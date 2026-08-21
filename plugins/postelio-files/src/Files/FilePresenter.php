<?php
/**
 * Présentation des fichiers. N'expose JAMAIS : storage_key, stored_name, chemin
 * disque, owner_user_id interne, détails scanner. Seul l'UUID public identifie le
 * fichier ; les URLs d'accès sont des routes REST (jamais d'URL disque).
 *
 * @package Postelio\Files\Files
 */

namespace Postelio\Files\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FilePresenter {

	/**
	 * @param array<string, mixed> $f
	 * @return array<string, mixed>
	 */
	public static function view( array $f ): array {
		$base = '/' . POSTELIO_REST_NAMESPACE . '/files/' . $f['public_uuid'];
		return array(
			'uuid'         => $f['public_uuid'],
			'type'         => $f['type'],
			'name'         => $f['original_name'],
			'mime_type'    => $f['mime_type'],
			'size_bytes'   => (int) $f['size_bytes'],
			'is_primary'   => (bool) $f['is_primary'],
			'status'       => $f['status'],
			'created_at'   => $f['created_at'],
			'links'        => array(
				'view'     => $base . '/view',
				'download' => $base . '/download',
			),
		);
	}

	/**
	 * @param array<int, array<string,mixed>> $files
	 * @return array<int, array<string,mixed>>
	 */
	public static function collection( array $files ): array {
		return array_map( array( self::class, 'view' ), $files );
	}
}
