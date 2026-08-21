<?php
/**
 * Exception applicative portant un code d'erreur interne stable.
 *
 * Les contrôleurs REST lèvent une ApiError ; le socle la convertit en réponse
 * `{ error: { code, message, details } }` avec le bon statut HTTP.
 *
 * @package Postelio\Core
 */

namespace Postelio\Core;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class ApiError extends \RuntimeException {

	private string $error_code;

	/** @var array<string, mixed> */
	private array $details;

	/**
	 * @param string               $code    Code interne (voir Errors::MAP).
	 * @param string               $message Message lisible.
	 * @param array<string, mixed> $details Détails structurés (ex. champ→raison).
	 */
	public function __construct( string $code, string $message = '', array $details = array() ) {
		$this->error_code = Errors::is_known( $code ) ? $code : 'server_error';
		$this->details    = $details;
		parent::__construct( '' !== $message ? $message : $this->error_code, Errors::http_status( $this->error_code ) );
	}

	public function error_code(): string {
		return $this->error_code;
	}

	public function http_status(): int {
		return Errors::http_status( $this->error_code );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function details(): array {
		return $this->details;
	}

	/**
	 * @return array{error: array{code:string, message:string, details:array}}
	 */
	public function to_envelope(): array {
		return Errors::envelope( $this->error_code, $this->getMessage(), $this->details );
	}

	// Fabriques pratiques ----------------------------------------------------

	public static function unauthenticated( string $message = 'Authentification requise.' ): self {
		return new self( 'unauthenticated', $message );
	}

	public static function forbidden( string $message = 'Action non autorisée.' ): self {
		return new self( 'forbidden', $message );
	}

	public static function not_found( string $message = 'Ressource introuvable.' ): self {
		return new self( 'not_found', $message );
	}

	/**
	 * @param array<string, mixed> $details
	 */
	public static function validation( array $details, string $message = 'Données invalides.' ): self {
		return new self( 'validation_error', $message, $details );
	}
}
