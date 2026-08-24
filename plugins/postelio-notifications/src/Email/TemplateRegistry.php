<?php
/**
 * Catalogue de templates e-mail V1. Chaque template sépare subject / preheader / body /
 * CTA / variables. Le rendu V1 est **texte** (WpMailProvider) ; une déclinaison HTML
 * responsive (branding #17324D / #FF6B6B) viendra sans changer les appels.
 *
 * Aucun HTML de confiance : les variables sont insérées telles quelles dans un corps
 * texte. Le Router ne fournit QUE des variables sûres (jamais motif interne, note, id SQL,
 * token). Le CTA est une URL de deep-link construite par le client (pas d'action par GET).
 *
 * @package Postelio\Notifications\Email
 */

namespace Postelio\Notifications\Email;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_NOTIFICATIONS_TESTING' ) ) {
		exit;
	}
}

final class TemplateRegistry {

	/**
	 * @return array<string, array{subject:string, preheader:string, body:string, cta:string}>
	 */
	public static function templates(): array {
		return array(
			'application_received' => array(
				'subject'   => 'Votre candidature à « {job_title} » est bien reçue',
				'preheader' => 'Confirmation de candidature',
				'body'      => "Bonjour {recipient_name},\n\nVotre candidature au poste « {job_title} » chez {company_name} a bien été enregistrée. Vous pouvez suivre son avancement depuis votre espace Postelio.",
				'cta'       => 'Voir ma candidature',
			),
			'new_application' => array(
				'subject'   => 'Nouvelle candidature — {job_title}',
				'preheader' => 'Un candidat a postulé',
				'body'      => "Bonjour {recipient_name},\n\nVous avez reçu une nouvelle candidature pour l'offre « {job_title} ». Consultez-la depuis votre espace recruteur.",
				'cta'       => 'Voir la candidature',
			),
			'application_selected' => array(
				'subject'   => 'Bonne nouvelle : votre candidature a été retenue',
				'preheader' => 'Candidature retenue',
				'body'      => "Bonjour {recipient_name},\n\nVotre candidature au poste « {job_title} » chez {company_name} a été retenue. {company_name} reviendra vers vous pour la suite.",
				'cta'       => 'Voir ma candidature',
			),
			'application_rejected' => array(
				'subject'   => 'Suite donnée à votre candidature « {job_title} »',
				'preheader' => 'Mise à jour de votre candidature',
				'body'      => "Bonjour {recipient_name},\n\nVotre candidature au poste « {job_title} » chez {company_name} n'a pas été retenue cette fois-ci. Nous vous souhaitons bonne chance dans vos recherches et vous invitons à consulter d'autres offres sur Postelio.",
				'cta'       => 'Voir d\'autres offres',
			),
			'new_message' => array(
				'subject'   => 'Nouveau message sur Postelio',
				'preheader' => 'Vous avez reçu un message',
				'body'      => "Bonjour {recipient_name},\n\nVous avez reçu un nouveau message concernant « {job_title} ». Connectez-vous pour le lire et y répondre.",
				'cta'       => 'Lire le message',
			),
			'interview_proposed' => array(
				'subject'   => 'Proposition d\'entretien — {job_title}',
				'preheader' => 'Une action est nécessaire',
				'body'      => "Bonjour {recipient_name},\n\n{company_name} vous propose un entretien pour le poste « {job_title} ».\n\n• Date : {date_label}\n• Fuseau : {timezone}\n• Durée : {duration} min\n• Type : {type_label}\n{location_block}\n\nUne action est nécessaire : merci de confirmer, refuser ou proposer un autre créneau depuis Postelio.\nRéférence entretien : {interview_ref}",
				'cta'       => 'Voir et répondre',
			),
			'interview_confirmed_proof' => array(
				'subject'   => 'Confirmation de votre entretien — {job_title}',
				'preheader' => 'Votre rendez-vous est confirmé',
				'body'      => "Bonjour {recipient_name},\n\nVotre entretien pour « {job_title} » chez {company_name} est confirmé.\n\n• Date : {date_label}\n• Fuseau : {timezone}\n• Durée : {duration} min\n• Type : {type_label}\n{location_block}\n\nConservez cet e-mail comme confirmation de rendez-vous.\nRéférence entretien : {interview_ref}",
				'cta'       => 'Voir l\'entretien',
			),
			'interview_declined' => array(
				'subject'   => 'Entretien décliné — {job_title}',
				'preheader' => 'Le candidat a décliné',
				'body'      => "Bonjour {recipient_name},\n\nLe candidat a décliné la proposition d'entretien pour « {job_title} ». Vous pouvez proposer un autre créneau depuis votre espace.",
				'cta'       => 'Voir l\'entretien',
			),
			'interview_rescheduled' => array(
				'subject'   => 'Nouveau créneau pour votre entretien — {job_title}',
				'preheader' => 'Votre entretien a été reprogrammé',
				'body'      => "Bonjour {recipient_name},\n\nL'entretien pour « {job_title} » chez {company_name} a été reprogrammé.\n\n• Nouvelle date : {date_label}\n• Fuseau : {timezone}\n• Durée : {duration} min\n• Type : {type_label}\n{location_block}\n\nUne nouvelle confirmation peut être nécessaire.\nRéférence entretien : {interview_ref}",
				'cta'       => 'Voir et confirmer',
			),
			'interview_cancelled' => array(
				'subject'   => 'Entretien annulé — {job_title}',
				'preheader' => 'Votre rendez-vous a été annulé',
				'body'      => "Bonjour {recipient_name},\n\nL'entretien pour « {job_title} » chez {company_name} prévu le {date_label} a été annulé. Vous pouvez consulter le détail depuis Postelio.",
				'cta'       => 'Voir l\'entretien',
			),
			'interview_reminder' => array(
				'subject'   => 'Rappel : entretien {job_title} — {date_label}',
				'preheader' => 'Votre entretien approche',
				'body'      => "Bonjour {recipient_name},\n\nPetit rappel de votre entretien pour « {job_title} » chez {company_name}.\n\n• Date : {date_label}\n• Fuseau : {timezone}\n• Type : {type_label}\n{location_block}\n\nRéférence entretien : {interview_ref}",
				'cta'       => 'Voir l\'entretien',
			),
			'job_expiring' => array(
				'subject'   => 'Votre offre « {job_title} » expire bientôt',
				'preheader' => 'Pensez à renouveler',
				'body'      => "Bonjour {recipient_name},\n\nVotre offre « {job_title} » arrive à expiration. Vous pouvez la gérer ou la renouveler depuis votre espace recruteur.",
				'cta'       => 'Gérer l\'offre',
			),
			'job_expired' => array(
				'subject'   => 'Votre offre « {job_title} » a expiré',
				'preheader' => 'Offre expirée',
				'body'      => "Bonjour {recipient_name},\n\nVotre offre « {job_title} » a expiré et n'est plus visible des candidats. Vous pouvez la renouveler ou la republier depuis votre espace.",
				'cta'       => 'Gérer l\'offre',
			),
			'job_suspended' => array(
				'subject'   => 'Votre offre « {job_title} » a été suspendue',
				'preheader' => 'Offre suspendue',
				'body'      => "Bonjour {recipient_name},\n\nVotre offre « {job_title} » a été suspendue. Pour en savoir plus, consultez votre espace recruteur ou contactez le support.",
				'cta'       => 'Gérer l\'offre',
			),
			'job_renewed' => array(
				'subject'   => 'Votre offre « {job_title} » a été renouvelée',
				'preheader' => 'Offre de nouveau en ligne',
				'body'      => "Bonjour {recipient_name},\n\nVotre offre « {job_title} » a été renouvelée et est de nouveau visible des candidats jusqu'au {new_expiration}.",
				'cta'       => 'Voir l\'offre',
			),
			'company_verified' => array(
				'subject'   => 'Votre entreprise {company_name} est vérifiée',
				'preheader' => 'Vérification confirmée',
				'body'      => "Bonjour {recipient_name},\n\nBonne nouvelle : l'entreprise {company_name} est désormais vérifiée sur Postelio. Vous pouvez publier vos offres.",
				'cta'       => 'Voir mon entreprise',
			),
			'company_rejected' => array(
				'subject'   => 'Vérification de {company_name} : information',
				'preheader' => 'Vérification non aboutie',
				'body'      => "Bonjour {recipient_name},\n\nLa vérification de l'entreprise {company_name} n'a pas pu aboutir. Vous pouvez soumettre à nouveau une demande depuis votre espace.",
				'cta'       => 'Voir mon entreprise',
			),
			'company_suspended' => array(
				'subject'   => 'Votre entreprise {company_name} a été suspendue',
				'preheader' => 'Action requise',
				'body'      => "Bonjour {recipient_name},\n\nL'entreprise {company_name} a été suspendue sur Postelio. Vos offres ne sont plus visibles. Consultez votre espace ou contactez le support.",
				'cta'       => 'Voir mon entreprise',
			),
		);
	}

