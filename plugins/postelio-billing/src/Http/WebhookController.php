<?php
/**
 * Endpoint webhook provider : POST /billing/webhook/stripe.
 *
 * Route PUBLIQUE authentifiée CRYPTOGRAPHIQUEMENT (signature), sans nonce ni session ni
 * Bearer utilisateur. Corps BRUT + signature vérifiés par le provider. Le retour navigateur
 * (success_url) n'écrit jamais rien : SEUL ce webhook confirme un paiement.
 *
 * @package Postelio\Billing\Http
 */

namespace Postelio\Billing\Http;

use Postelio\Billing\Provider\ProviderRegistry;
use Postelio\Billing\Webhook\WebhookProcessor;
use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WebhookController extends Controller {

	private WebhookProcessor $processor;

	public function __construct( WebhookProcessor $processor ) {
		$this->processor = $processor;
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace(), '/billing/webhook/stripe', array(
			'methods'             => 'POST',
			'permission_callback' => Guard::public_access(),
			'callback'            => array( $this, 'handle' ),
		) );
	}

	public function handle( \WP_REST_Request $r ): \WP_REST_Response {
		$raw = (string) $r->get_body();
		$sig = (string) ( $r->get_header( 'stripe_signature' ) ?? '' );

		$event = ProviderRegistry::resolve()->verify_webhook( $raw, $sig );
		if ( null === $event ) {
			// Signature invalide / corps illisible : 400 (Stripe réessaiera si transitoire).
			return new \WP_REST_Response( array( 'error' => array( 'code' => 'invalid_signature' ) ), 400 );
		}

		$result = $this->processor->handle( $event );
		// Toujours 2xx pour un événement bien signé (évite les retentatives Stripe inutiles) ;
		// l'idempotence/observabilité est gérée côté store d'événements.
		return new \WP_REST_Response( array( 'received' => true, 'status' => $result['status'] ), 200 );
	}
}
