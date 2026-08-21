<?php
/**
 * Abstraction de stockage de fichiers (décision D4). Les autres plugins ne
 * manipulent JAMAIS de chemin disque : ils passent par le service/façade files,
 * qui délègue au provider actif. Un `S3StorageProvider` pourra être branché plus
 * tard sans changer les contrats métier.
 *
 * @package Postelio\Files\Storage
 */

namespace Postelio\Files\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

interface StorageProvider {

	/** Identifiant du provider (ex. "local", "s3"). */
	public function name(): string;

	/** Déplace/copie un fichier source (chemin temporaire) vers la clé de stockage. */
	public function put( string $source_path, string $key ): bool;

	/** Ouvre un flux de lecture (resource) pour la clé, ou null si absent. @return resource|null */
	public function open_read( string $key );

	public function exists( string $key ): bool;

	public function size( string $key ): int;

	public function delete( string $key ): bool;
}
