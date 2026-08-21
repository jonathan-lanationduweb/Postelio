<?php
/**
 * Journalisation applicative légère (vers debug.log si WP_DEBUG_LOG).
 *
 * Ne remplace pas l'audit log (métier/sécurité) : sert au diagnostic technique.
 *
 * @package Postelio\Core\Log
 */

namespace Postelio\Core\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logger {

	public const DEBUG   = 'debug';
	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	/**
	 * @param array<string, mixed> $context
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		$line = sprintf( '[postelio][%s] %s', $level, $message );
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );
	}

	/** @param array<string, mixed> $context */
	public static function info( string $message, array $context = array() ): void {
		self::log( self::INFO, $message, $context );
	}

	/** @param array<string, mixed> $context */
	public static function warning( string $message, array $context = array() ): void {
		self::log( self::WARNING, $message, $context );
	}

	/** @param array<string, mixed> $context */
	public static function error( string $message, array $context = array() ): void {
		self::log( self::ERROR, $message, $context );
	}
}
