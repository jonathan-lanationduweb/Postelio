<?php
/**
 * Moteur de règles LOCAL (V1, aucune API externe). Logique PURE (testable hors WP).
 * Retourne un niveau de risque + des reason codes. Volontairement SIMPLE : les cas
 * ambigus → medium (review/flag) ; seuls les cas très explicites → high/critical.
 * N'est PAS une pseudo-IA ; pas de dictionnaire géant.
 *
 * @package Postelio\Moderation\Rules
 */

namespace Postelio\Moderation\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_MODERATION_TESTING' ) ) {
		exit;
	}
}

final class LocalRuleEngine {

	public const LOW      = 'low';
	public const MEDIUM   = 'medium';
	public const HIGH     = 'high';
	public const CRITICAL = 'critical';

	/**
	 * @return array{risk_level:string, reason_codes:array<int,string>}
	 */
	public function evaluate( string $text, string $resource_type = 'message' ): array {
		$t      = strtolower( trim( $text ) );
		$risk   = self::LOW;
		$codes  = array();
		$bump   = static function ( string &$risk, string $to ) {
			$order = array( 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4 );
			if ( ( $order[ $to ] ?? 0 ) > ( $order[ $risk ] ?? 0 ) ) {
				$risk = $to;
			}
		};

		// 1) Schémas d'URL dangereux → high.
		if ( preg_match( '/\b(javascript|data|file|vbscript)\s*:/i', $text ) ) {
			$codes[] = 'malware_link';
			$bump( $risk, self::HIGH );
		}

		// 2) Paiement hors plateforme / fraude financière → high.
		if ( preg_match( '/\b(iban|rib|western union|mandat cash|paypal\.me|payer pour postuler|frais de dossier|virement|carte bancaire|bitcoin|crypto)\b/i', $t ) ) {
			$codes[] = 'off_platform_payment';
			$bump( $risk, self::HIGH );
		}
		if ( preg_match( '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/', strtoupper( $text ) ) ) { // motif IBAN
			$codes[] = 'off_platform_payment';
			$bump( $risk, self::HIGH );
		}

		// 3) Menace explicite / haine très explicite → critical (haute confiance, liste courte).
		$explicit = (array) apply_filters( 'postelio/moderation/blocklist_critical', array( 'je vais te tuer', 'menace de mort' ) );
		foreach ( $explicit as $needle ) {
			if ( '' !== (string) $needle && false !== strpos( $t, strtolower( (string) $needle ) ) ) {
				$codes[] = 'violence_threat';
				$bump( $risk, self::CRITICAL );
			}
		}

		// 4) Haine/harcèlement/sexuel « à surveiller » (liste configurable) → medium (review).
		$watch = (array) apply_filters( 'postelio/moderation/watchlist', array() );
		foreach ( $watch as $needle ) {
			if ( '' !== (string) $needle && false !== strpos( $t, strtolower( (string) $needle ) ) ) {
				$codes[] = 'harassment';
				$bump( $risk, self::MEDIUM );
			}
		}

		// 5) Coordonnées de contact → MEDIUM (flag, jamais bloqué — contextuel légitime).
		$has_email = (bool) preg_match( '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $text );
		$has_phone = (bool) preg_match( '/(\+?\d[\s.\-]?){9,}/', $text );
		$has_url   = (bool) preg_match( '#https?://#i', $text );
		if ( $has_email || $has_phone ) {
			$codes[] = 'contact_bypass';
			$bump( $risk, self::MEDIUM );
		}

		// 6) Spam / répétition → medium.
		$link_count = preg_match_all( '#https?://#i', $text );
		if ( $link_count >= 4 || preg_match( '/(.)\1{9,}/u', $text ) ) {
			$codes[] = 'spam';
			$bump( $risk, self::MEDIUM );
		}
		unset( $has_url );

		return array( 'risk_level' => $risk, 'reason_codes' => array_values( array_unique( $codes ) ) );
	}
}
