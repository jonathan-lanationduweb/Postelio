<?php
/**
 * Règles de validation d'upload (pures → testables) : extension, taille, signature
 * PDF, sécurité du nom. La vérification MIME réelle (finfo) est faite par le service
 * sur le fichier temporaire.
 *
 * @package Postelio\Files\Files
 */

namespace Postelio\Files\Files;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class UploadValidator {

	public const ALLOWED_MIME = 'application/pdf';

	/** L'extension FINALE est-elle .pdf ? (rejette cv.pdf.php, cv.exe, etc.) */
	public static function allowed_extension( string $filename ): bool {
		$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		return 'pdf' === $ext;
	}

	public static function within_size( int $size, int $max ): bool {
		return $size > 0 && $size <= $max;
	}

	/** Le contenu commence-t-il par la signature PDF (%PDF-) ? */
	public static function has_pdf_signature( string $head ): bool {
		return 0 === strncmp( $head, '%PDF-', 5 );
	}

	/**
	 * Nom d'origine sûr pour l'AFFICHAGE (jamais utilisé comme nom physique).
	 * Retire toute composante de chemin et neutralise la traversée.
	 */
	public static function safe_original_name( string $name ): string {
		$name = str_replace( '\\', '/', $name ); // uniformise, puis basename retire le chemin
		$name = wp_basename( $name );
		$name = sanitize_file_name( $name );
		if ( strlen( $name ) > 200 ) {
			$name = substr( $name, -200 );
		}
		return '' !== $name ? $name : 'cv.pdf';
	}
}
