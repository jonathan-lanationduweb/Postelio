<?php
/**
 * Socle REST : enregistre le namespace `postelio/v1` et les endpoints transversaux.
 *
 * @package Postelio\Core\Rest
 */

namespace Postelio\Core\Rest;

use Postelio\Core\Events;
use Postelio\Core\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Server {

	private Registry $registry;
	private Events $events;

	public function __construct( Registry $registry, Events $events ) {
		$this->registry = $registry;
		$this->events   = $events;
	}

	/**
	 * Enregistre les routes transversales du core.
	 */
	public function register_routes(): void {
		$controllers = array(
			new HealthController( $this->registry ),
			new VersionController( $this->registry ),
			new ConfigController(),
			new MeController(),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}

		// Signale aux plugins métier qu'ils peuvent enregistrer leurs routes.
		$this->events->emit( 'rest.routes_registering', array( 'namespace' => POSTELIO_REST_NAMESPACE ) );
	}
}
