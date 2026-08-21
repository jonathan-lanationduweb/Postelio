<?php
/**
 * Validation et normalisation des données d'entretien. Logique PURE (DateTime,
 * DateTimeZone, filter_var) — aucune dépendance WordPress → testable directement.
 *
 * - Dates : entrée ISO 8601, stockage **UTC** (`Y-m-d H:i:s`) ; le fuseau métier est
 *   conservé à part. Une entrée sans offset est interprétée dans le fuseau fourni.
 * - Types : liste fermée `video|onsite|phone` (pas de valeur libre).
 * - Durée : bornée (15..240 min).
 * - Données spécifiques : URL visio (schéma http/https), adresse structurée, téléphone.
 *
 * Le nettoyage anti-XSS des champs texte (sanitize_textarea_field) est appliqué par le
 * service (dépend de WP) ; ici on ne fait que valider/structurer.
 *
 * @package Postelio\Interviews\Interviews
 */

namespace Postelio\Interviews\Interviews;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_INTERVIEWS_TESTING' ) ) {
		exit;
	}
}

final class InterviewValidator {

	public const TYPE_VIDEO  = 'video';
	public const TYPE_ONSITE = 'onsite';
	public const TYPE_PHONE  = 'phone';

	public const TYPES = array( self::TYPE_VIDEO, self::TYPE_ONSITE, self::TYPE_PHONE );

	public const MIN_DURATION = 15;
	public const MAX_DURATION = 240;

	public static function valid_type( string $type ): bool {
		return in_array( $type, self::TYPES, true );
	}

	public static function valid_timezone( string $tz ): bool {
		if ( '' === $tz ) {
			return false;
		}
		try {
			new \DateTimeZone( $tz );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	public static function valid_duration( int $minutes ): bool {
		return $minutes >= self::MIN_DURATION && $minutes <= self::MAX_DURATION;
	}

	/** URL de visioconférence : http/https + hôte présent. */
	public static function valid_meeting_url( string $url ): bool {
		if ( strlen( $url ) > 2048 ) {
			return false;
		}
		$parts = \parse_url( $url );
		return is_array( $parts )
			&& in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			&& '' !== (string) ( $parts['host'] ?? '' );
	}

	/**
	 * Convertit une date ISO 8601 en UTC (`Y-m-d H:i:s`). Une entrée sans offset est
	 * interprétée dans `$tz`. Retourne null si invalide.
	 */
	public static function to_utc( string $iso, string $tz ): ?string {
		$iso = trim( $iso );
		if ( '' === $iso || ! self::valid_timezone( $tz ) ) {
			return null;
		}
		try {
			// Si l'ISO porte un offset (Z ou +hh:mm), DateTime l'utilise ; sinon le 2e
			// argument (fuseau métier) s'applique.
			$dt = new \DateTime( $iso, new \DateTimeZone( $tz ) );
		} catch ( \Exception $e ) {
			return null;
		}
		$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Valide un créneau : date convertible + future (par rapport à `$now_utc_ts`) + durée.
	 *
	 * @return array{ok:bool, scheduled_at?:string, errors?:array<string,string>}
	 */
	public static function validate_slot( string $iso, string $tz, int $duration, int $now_utc_ts ): array {
		$errors = array();
		if ( ! self::valid_timezone( $tz ) ) {
			$errors['timezone'] = 'Fuseau horaire invalide.';
		}
		$utc = self::valid_timezone( $tz ) ? self::to_utc( $iso, $tz ) : null;
		if ( null === $utc ) {
			$errors['scheduled_at'] = 'Date/heure invalide (format ISO 8601 attendu).';
		} elseif ( strtotime( $utc . ' UTC' ) <= $now_utc_ts ) {
			$errors['scheduled_at'] = 'La date de l\'entretien doit être dans le futur.';
		}
		if ( ! self::valid_duration( $duration ) ) {
			$errors['duration_minutes'] = 'Durée hors bornes (' . self::MIN_DURATION . '–' . self::MAX_DURATION . ' min).';
		}
		if ( $errors ) {
			return array( 'ok' => false, 'errors' => $errors );
		}
		return array( 'ok' => true, 'scheduled_at' => (string) $utc );
	}
}
