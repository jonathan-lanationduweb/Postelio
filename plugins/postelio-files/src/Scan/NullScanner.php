<?php
/**
 * Scanner par défaut (V1) : considère tout fichier déjà validé (MIME/taille/PDF)
 * comme sain. Ne remplace pas un antivirus ; sert de point d'extension.
 *
 * @package Postelio\Files\Scan
 */

namespace Postelio\Files\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class NullScanner implements FileScanner {

	public function scan( string $path, string $mime ): string {
		return 'ready';
	}
}