	public static function exists( string $template ): bool {
		return isset( self::templates()[ $template ] );
	}

	/**
	 * Construit un EmailMessage à partir d'un template + variables sûres.
	 *
	 * @param array<string, string> $vars  Variables de contenu (déjà sûres).
	 */
	public static function render( string $template, string $to, string $to_name, string $cta_url, array $vars ): ?EmailMessage {
		$all = self::templates();
		if ( ! isset( $all[ $template ] ) ) {
			return null;
		}
		$tpl                   = $all[ $template ];
		$vars['recipient_name'] = $vars['recipient_name'] ?? ( '' !== $to_name ? $to_name : 'bonjour' );

		$subject   = self::interpolate( $tpl['subject'], $vars );
		$preheader = self::interpolate( $tpl['preheader'], $vars );
		$body      = self::interpolate( $tpl['body'], $vars );

		return new EmailMessage( $to, $subject, $body, $to_name, $preheader, $tpl['cta'], $cta_url, array( 'template' => $template ) );
	}

	/**
	 * Remplace les jetons {clef} par les variables ; les jetons inconnus deviennent vides.
	 *
	 * @param array<string, string> $vars
	 */
	private static function interpolate( string $tpl, array $vars ): string {
		return (string) preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( array $m ) use ( $vars ): string {
				return isset( $vars[ $m[1] ] ) ? (string) $vars[ $m[1] ] : '';
			},
			$tpl
		);
	}
}
