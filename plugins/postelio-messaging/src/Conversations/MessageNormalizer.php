<?php
/**
 * Normalisation du corps d'un message (V1 : texte uniquement).
 *
 * - trim ;
 * - suppression des balises HTML (aucun HTML de confiance ; anti-XSS au stockage) ;
 * - Unicode conservé ;
 * - message vide refusé ;
 * - longueur maximale configurable.
 *
 * Classe pure (avec shims WP en test) → testable.
 *
 * @package Postelio\Messaging\Conversations
 */

namespace Postelio\Messaging\Conversations;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_CORE_TESTING' ) ) {
		exit;
	}
}

final class MessageNormalizer {

	public static function max_length(): int {
		$n = (int) apply_filters( 'postelio/messaging/max_length', 5000 );
		return $n > 0 ? $n : 5000;
	}

	/**
	 * @return array{ok:bool, value:string, error:?string}
	 */
	public static function normalize( string $raw ): array {
		// Retire les balises (le contenu XSS devient du texte inerte), conserve les
		// sauts de ligne et l'Unicode.
		$clean = sanitize_textarea_field( $raw );
		$clean = trim( $clean );

		if ( '' === $clean ) {
			return array( 'ok' => false, 'value' => '', 'error' => 'Message vide.' );
		}
		$max = self::max_length();
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $clean ) > $max : strlen( $clean ) > $max ) {
			return array( 'ok' => false, 'value' => '', 'error' => "Message trop long (max {$max} caractères)." );
		}
		return array( 'ok' => true, 'value' => $clean, 'error' => null );
	}
}
