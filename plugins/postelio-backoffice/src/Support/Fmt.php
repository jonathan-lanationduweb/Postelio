<?php
/**
 * Formatage d'affichage partagé par les écrans : dates (UTC → fuseau du site), tailles, montants,
 * troncature. Aucun accès aux données, aucune décision métier.
 *
 * @package Postelio\Backoffice\Support
 */

namespace Postelio\Backoffice\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Fmt {

	/** Date+heure d'une valeur MySQL UTC (« — » si vide/invalide). */
	public static function datetime( $value ): string {
		$v = (string) $value;
		if ( '' === $v || '0000-00-00 00:00:00' === $v ) {
			return '—';
		}
		return get_date_from_gmt( $v, 'd/m/Y H:i' );
	}

	/** Date seule d'une valeur MySQL UTC. */
	public static function date( $value ): string {
		$v = (string) $value;
		if ( '' === $v || '0000-00-00 00:00:00' === $v ) {
			return '—';
		}
		return get_date_from_gmt( $v, 'd/m/Y' );
	}

	/** Valeur non vide, sinon « — ». */
	public static function or_dash( $value ): string {
		$v = trim( (string) $value );
		return '' !== $v ? $v : '—';
	}

	/** Entier, ou « — » si null. */
	public static function count( ?int $n ): string {
		return null === $n ? '—' : (string) $n;
	}

	/** Taille lisible (o / Ko / Mo / Go / To). */
	public static function bytes( int $b ): string {
		if ( $b <= 0 ) {
			return '0 o';
		}
		$units = array( 'o', 'Ko', 'Mo', 'Go', 'To' );
		$i     = (int) floor( log( $b, 1024 ) );
		$i     = max( 0, min( $i, count( $units ) - 1 ) );
		return number_format_i18n( $b / ( 1024 ** $i ), $i > 1 ? 1 : 0 ) . ' ' . $units[ $i ];
	}

	/** Montant en centimes → « 12,00 EUR ». */
	public static function money( int $cents, string $currency = 'EUR' ): string {
		return number_format_i18n( $cents / 100, 2 ) . ' ' . strtoupper( $currency );
	}

	/** Référence courte et lisible d'un UUID (jamais présentée comme information principale). */
	public static function ref( string $uuid, int $len = 8 ): string {
		$u = trim( $uuid );
		return '' === $u ? '—' : mb_substr( $u, 0, $len ) . '…';
	}

	/** Date relative courte et lisible (« il y a 3 h », « hier », « dans 2 j »), absolue au-delà de 7 jours. */
	public static function relative( $value ): string {
		$v = (string) $value;
		if ( '' === $v || '0000-00-00 00:00:00' === $v ) {
			return '';
		}
		$ts = strtotime( $v . ' UTC' );
		if ( false === $ts ) {
			return self::date( $v );
		}
		$diff = time() - $ts;
		$abs  = abs( $diff );
		if ( $abs > 7 * DAY_IN_SECONDS ) {
			return get_date_from_gmt( $v, 'd/m/Y' );
		}
		if ( $abs < MINUTE_IN_SECONDS ) {
			return 'à l\'instant';
		}
		if ( $abs < HOUR_IN_SECONDS ) {
			$n = (int) floor( $abs / MINUTE_IN_SECONDS );
			return $diff > 0 ? 'il y a ' . $n . ' min' : 'dans ' . $n . ' min';
		}
		if ( $abs < DAY_IN_SECONDS ) {
			$n = (int) floor( $abs / HOUR_IN_SECONDS );
			return $diff > 0 ? 'il y a ' . $n . ' h' : 'dans ' . $n . ' h';
		}
		$n = (int) floor( $abs / DAY_IN_SECONDS );
		if ( 1 === $n ) {
			return $diff > 0 ? 'hier' : 'demain';
		}
		return $diff > 0 ? 'il y a ' . $n . ' j' : 'dans ' . $n . ' j';
	}

	/** Texte tronqué proprement. */
	public static function excerpt( string $text, int $len = 240 ): string {
		$t = trim( wp_strip_all_tags( $text ) );
		return mb_strlen( $t ) > $len ? mb_substr( $t, 0, $len - 1 ) . '…' : $t;
	}
}
