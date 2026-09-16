<?php
/**
 * Présentation des offres selon l'audience. N'expose jamais l'ID interne : seul
 * l'`uuid` public identifie l'offre (D2). Les données de contact/présélection
 * restent réservées au propriétaire.
 *
 * @package Postelio\Jobs\Jobs
 */

namespace Postelio\Jobs\Jobs;

use Postelio\Companies\Api\CompanyDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobPresenter {

	/**
	 * @param array<string, mixed> $j
	 * @return array<string, mixed>
	 */
	public static function public_view( array $j ): array {
		// Offre EXTERNE (Lot 10) : présentation déléguée à postelio-job-sources via filtre.
		if ( 'external' === ( $j['source_type'] ?? 'native' ) ) {
			$ext = apply_filters( 'postelio/jobs/present_external', null, $j );
			if ( is_array( $ext ) ) {
				return $ext;
			}
		}
		$d = is_array( $j['detail'] ) ? $j['detail'] : array();
		return array(
			'uuid'             => $j['uuid'],
			'titre'            => $j['titre'],
			'description'      => $j['description'],
			'resume'           => $d['resume'] ?? null,
			'ville'            => $j['ville'],
			'departement'      => $j['departement'],
			'contrat'          => $j['contrat'],
			'teletravail'      => $j['teletravail'],
			'categorie'        => $j['categorie'],
			'categorie_label'  => $d['categorie_label'] ?? null,
			'niveau_etude'     => $j['niveau_etude'],
			'experience'       => $j['experience'],
			'salaire'          => $d['salaire'] ?? null,
			'salaire_annuel'   => $j['salaire_annuel'],
			'temps_travail'    => $d['temps_travail'] ?? null,
			'duree'            => $d['duree'] ?? null,
			'alternance'       => $j['alternance'],
			'stage'            => $j['stage'],
			'debutant'         => $j['debutant'],
			'date_publication' => $j['date_publication'],
			'date_expiration'  => $j['date_expiration'],
			'missions'         => $d['missions'] ?? array(),
			'profil'           => $d['profil'] ?? array(),
			'competences'      => $d['competences'] ?? array(),
			'avantages'        => $d['avantages'] ?? array(),
			'processus'        => $d['processus'] ?? array(),
			// Questions de présélection PUBLIQUES (pour le formulaire de candidature) : id,
			// libellé, type, obligatoire, options — JAMAIS le `critere` (barème interne).
			'questions_preselection' => self::public_questions( $d['questions_preselection'] ?? array() ),
			'company'          => CompanyDirectory::public_summary( (int) $j['company']['id'] ),
			// Provenance (Lot 10) : offre native Postelio, candidature Postelio.
			'source'           => array( 'type' => 'native', 'key' => 'postelio', 'label' => 'Postelio', 'external' => false ),
			'application'      => array( 'mode' => 'postelio' ),
		);
		// Exclus du public : id/author interne, email_reception, `critere` des questions, statut brut.
	}

	/**
	 * Vue PUBLIQUE des questions de présélection : de quoi afficher le formulaire, sans le
	 * barème interne (`critere`).
	 *
	 * @param mixed $qs
	 * @return array<int, array<string, mixed>>
	 */
	private static function public_questions( $qs ): array {
		if ( ! is_array( $qs ) ) {
			return array();
		}
		$out = array();
		foreach ( $qs as $q ) {
			if ( ! is_array( $q ) || '' === (string) ( $q['id'] ?? '' ) ) {
				continue;
			}
			$pq = array(
				'id'       => (string) $q['id'],
				'label'    => (string) ( $q['label'] ?? '' ),
				'type'     => (string) ( $q['type'] ?? 'texte' ),
				'required' => ! empty( $q['required'] ),
			);
			if ( isset( $q['options'] ) && is_array( $q['options'] ) ) {
				$pq['options'] = array_values( array_map( 'strval', $q['options'] ) );
			}
			$out[] = $pq; // jamais `critere`
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $j
	 * @return array<string, mixed>
	 */
	public static function owner_view( array $j ): array {
		$view                          = self::public_view( $j );
		$d                             = is_array( $j['detail'] ) ? $j['detail'] : array();
		$view['status']                = $j['status'];
		$view['revision']              = $j['revision'] ?? 1;
		$view['renewal_count']         = $j['renewal_count'] ?? 0;
		$view['email_reception']       = $d['email_reception'] ?? null;
		$view['questions_preselection'] = $d['questions_preselection'] ?? array();
		// Nom d'entreprise TOUJOURS à jour via l'annuaire (le cache meta n'est qu'un repli).
		$view['company']               = array(
			'uuid' => $j['company']['uuid'],
			'nom'  => CompanyDirectory::name_of( (int) $j['company']['id'] ) ?? $j['company']['nom'],
		);
		return $view;
	}

	/**
	 * @param array<string, mixed> $j
	 * @return array<string, mixed>
	 */
	public static function admin_view( array $j ): array {
		return self::owner_view( $j );
	}
}
