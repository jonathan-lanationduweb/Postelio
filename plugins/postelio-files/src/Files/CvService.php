<?php
/**
 * Logique métier des CV (fichiers de type `cv`).
 *
 * Chaque upload = une NOUVELLE ressource immuable (versionnement). Le nom physique
 * est aléatoire (non devinable) ; le nom d'origine n'est conservé que pour l'affichage.
 * Validation réelle MIME + signature PDF + taille. Suppression LOGIQUE : `archived`
 * si le CV est encore référencé par une candidature (conservation), sinon `deleted`.
 *
 * @package Postelio\Files\Files
 */

namespace Postelio\Files\Files;

use Postelio\Core\ApiError;
use Postelio\Core\Plugin as Core;
use Postelio\Files\Scan\FileScanner;
use Postelio\Files\Storage\StorageProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CvService {

	public const TYPE = 'cv';

	private StorageProvider $storage;
	private FileScanner $scanner;
	private FileRepository $files;

	public function __construct( StorageProvider $storage, FileScanner $scanner, FileRepository $files ) {
		$this->storage = $storage;
		$this->scanner = $scanner;
		$this->files   = $files;
	}

	public static function max_bytes(): int {
		$m = (int) apply_filters( 'postelio/files/max_bytes', 10 * 1024 * 1024 ); // D3 : 10 Mo
		return $m > 0 ? $m : 10 * 1024 * 1024;
	}

	/** Statuts « actifs » visibles dans le profil courant. @return string[] */
	private static function active_statuses(): array {
		return array( 'ready' );
	}

	/**
	 * Importe un CV.
	 *
	 * @param array{tmp_name:string, name:string, size:int, error:int, type?:string} $file
	 * @return array<string, mixed>
	 * @throws ApiError
	 */
	public function upload( int $owner, array $file ): array {
		if ( ! isset( $file['tmp_name'], $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || '' === $file['tmp_name'] ) {
			throw ApiError::validation( array( 'file' => 'Fichier manquant ou upload en échec.' ) );
		}
		$size = (int) ( $file['size'] ?? 0 );
		if ( $size > self::max_bytes() ) {
			throw new ApiError( 'payload_too_large', 'Fichier trop volumineux (max ' . ( self::max_bytes() / 1048576 ) . ' Mo).' );
		}
		if ( ! UploadValidator::within_size( $size, self::max_bytes() ) ) {
			throw ApiError::validation( array( 'file' => 'Fichier vide ou taille invalide.' ) );
		}
		if ( ! UploadValidator::allowed_extension( (string) $file['name'] ) ) {
			throw new ApiError( 'unsupported_media_type', 'Seuls les fichiers PDF sont acceptés.' );
		}

		$tmp = $file['tmp_name'];
		// MIME réel (jamais le Content-Type client).
		$mime = function_exists( 'finfo_open' ) ? ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $tmp ) : '';
		$head = (string) @file_get_contents( $tmp, false, null, 0, 8 );
		if ( UploadValidator::ALLOWED_MIME !== $mime || ! UploadValidator::has_pdf_signature( $head ) ) {
			throw new ApiError( 'unsupported_media_type', 'Le fichier n\'est pas un PDF valide.' );
		}

		$sha        = hash_file( 'sha256', $tmp ) ?: null;
		$stored_name = wp_generate_uuid4() . '.pdf';
		$key         = gmdate( 'Y/m' ) . '/' . $stored_name; // sharding par date (UTC)

		if ( ! $this->storage->put( $tmp, $key ) ) {
			throw new ApiError( 'server_error', 'Stockage du fichier impossible.' );
		}

		$status  = $this->scanner->scan( '', $mime ); // fichier déjà déplacé ; scan logique V1 (ready)
		$primary = 0 === $this->files->count_for_owner( $owner, self::TYPE, self::active_statuses() ); // 1er CV = principal

		$id = $this->files->insert( array(
			'owner_user_id'    => $owner,
			'type'             => self::TYPE,
			'storage_provider' => $this->storage->name(),
			'storage_key'      => $key,
			'original_name'    => UploadValidator::safe_original_name( (string) $file['name'] ),
			'stored_name'      => $stored_name,
			'mime_type'        => UploadValidator::ALLOWED_MIME,
			'size_bytes'       => $size,
			'sha256'           => $sha,
			'status'           => $status,
			'is_primary'       => $primary,
		) );

		$this->emit( 'cv.uploaded', $id, $owner );
		return $this->files->get( $id );
	}

	/** @return array<int, array<string,mixed>> */
	public function list( int $owner ): array {
		return $this->files->list_for_owner( $owner, self::TYPE, self::active_statuses() );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function set_primary( int $owner, string $uuid ): array {
		$file = $this->owned_cv_or_fail( $owner, $uuid );
		if ( 'ready' !== $file['status'] ) {
			throw new ApiError( 'invalid_transition', 'Ce CV ne peut pas être défini comme principal.' );
		}
		$this->files->set_primary( $owner, self::TYPE, (int) $file['id'] );
		$this->emit( 'cv.primary_changed', (int) $file['id'], $owner );
		return $this->files->get( (int) $file['id'] );
	}

	/**
	 * Retrait du profil : `archived` si encore référencé par une candidature
	 * (conservation), sinon `deleted` (logique).
	 *
	 * @return array<string, mixed>
	 */
	public function delete( int $owner, string $uuid ): array {
		$file       = $this->owned_cv_or_fail( $owner, $uuid );
		$referenced = (bool) apply_filters( 'postelio/files/file_is_referenced', false, $uuid );
		$status     = $referenced ? 'archived' : 'deleted';
		$this->files->update_status( (int) $file['id'], $status );
		$this->emit( 'cv.deleted', (int) $file['id'], $owner, array( 'retained' => $referenced ) );
		return $this->files->get( (int) $file['id'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function owned_cv_or_fail( int $owner, string $uuid ): array {
		$file = $this->files->get_by_uuid( $uuid );
		if ( null === $file || (int) $file['owner_user_id'] !== $owner || self::TYPE !== $file['type'] ) {
			throw ApiError::not_found();
		}
		return $file;
	}

	private function emit( string $event, int $file_id, int $owner, array $audit = array() ): void {
		Core::instance()->events()->emit(
			$event,
			array(
				'file_id'       => $file_id,
				'owner_id'      => $owner,
				'resource_type' => 'file',
				'resource_id'   => (string) $file_id,
				'audit'         => $audit,
			)
		);
	}
}
