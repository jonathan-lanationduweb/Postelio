<?php
/**
 * Bus d'événements interne (abstraction au-dessus des hooks WordPress).
 *
 * Convention : `postelio/<domaine>.<action>` (ex. `postelio/application.created`).
 * Les plugins émettent et écoutent ; jamais d'appel direct inter-plugins métier.
 * En plus de l'action spécifique, chaque émission déclenche une action générique
 * `postelio/event` (nom + charge utile) permettant une écoute globale (audit).
 *
 * @package Postelio\Core
 */

namespace Postelio\Core;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class Events {

	/** Préfixe des hooks WordPress dérivés des événements. */
	public const HOOK_PREFIX = 'postelio/';

	/** Hook générique déclenché pour chaque événement (écoute globale / audit). */
	public const HOOK_ANY = 'postelio/event';

	/**
	 * Normalise un nom d'événement en nom de hook WordPress.
	 * "application.created" → "postelio/application.created".
	 */
	public static function hook( string $event ): string {
		$event = trim( $event );
		if ( 0 === strncmp( $event, self::HOOK_PREFIX, strlen( self::HOOK_PREFIX ) ) ) {
			return $event;
		}
		return self::HOOK_PREFIX . $event;
	}

	/**
	 * Émet un événement.
	 *
	 * @param string               $event   Nom logique (sans préfixe).
	 * @param array<string, mixed> $payload Charge utile.
	 */
	public function emit( string $event, array $payload = array() ): void {
		$event   = self::strip_prefix( $event );
		$context = array(
			'event'      => $event,
			'payload'    => $payload,
			'emitted_at' => self::now(),
		);

		if ( function_exists( 'do_action' ) ) {
			// Action spécifique : add_action('postelio/application.created', ...).
			do_action( self::hook( $event ), $payload, $context );
			// Action générique : add_action('postelio/event', fn($event,$payload,$context)).
			do_action( self::HOOK_ANY, $event, $payload, $context );
		}
	}

	/**
	 * Écoute un événement.
	 *
	 * @param string   $event    Nom logique (sans préfixe).
	 * @param callable $callback Rappel `fn($payload, $context)`.
	 * @param int      $priority Priorité WordPress.
	 */
	public function on( string $event, callable $callback, int $priority = 10 ): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( self::hook( self::strip_prefix( $event ) ), $callback, $priority, 2 );
		}
	}

	/**
	 * Écoute TOUS les événements (utilisé par l'audit log).
	 *
	 * @param callable $callback Rappel `fn($event, $payload, $context)`.
	 * @param int      $priority Priorité WordPress.
	 */
	public function on_any( callable $callback, int $priority = 10 ): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( self::HOOK_ANY, $callback, $priority, 3 );
		}
	}

	private static function strip_prefix( string $event ): string {
		$event = trim( $event );
		if ( 0 === strncmp( $event, self::HOOK_PREFIX, strlen( self::HOOK_PREFIX ) ) ) {
			$event = substr( $event, strlen( self::HOOK_PREFIX ) );
		}
		return $event;
	}

	private static function now(): string {
		if ( function_exists( 'current_time' ) ) {
			return (string) current_time( 'mysql', true );
		}
		return gmdate( 'Y-m-d H:i:s' );
	}
}
