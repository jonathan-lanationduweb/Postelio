<?php
/**
 * Déclaration DÉCOUPLÉE des besoins Notifications, côté postelio-alerts (§25-26) :
 *  - catégorie de préférences `job_alert` (via `postelio/notifications/categories`) ;
 *  - template e-mail `job_alert_digest` (via `postelio/notifications/email_templates`).
 *
 * postelio-notifications n'a AUCUNE connaissance en dur de postelio-alerts : si ce module est
 * désactivé, ni la catégorie ni le template n'existent (et l'événement de digest ne se produit
 * jamais). Une alerte est créée VOLONTAIREMENT par l'utilisateur : ce n'est pas une reco
 * marketing — mais ses préférences de canal restent respectées par Notifications.
 *
 * @package Postelio\Alerts\Integration
 */

namespace Postelio\Alerts\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NotificationsBridge {

	public function register(): void {
		add_filter( 'postelio/notifications/categories', array( $this, 'add_category' ) );
		add_filter( 'postelio/notifications/email_templates', array( $this, 'add_template' ) );
	}

	/**
	 * @param mixed $catalog
	 * @return mixed
	 */
	public function add_category( $catalog ) {
		if ( ! is_array( $catalog ) ) {
			return $catalog;
		}
		$catalog['job_alert'] = array(
			'roles'     => array( 'candidate' ),
			'marketing' => false, // alerte volontaire, transactionnelle
			'in_app'    => true,
			'email'     => true,
			'label'     => 'Alertes emploi',
		);
		return $catalog;
	}

	/**
	 * @param mixed $templates
	 * @return mixed
	 */
	public function add_template( $templates ) {
		if ( ! is_array( $templates ) ) {
			return $templates;
		}
		$templates['job_alert_digest'] = array(
			'subject'   => '{match_count} nouvelle(s) offre(s) pour votre alerte « {saved_search_name} »',
			'preheader' => 'De nouvelles offres correspondent à votre recherche',
			'body'      => "Bonjour {recipient_name},\n\n{match_count} nouvelle(s) offre(s) correspondent à votre alerte « {saved_search_name} » :\n\n{offers_block}\n\nRetrouvez toutes les offres depuis votre espace candidat Postelio.",
			'cta'       => 'Voir les offres',
		);
		return $templates;
	}
}
