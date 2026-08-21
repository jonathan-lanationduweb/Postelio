<?php
/**
 * Contrôleur REST de base : enveloppe de réponse et gestion d'erreurs communes.
 *
 * Les plugins métier étendront cette classe. Ici, seuls les endpoints transversaux
 * l'utilisent (health, version, config, me).
 *
 * @package Postelio\Core\Rest
 */

namespace Postelio\Core\Rest;

use Postelio\Core\ApiError;
use Postelio\Core\Errors;
use Postelio\Core\Log\Logger;
use Postelio\Core\Support\Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Controller {

	/**
	 * Enregistre les routes du contrôleur.
	 */
	abstract public function register_routes(): void;

	protected function namespace(): string {
		return POSTELIO_REST_NAMESPACE;
	}

	/**
	 * Réponse de succès `{ data, meta }`.
	 *
	 * @param mixed                $data
	 * @param array<string, mixed> $meta
	 */
	protected function ok( $data, array $meta = array(), int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( Response::ok( $data, $meta ), $status );
	}

	/**
	 * Réponse brute (déjà enveloppée), utile pour la pagination.
	 *
	 * @param array<string, mixed> $envelope
	 */
	protected function raw( array $envelope, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( $envelope, $status );
	}

	/**
	 * Réponse d'erreur `{ error: { code, message, details } }`.
	 */
	protected function fail( ApiError $error ): \WP_REST_Response {
		return new \WP_REST_Response( $error->to_envelope(), $error->http_status() );
	}

	/**
	 * Exécute un handler en convertissant les ApiError (et toute exception) en
	 * réponse normalisée.
	 *
	 * @param callable $handler fn(\WP_REST_Request): \WP_REST_Response
	 */
	protected function guarded( callable $handler ): callable {
		return function ( \WP_REST_Request $request ) use ( $handler ): \WP_REST_Response {
			try {
				return $handler( $request );
			} catch ( ApiError $e ) {
				return $this->fail( $e );
			} catch ( \Throwable $e ) {
				Logger::error( 'Exception REST non gérée', array( 'message' => $e->getMessage() ) );
				return new \WP_REST_Response(
					Errors::envelope( 'server_error', __( 'Erreur interne.', 'postelio-core' ) ),
					500
				);
			}
		};
	}
}
