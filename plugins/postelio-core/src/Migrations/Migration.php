<?php
/**
 * Contrat d'une migration incrémentale, idempotente et ordonnée.
 *
 * @package Postelio\Core\Migrations
 */

namespace Postelio\Core\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

interface Migration {

	/**
	 * Version de schéma apportée par cette migration (ex. "1", "2").
	 * Comparée via version_compare().
	 */
	public function version(): string;

	/**
	 * Applique la migration. Doit être idempotente (dbDelta gère les tables).
	 */
	public function up(): void;
}
