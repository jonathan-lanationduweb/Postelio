<?php
/**
 * Endpoints fichiers (namespace `postelio/v1`).
 *
 *  POST   /me/files/cv                 (upload — candidat + e-mail vérifié)
 *  GET    /me/files/cv                 (liste des CV actifs)
 *  GET    /me/files/cv/{uuid}          (métadonnées)
 *  POST   /me/files/cv/{uuid}/primary  (définir principal)
 *  DELETE /me/files/cv/{uuid}          (retrait logique)
 *  GET    /files/{uuid}/view           (aperçu PDF inline, streaming sécurisé)
 *  GET    /files/{uuid}/download        (téléchargement, streaming sécurisé)
 *
 * Aucune URL disque : accès uniquement via ces routes contrôlées.
 *
 * @package Postelio\Files\Files
 */

namespace Postelio\Files\Files;

use Postelio\Core\ApiError;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Files\Storage\StorageProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FileController extends Controller {

	private const UUID = '(?P<uuid>[0-9a-fA-F-]{36})';

	private CvService $cv;
	private FileRepository $files;
	private StorageProvider $storage;

	public function __construct( CvService $cv, FileRepository $files, StorageProvider $storage ) {
		$this->cv      = $cv;
		$this->files   = $files;
		$this->storage = $storage;
	}

	public function register_routes(): void {
		$ns = $this->namespace();

		register_rest_route( $ns, '/me/files/cv', array(
			array( 'methods' => 'POST', 'permission_callback' => Guard::require_all( 'pst_manage_own_cv', 'pst_email_verified' ), 'callback' => $this->guarded( array( $this, 'upload' ) ) ),
			array( 'methods' => 'GET', 'permission_callback' => Guard::require_cap( 'pst_manage_own_cv' ), 'callback' => $this->guarded( array( $this, 'list' ) ) ),
		) );
		register_rest_route( $ns, '/me/files/cv/' . self::UUID, array(
			array( 'methods' => 'GET', 'permission_callback' => Guard::require_cap( 'pst_manage_own_cv' ), 'callback' => $this->guarded( array( $this, 'get_one' ) ) ),
			array( 'methods' => 'DELETE', 'permission_callback' => Guard::require_cap( 'pst_manage_own_cv' ), 'callback' => $this->guarded( array( $this, 'delete' ) ) ),
		) );
		register_rest_route( $ns, '/me/files/cv/' . self::UUID . '/primary', array(
			'methods' => 'POST', 'permission_callback' => Guard::require_cap( 'pst_manage_own_cv' ), 'callback' => $this->guarded( array( $this, 'set_primary' ) ),
		) );

		// Streaming (aperçu / téléchargement) — authentifié, autorisation fine interne.
		register_rest_route( $ns, '/files/' . self::UUID . '/view', array(
			'methods' => 'GET', 'permission_callback' => Guard::require_cap( 'read' ), 'callback' => array( $this, 'stream_view' ),
		) );
		register_rest_route( $ns, '/files/' . self::UUID . '/download', array(
			'methods' => 'GET', 'permission_callback' => Guard::require_cap( 'read' ), 'callback' => array( $this, 'stream_download' ),
		) );
	}

	// --- CV (JSON) ---------------------------------------------------------

	public function upload( \WP_REST_Request $r ): \WP_REST_Response {
		$files = $r->get_file_params();
		$file  = $files['file'] ?? $files['cv'] ?? null;
		if ( ! is_array( $file ) ) {
			throw ApiError::validation( array( 'file' => 'Champ fichier « file » requis (multipart).' ) );
		}
		$f = $this->cv->upload( get_current_user_id(), $file );
		return $this->ok( FilePresenter::view( $f ), array(), 201 );
	}

	public function list(): \WP_REST_Response {
		return $this->ok( FilePresenter::collection( $this->cv->list( get_current_user_id() ) ) );
	}

	public function get_one( \WP_REST_Request $r ): \WP_REST_Response {
		$f = $this->cv->owned_cv_or_fail( get_current_user_id(), self::uuid( $r ) );
		return $this->ok( FilePresenter::view( $f ) );
	}

	public function set_primary( \WP_REST_Request $r ): \WP_REST_Response {
		return $this->ok( FilePresenter::view( $this->cv->set_primary( get_current_user_id(), self::uuid( $r ) ) ) );
	}

	public function delete( \WP_REST_Request $r ): \WP_REST_Response {
		return $this->ok( FilePresenter::view( $this->cv->delete( get_current_user_id(), self::uuid( $r ) ) ) );
	}

	// --- Streaming ---------------------------------------------------------

	public function stream_view( \WP_REST_Request $r ): void {
		$this->stream( self::uuid( $r ), 'inline' );
	}

	public function stream_download( \WP_REST_Request $r ): void {
		$this->stream( self::uuid( $r ), 'attachment' );
	}

	/**
	 * Diffuse le fichier après contrôle d'accès. Écrit directement la réponse binaire
	 * (headers + flux) puis termine — ne renvoie pas d'enveloppe JSON.
	 */
	private function stream( string $uuid, string $disposition ): void {
		$file = $this->files->get_by_uuid( $uuid );
		$user = get_current_user_id();

		// Accès : propriétaire, OU autorisation accordée par un autre plugin
		// (ex. applications : recruteur d'une candidature référençant ce fichier).
		$readable = null !== $file && in_array( $file['status'], array( 'ready', 'archived' ), true );
		$allowed  = $readable && (
			(int) $file['owner_user_id'] === $user
			|| true === apply_filters( 'postelio/files/authorize_download', false, $uuid, $user )
		);
		if ( ! $allowed ) {
			status_header( 404 ); // non-divulgation : UUID connu ≠ accès
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( array( 'error' => array( 'code' => 'not_found', 'message' => 'Introuvable.' ) ) );
			exit;
		}

		$stream = $this->storage->open_read( $file['storage_key'] );
		if ( ! is_resource( $stream ) ) {
			status_header( 404 );
			exit;
		}

		$size  = (int) $file['size_bytes'] ?: $this->storage->size( $file['storage_key'] );
		$name  = self::header_filename( (string) $file['original_name'] );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: default-src \'none\'; sandbox' );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode( (string) $file['original_name'] ) );

		$start = 0;
		$end   = $size - 1;
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? (string) $_SERVER['HTTP_RANGE'] : '';
		if ( $size > 0 && preg_match( '/bytes=(\d*)-(\d*)/', $range, $m ) ) {
			if ( '' !== $m[1] ) { $start = (int) $m[1]; }
			if ( '' !== $m[2] ) { $end = (int) $m[2]; }
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				fclose( $stream );
				exit;
			}
			$end = min( $end, $size - 1 );
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		} else {
			status_header( 200 );
		}
		header( 'Content-Length: ' . ( $end - $start + 1 ) );

		if ( $start > 0 ) {
			fseek( $stream, $start );
		}
		$remaining = $end - $start + 1;
		while ( $remaining > 0 && ! feof( $stream ) ) {
			$chunk = fread( $stream, (int) min( 8192, $remaining ) );
			if ( false === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore
			$remaining -= strlen( $chunk );
			flush();
		}
		fclose( $stream );
		exit;
	}

	private static function header_filename( string $name ): string {
		$ascii = preg_replace( '/[^A-Za-z0-9._-]/', '_', $name );
		return '' !== $ascii ? $ascii : 'cv.pdf';
	}

	private static function uuid( \WP_REST_Request $r ): string {
		return (string) ( $r->get_url_params()['uuid'] ?? '' );
	}
}
