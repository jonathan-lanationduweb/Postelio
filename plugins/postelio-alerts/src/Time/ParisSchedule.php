<?php
/**
 * Calcul des échéances d'alerte en heure locale métier (Europe/Paris), rendu en UTC.
 *
 * V1 : daily = 07h30 Europe/Paris ; weekly = lundi 07h30 Europe/Paris. Le calcul passe TOUJOURS
 * par DateTimeZone('Europe/Paris') puis conversion UTC → gestion correcte de l'heure d'été (DST) :
 * la cible reste 07h30 locale même quand l'offset UTC change (+1 hiver / +2 été). Toujours une
 * échéance STRICTEMENT postérieure à l'instant de référence (pas de boucle immédiate).
 *
 * SANS dépendance WordPress (testable) : l'instant de référence est un timestamp UTC passé en
 * argument (par défaut `time()` en exécution réelle).
 *
 * @package Postelio\Alerts\Time
 */

namespace Postelio\Alerts\Time;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_ALERTS_TESTING' ) ) {
		exit;
	}
}

final class ParisSchedule {

	public const TIMEZONE = 'Europe/Paris';
	public const HOUR     = 7;
	public const MINUTE   = 30;

	/**
	 * Prochaine échéance selon la fréquence, en UTC ('Y-m-d H:i:s'). Retourne null pour
	 * 'disabled' (ou toute fréquence inconnue).
	 */
	public static function next_run( string $frequency, int $ref_utc, string $timezone = self::TIMEZONE ): ?string {
		switch ( $frequency ) {
			case 'daily':
				return self::next_daily( $ref_utc, $timezone );
			case 'weekly':
				return self::next_weekly( $ref_utc, $timezone );
			default:
				return null;
		}
	}

	/** Prochain 07h30 local strictement après $ref_utc, en UTC. */
	public static function next_daily( int $ref_utc, string $timezone = self::TIMEZONE ): string {
		$tz  = new \DateTimeZone( $timezone );
		$ref = ( new \DateTime( '@' . $ref_utc ) )->setTimezone( $tz );

		$target = ( clone $ref )->setTime( self::HOUR, self::MINUTE, 0 );
		if ( $target <= $ref ) {
			$target->modify( '+1 day' )->setTime( self::HOUR, self::MINUTE, 0 );
		}
		return self::to_utc( $target );
	}

	/** Prochain lundi 07h30 local strictement après $ref_utc, en UTC. */
	public static function next_weekly( int $ref_utc, string $timezone = self::TIMEZONE ): string {
		$tz  = new \DateTimeZone( $timezone );
		$ref = ( new \DateTime( '@' . $ref_utc ) )->setTimezone( $tz );

		// 'Monday this week' peut être passé ; on avance jusqu'au premier lundi 07h30 > ref.
		$target = ( clone $ref )->modify( 'monday this week' )->setTime( self::HOUR, self::MINUTE, 0 );
		while ( $target <= $ref ) {
			$target->modify( '+1 week' )->setTime( self::HOUR, self::MINUTE, 0 );
		}
		return self::to_utc( $target );
	}

	private static function to_utc( \DateTime $local ): string {
		$utc = ( clone $local )->setTimezone( new \DateTimeZone( 'UTC' ) );
		return $utc->format( 'Y-m-d H:i:s' );
	}
}
