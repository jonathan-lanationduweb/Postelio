<?php
/**
 * Contrat d'analyse antivirus/contenu. AUCUN service réel n'est branché en V1.
 * Un provider futur (ClamAV, service cloud…) implémentera cette interface et sera
 * branché via le filtre `postelio/files/scanner`.
 *
 * @package Postelio\Files\Scan
 */

namespace Postelio\Files\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

interface FileScanner {

	/**
	 * Analyse un fichier. Retourne le statut résultant : 'ready' (sain) ou
	 * 'quarantined' (suspect).
	 */
	public function scan( string $path, string $mime ): string;
}
