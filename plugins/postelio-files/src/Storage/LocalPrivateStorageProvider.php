<?php
/**
 * Stockage local PRIVÉ (V1). Base hors des chemins publics servis, protégée en plus
 * par un `.htaccess` (deny) et un `index.php` de silence. Les clés sont assainies
 * (aucune traversée de chemin possible). Aucune logique spécifique à Windows :
 * on s'appuie sur les fonctions de chemin PHP/WordPress (portable Linux).
 *
 * @package Postelio\Files\Storage
 */

namespace Postelio\Files\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class LocalPrivateStorageProvider implements StorageProvider {

	private string $base;

	public function __construct( string $base_dir ) {
		$this->base = rtrim( wp_normalize_path( $base_dir ), '/' );
	}

	public function name(): string {
		return 'local';
	}

	/**
	 * Empêche toute traversée : on ne garde que des segments sûrs et on vérifie que
	 * le chemin résolu reste dans la base.
	 */
	private function resolve( string $key ): ?string {
		$key = wp_normalize_path( $key );
		$key = ltrim( $key, '/' );
		if ( '' === $key || false !== strpos( $key, '..' ) || preg_match( '#(^|/)\.+(/|$)#', $key ) ) {
			return null;
		}
		// Autorise uniquement des caractères de clé attendus (segments/./-/_).
		if ( ! preg_match( '#^[A-Za-z0-9_\-/\.]+$#', $key ) ) {
			return null;
		}
		$path = $this->base . '/' . $key;
		$path = wp_normalize_path( $path );
		if ( 0 !== strpos( $path, $this->base . '/' ) ) {
			return null;
		}
		return $path;
	}

	public function put( string $source_path, string $key ): bool {
		$dest = $this->resolve( $key );
		if ( null === $dest ) {
			return false;
		}
		$dir = dirname( $dest );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// @ : erreurs traitées par le retour booléen.
		if ( is_uploaded_file( $source_path ) ) {
			return @move_uploaded_file( $source_path, $dest ) || @copy( $source_path, $dest );
		}
		return @copy( $source_path, $dest );
	}

	/** @return resource|null */
	public function open_read( string $key ) {
		$path = $this->resolve( $key );
		if ( null === $path || ! is_file( $path ) ) {
			return null;
		}
		$fh = @fopen( $path, 'rb' );
		return false !== $fh ? $fh : null;
	}

	public function exists( string $key ): bool {
		$path = $this->resolve( $key );
		return null !== $path && is_file( $path );
	}

	public function size( string $key ): int {
		$path = $this->resolve( $key );
		return ( null !== $path && is_file( $path ) ) ? (int) filesize( $path ) : 0;
	}

	public function delete( string $key ): bool {
		$path = $this->resolve( $key );
		if ( null === $path || ! is_file( $path ) ) {
			return false;
		}
		return @unlink( $path );
	}

	/**
	 * Crée la base privée + protections (deny .htaccess, index.php). Idempotent.
	 */
	public function ensure_protected(): void {
		if ( ! is_dir( $this->base ) ) {
			wp_mkdir_p( $this->base );
		}
		$ht = $this->base . '/.htaccess';
		if ( ! is_file( $ht ) ) {
			file_put_contents(
				$ht,
				"# Postelio — stockage privé : aucun accès HTTP direct\n"
				. "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
			);
		}
		$idx = $this->base . '/index.php';
		if ( ! is_file( $idx ) ) {
			file_put_contents( $idx, "<?php // Silence is golden.\n" );
		}
	}

	public function base_dir(): string {
		return $this->base;
	}
}
