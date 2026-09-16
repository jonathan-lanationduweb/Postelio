<?php
/**
 * Parcours de candidature GUEST (sans compte) — double opt-in.
 *
 * 1. `submit()` : un visiteur postule (prénom, nom, e-mail, CV, présélection, message,
 *    consentement). La candidature est stockée `pending_email` avec un JETON signé (haché,
 *    à durée limitée) et n'est PAS visible du recruteur. Un e-mail de confirmation est
 *    envoyé (postelio-notifications). Réponse générique (anti-énumération, anti-spam).
 * 2. `confirm()` : le clic sur le lien signé confirme l'e-mail, puis MATÉRIALISE la
 *    candidature en candidature réelle rattachée à un compte candidat — existant (rattachement
 *    sûr) ou INVITÉ (créé après consentement, réclamable par définition de mot de passe). Le
 *    CV guest est ré-attribué au compte. La confirmation déclenche le flux standard
 *    (`application.created` → notification recruteur + e-mail candidat).
 *
 * Sécurité : accès à une candidature guest UNIQUEMENT via (uuid + jeton) ; l'UUID seul ne
 * suffit pas. Présélection obligatoire réellement exigée. Rate limiting. Consentement RGPD
 * horodaté. Dédoublonnage (offre, e-mail) et via la contrainte unique (offre, candidat) à la
 * matérialisation.
 *
 * @package Postelio\Applications\Applications
 */

namespace Postelio\Applications\Applications;

