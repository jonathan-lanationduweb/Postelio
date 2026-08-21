<?php
/**
 * Validation des réponses de présélection CONTRE le snapshot serveur de l'offre.
 *
 * On ne fait JAMAIS confiance au corps candidat pour recréer les questions : la
 * liste des questions (id/label/type/required) provient du snapshot d'offre. Le
 * candidat ne fournit que des réponses indexées par `question_id`.
 *
 * Classe pure (retourne erreurs + réponses normalisées) → testable en isolation.
 *
 * @package Postelio\Applications\Applications
 */

namespace Postelio\Applications\Applications;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class ScreeningValidator {

	/**
	 * @param array<int, array<string,mixed>> $questions Snapshot (id,label,type,required,critere).
	 * @param array<string, mixed>            $input     Réponses candidat {question_id: valeur}.
	 * @return array{errors: array<string,string>, answers: array<int, array<string,mixed>>}
	 */
	public static function validate( array $questions, array $input ): array {
		$errors  = array();
		$answers = array();

		foreach ( $questions as $q ) {
			$id    = (string) ( $q['id'] ?? '' );
			$label = (string) ( $q['label'] ?? '' );
			$type  = (string) ( $q['type'] ?? 'texte' );
			$req   = ! empty( $q['required'] );
			if ( '' === $id ) {
				continue;
			}

			$raw     = $input[ $id ] ?? null;
			$present = ! ( null === $raw || ( is_string( $raw ) && '' === trim( $raw ) ) );

			if ( ! $present ) {
				if ( $req ) {
					$errors[ $id ] = 'Réponse obligatoire.';
				}
				continue; // pas de réponse (optionnelle) → on n'enregistre rien
			}

			$value = null;
			switch ( $type ) {
				case 'oui_non':
					$b = self::to_bool( $raw );
					if ( null === $b ) {
						$errors[ $id ] = 'Réponse oui/non attendue.';
						continue 2;
					}
					$value = $b;
					break;
				case 'nombre':
					if ( ! is_numeric( $raw ) ) {
						$errors[ $id ] = 'Nombre attendu.';
						continue 2;
					}
					$value = 0 + $raw;
					break;
				case 'choix':
				case 'texte':
				default:
					$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';
					break;
			}

			$answers[] = array(
				'question_id'    => $id,
				'question_label' => $label, // snapshot du libellé au moment T
				'question_type'  => $type,
				'answer'         => $value,
			);
		}

		return array( 'errors' => $errors, 'answers' => $answers );
	}

	private static function to_bool( $v ): ?bool {
		if ( is_bool( $v ) ) {
			return $v;
		}
		$s = strtolower( trim( (string) $v ) );
		if ( in_array( $s, array( 'oui', 'yes', 'true', '1' ), true ) ) {
			return true;
		}
		if ( in_array( $s, array( 'non', 'no', 'false', '0' ), true ) ) {
			return false;
		}
		return null;
	}
}
