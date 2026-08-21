<?php
/**
 * Migration users #1 : table `wp_postelio_candidate_profiles` (1-1 avec l'utilisateur).
 *
 * Colonnes conformes à docs/backend/data-model.md#candidateprofile. Les listes
 * riches (expériences, compétences…) sont stockées en JSON (LONGTEXT) ; le Lot 02
 * ne gère que les champs de base, mais le schéma est prévu pour les lots suivants.
 *
 * @package Postelio\Users\Migrations
 */

namespace Postelio\Users\Migrations;

use Postelio\Core\Migrations\Migration;
use Postelio\Core\Migrations\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CreateCandidateProfilesTable implements Migration {

	public function version(): string {
		return '1';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'postelio_candidate_profiles';
		$collate = Migrator::charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			metier VARCHAR(150) NULL,
			metiers_alt LONGTEXT NULL,
			ville VARCHAR(150) NULL,
			rayon_km INT NULL,
			contrat VARCHAR(50) NULL,
			temps_travail VARCHAR(50) NULL,
			teletravail VARCHAR(50) NULL,
			salaire_souhaite VARCHAR(50) NULL,
			niveau_etude VARCHAR(50) NULL,
			disponibilite VARCHAR(50) NULL,
			dispo_date DATE NULL,
			statut_recherche VARCHAR(20) NULL,
			statut_visible TINYINT(1) NOT NULL DEFAULT 1,
			profile_visibility VARCHAR(20) NOT NULL DEFAULT 'recruteurs',
			alternance LONGTEXT NULL,
			mobilite LONGTEXT NULL,
			a_propos TEXT NULL,
			experiences LONGTEXT NULL,
			formations LONGTEXT NULL,
			competences_principales LONGTEXT NULL,
			competences_complementaires LONGTEXT NULL,
			langues LONGTEXT NULL,
			certifications LONGTEXT NULL,
			realisations LONGTEXT NULL,
			liens LONGTEXT NULL,
			visibility LONGTEXT NULL,
			telephone VARCHAR(40) NULL,
			photo TINYINT(1) NOT NULL DEFAULT 0,
			blocked_companies LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			date_maj DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY ville (ville),
			KEY metier (metier),
			KEY statut_recherche (statut_recherche)
		) {$collate};";

		dbDelta( $sql );
	}
}