use Postelio\Core\ApiError;
use Postelio\Core\Plugin as Core;
use Postelio\Jobs\Api\JobDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GuestApplicationService {

	private const RL       = '\\Postelio\\Users\\Auth\\AuthRateLimiter';
	private const CV       = '\\Postelio\\Files\\Api\\FileCvContract';
	private const ONBOARD  = '\\Postelio\\Users\\Api\\GuestOnboarding';
	private const MAILER   = '\\Postelio\\Notifications\\Api\\GuestMailer';

	private GuestApplicationRepository $guests;
	private ApplicationService $apps;

	public function __construct( GuestApplicationRepository $guests, ApplicationService $apps ) {
		$this->guests = $guests;
		$this->apps   = $apps;
	}

	private static function token_ttl(): int {
		return (int) apply_filters( 'postelio/applications/guest_token_ttl', 3 * DAY_IN_SECONDS );
	}

	/**
	 * Soumission guest. Retourne une réponse GÉNÉRIQUE (jamais l'existence d'un compte ni le
	 * jeton). Le jeton n'est communiqué QUE par e-mail.
	 *
	 * @param array<string, mixed> $input
	 * @return array{submitted:bool}
	 * @throws ApiError
	 */
	public function submit( string $job_uuid, array $input ): array {
		$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$first = sanitize_text_field( (string) ( $input['first_name'] ?? '' ) );
		$last  = sanitize_text_field( (string) ( $input['last_name'] ?? '' ) );

		// Anti-spam (par IP et IP+e-mail) AVANT tout travail.
		if ( class_exists( self::RL ) ) {
			call_user_func( array( self::RL, 'guard_guest_apply' ), strtolower( $email ) );
		}

		$errors = array();
		if ( ! is_email( $email ) ) {
			$errors['email'] = 'Adresse e-mail invalide.';
		}
		if ( '' === $first ) {
			$errors['first_name'] = 'Prénom requis.';
		}
		if ( '' === $last ) {
			$errors['last_name'] = 'Nom requis.';
		}
		if ( empty( $input['consent'] ) ) {
			$errors['consent'] = 'Votre consentement est requis pour envoyer la candidature.';
		}
		if ( ! empty( $errors ) ) {
			throw ApiError::validation( $errors );
		}

		$job_id = JobDirectory::id_from_uuid( $job_uuid );
		if ( 0 === $job_id ) {
			if ( JobDirectory::is_external( $job_uuid ) ) {
				throw new ApiError( 'conflict', 'Cette offre externe ne se candidate pas sur Postelio.' );
			}
			throw ApiError::not_found( 'Offre introuvable.' );
		}
		if ( ! JobDirectory::is_candidateable( $job_id ) ) {
			throw new ApiError( 'invalid_transition', 'Cette offre n\'accepte pas (ou plus) de candidatures.' );
		}
		$snap = JobDirectory::application_snapshot( $job_id );
		if ( null === $snap ) {
			throw ApiError::not_found( 'Offre introuvable.' );
		}

		// Présélection OBLIGATOIRE réellement exigée (contre le snapshot serveur).
		$screening = ScreeningValidator::validate( $snap['questions_preselection'], (array) ( $input['screening_answers'] ?? array() ) );
		if ( ! empty( $screening['errors'] ) ) {
			throw ApiError::validation( $screening['errors'], 'Réponses de présélection incomplètes ou invalides.' );
		}

		// CV (facultatif) : doit être un CV guest utilisable (propriétaire 0), jamais celui d'autrui.
		$cv = (string) ( $input['cv_reference'] ?? $input['cv_uuid'] ?? '' );
		if ( '' !== $cv && ! ( class_exists( self::CV ) && (bool) call_user_func( array( self::CV, 'usable_guest_cv' ), $cv ) ) ) {
			throw ApiError::validation( array( 'cv' => 'CV invalide, inexistant ou déjà utilisé.' ) );
		}

		$secret     = wp_generate_password( 40, false );
		$token_hash = hash( 'sha256', $secret );
		$expires    = time() + self::token_ttl();

		// Dédoublonnage : une candidature guest en attente pour (offre, e-mail) est réutilisée
		// (renvoi d'un nouveau lien), sans créer de doublon.
		$pending = $this->guests->pending_for( $job_id, $email );
		if ( null !== $pending ) {
			$this->guests->refresh_token( (int) $pending['id'], $token_hash, $expires );
			$guest_uuid = (string) $pending['public_uuid'];
		} else {
			$id = $this->guests->insert( array(
				'job_id'            => $job_id,
				'job_uuid'          => (string) $snap['job_uuid'],
				'company_id'        => (int) $snap['company_id'],
				'company_uuid'      => (string) $snap['company_uuid'],
				'email'             => $email,
				'first_name'        => $first,
				'last_name'         => $last,
				'cv_reference'      => '' !== $cv ? $cv : null,
				'screening_answers' => (array) ( $input['screening_answers'] ?? array() ),
				'message'           => isset( $input['message'] ) ? sanitize_textarea_field( (string) $input['message'] ) : null,
				'consent_at'        => current_time( 'mysql', true ),
				'token_hash'        => $token_hash,
				'token_expires'     => $expires,
			) );
			$row        = $this->guests->get( $id );
			$guest_uuid = (string) ( $row['public_uuid'] ?? '' );
		}

		$this->send_confirmation( $email, $guest_uuid, $secret, $first . ' ' . $last, $first, (string) $snap['titre'], (string) $snap['company_name'] );

		Core::instance()->events()->emit( 'application.guest_submitted', array(
			'resource_type' => 'guest_application', 'resource_id' => $guest_uuid, 'audit' => array( 'job_uuid' => (string) $snap['job_uuid'] ),
		) );

		return array( 'submitted' => true );
	}

	/**
	 * Confirmation d'e-mail (double opt-in) + matérialisation.
	 *
	 * @return array{confirmed:bool, account:string, claim_url?:string}
	 * @throws ApiError
	 */
	public function confirm( string $uuid, string $token ): array {
		if ( class_exists( self::RL ) ) {
			call_user_func( array( self::RL, 'guard_guest_confirm' ) );
		}
		$token = (string) $token;
		if ( '' === $token ) {
			throw ApiError::validation( array( 'token' => 'Jeton requis.' ) );
		}
		$guest = $this->guests->get_by_uuid_and_token( $uuid, hash( 'sha256', $token ) );
		// Message générique : ne révèle ni l'existence de la candidature ni la cause exacte.
		if ( null === $guest || GuestApplicationRepository::STATUS_PENDING !== $guest['status'] ) {
			throw new ApiError( 'invalid_transition', 'Lien de confirmation invalide ou déjà utilisé.' );
		}
		if ( (int) $guest['token_expires'] < time() ) {
			throw new ApiError( 'invalid_transition', 'Lien de confirmation expiré.' );
		}

		if ( ! class_exists( self::ONBOARD ) ) {
			throw new ApiError( 'server_error', 'Onboarding indisponible.' );
		}
		$user_id = (int) call_user_func( array( self::ONBOARD, 'find_candidate' ), (string) $guest['email'] );
		if ( 0 === $user_id ) {
			$user_id = (int) call_user_func( array( self::ONBOARD, 'invite_or_get_candidate' ), (string) $guest['email'], (string) $guest['first_name'], (string) $guest['last_name'] );
		}

		// Ré-attribue le CV guest au compte (avant apply : apply valide l'appartenance).
		$cv = (string) ( $guest['cv_reference'] ?? '' );
		if ( '' !== $cv && class_exists( self::CV ) ) {
			call_user_func( array( self::CV, 'reassign_to_user' ), $cv, $user_id );
		}

		$app_uuid = '';
		try {
			$app = $this->apps->apply( $user_id, (string) $guest['job_uuid'], array(
				'cv_reference'      => '' !== $cv ? $cv : null,
				'message'           => $guest['message'] ?? '',
				'screening_answers' => (array) ( $guest['screening_answers'] ?? array() ),
			) );
			$app_uuid = (string) ( $app['public_uuid'] ?? '' );
		} catch ( ApiError $e ) {
			// Déjà candidaté (contrainte unique offre/candidat) : idempotent, on rattache quand même.
			if ( 'conflict' !== $e->error_code() ) {
				throw $e;
			}
		}

		$this->guests->mark_linked( (int) $guest['id'], $app_uuid );

		$invited = (bool) call_user_func( array( self::ONBOARD, 'is_invited' ), $user_id );
		$result  = array( 'confirmed' => true, 'account' => $invited ? 'invited' : 'existing' );
		if ( $invited ) {
			$result['claim_url'] = (string) call_user_func( array( self::ONBOARD, 'claim_url' ), $user_id );
		}

		Core::instance()->events()->emit( 'application.email_confirmed', array(
			'resource_type' => 'guest_application', 'resource_id' => (string) $guest['public_uuid'], 'audit' => array( 'account' => $result['account'] ),
		) );
		return $result;
	}

	private function send_confirmation( string $email, string $guest_uuid, string $secret, string $to_name, string $first, string $job_title, string $company_name ): void {
		if ( ! class_exists( self::MAILER ) ) {
			return;
		}
		$cta = add_query_arg(
			array( 'uuid' => $guest_uuid, 'token' => $secret ),
			home_url( '/candidature-confirmation' )
		);
		call_user_func(
			array( self::MAILER, 'send' ),
			$email,
			'guest_application_pending',
			$cta,
			array(
				'recipient_name' => $first,
				'job_title'      => $job_title,
				'company_name'   => $company_name,
				'expires_hours'  => (string) ( self::token_ttl() / HOUR_IN_SECONDS ),
			),
			trim( $to_name )
		);
	}
}
