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

	/** Texte tronqué proprement. */
	public static function excerpt( string $text, int $len = 240 ): string {
		$t = trim( wp_strip_all_tags( $text ) );
		return mb_strlen( $t ) > $len ? mb_substr( $t, 0, $len - 1 ) . '…' : $t;
	}
}
