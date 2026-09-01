<?php
/**
 * Écriture VALIDÉE de la configuration du site. Assainit chaque valeur selon le TYPE déclaré dans
 * le schéma (jamais de HTML arbitraire stocké), rejette les pages/champs inconnus, puis persiste et
 * émet un événement d'audit minimal (`site.<page>.updated`, sans dumper toute la charge utile).
 *
 * @package Postelio\Site\Config
 */

namespace Postelio\Site\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteConfigService {

	private SiteConfigRepository $repo;

	public function __construct( ?SiteConfigRepository $repo = null ) {
		$this->repo = $repo ?? new SiteConfigRepository();
	}

	/**
	 * Valide + enregistre les valeurs d'une page. Retourne les valeurs finales (fusionnées).
	 *
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, page:string, values:array<string,mixed>}
	 */
	public function save( string $page, array $input ): array {
		$def = SiteSchema::page( $page );
		if ( null === $def ) {
			return array( 'ok' => false, 'page' => $page, 'values' => array() );
		}

		$clean = ( 'sections' === ( $def['type'] ?? '' ) )
			? $this->clean_sections( (array) $def['sections'], $input )
			: $this->clean_fields( (array) ( $def['fields'] ?? array() ), $input );

		$this->repo->put( $page, $clean );

		// Audit minimal via le bus d'événements du core (auto-journalisé s'il est présent).
		if ( class_exists( '\\Postelio\\Core\\Plugin' ) ) {
			\Postelio\Core\Plugin::instance()->events()->emit(
				'site.' . $page . '.updated',
				array(
					'resource_type' => 'site_config',
					'resource_id'   => $page,
					'audit'         => array( 'page' => $page, 'by' => get_current_user_id() ),
				)
			);
		}

		return array( 'ok' => true, 'page' => $page, 'values' => $this->repo->get( $page ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $sections
	 * @param array<string,mixed>               $input
	 * @return array<string,mixed>
	 */
	private function clean_sections( array $sections, array $input ): array {
		$out  = array();
		$keys = array_keys( $sections );

		foreach ( $sections as $skey => $section ) {
			$in            = isset( $input[ $skey ] ) && is_array( $input[ $skey ] ) ? $input[ $skey ] : array();
			$vals          = $this->clean_fields( (array) ( $section['fields'] ?? array() ), $in );
			$vals['_enabled'] = ! empty( $in['_enabled'] );
			$out[ $skey ]  = $vals;
		}

		// Ordre : seulement des sections connues, réordonnables, sans doublon ; complété par le reste.
		$requested = isset( $input['_order'] ) && is_array( $input['_order'] ) ? array_map( 'strval', $input['_order'] ) : array();
		$order     = array();
		foreach ( $requested as $k ) {
			if ( in_array( $k, $keys, true ) && ! in_array( $k, $order, true ) ) {
				$order[] = $k;
			}
		}
		foreach ( $keys as $k ) {
			if ( ! in_array( $k, $order, true ) ) {
				$order[] = $k;
			}
		}
		$out['_order'] = $order;
		return $out;
	}

	/**
	 * @param array<string,array<string,mixed>> $fields
	 * @param array<string,mixed>               $input
	 * @return array<string,mixed>
	 */
	private function clean_fields( array $fields, array $input ): array {
		$out = array();
		foreach ( $fields as $key => $f ) {
			$type = (string) ( $f['type'] ?? 'text' );
			$raw  = $input[ $key ] ?? null;
			$out[ $key ] = $this->clean_value( $type, $raw, $f );
		}
		return $out;
	}

	/**
	 * @param mixed                $raw
	 * @param array<string,mixed>  $f
	 * @return mixed
	 */
	private function clean_value( string $type, $raw, array $f ) {
		switch ( $type ) {
			case 'toggle':
				return (bool) $raw;

			case 'number':
				$n = is_numeric( $raw ) ? (int) $raw : (int) ( $f['default'] ?? 0 );
				if ( isset( $f['min'] ) ) {
					$n = max( (int) $f['min'], $n );
				}
				if ( isset( $f['max'] ) ) {
					$n = min( (int) $f['max'], $n );
				}
				return $n;

			case 'color':
				$c = sanitize_hex_color( is_string( $raw ) ? $raw : '' );
				return $c ?: (string) ( $f['default'] ?? '' );

			case 'select':
				$opts = array_keys( (array) ( $f['options'] ?? array() ) );
				$v    = is_string( $raw ) ? $raw : '';
				return in_array( $v, $opts, true ) ? $v : (string) ( $f['default'] ?? ( $opts[0] ?? '' ) );

			case 'media':
				// Stocke une URL (ou un id numérique). Jamais de balise, jamais de javascript:/data:.
				$v = is_string( $raw ) ? trim( $raw ) : '';
				if ( '' === $v ) {
					return '';
				}
				if ( ctype_digit( $v ) ) {
					return (string) (int) $v; // id d'attachement (validé par WordPress à l'upload)
				}
				$url = esc_url_raw( $v, array( 'http', 'https' ) ); // bloque javascript:, data:, file:…
				if ( '' === $url ) {
					return '';
				}
				// Validation d'extension si le champ déclare des types autorisés.
				$accept = isset( $f['accept'] ) && is_array( $f['accept'] ) ? array_map( 'strtolower', $f['accept'] ) : array();
				if ( ! empty( $accept ) ) {
					$path = (string) wp_parse_url( $url, PHP_URL_PATH );
					$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
					if ( '' !== $ext && ! in_array( $ext, $accept, true ) ) {
						return (string) ( $f['default'] ?? '' ); // type refusé → repli sur défaut
					}
				}
				return $url;

			case 'textarea':
				return sanitize_textarea_field( is_string( $raw ) ? $raw : '' );

			case 'repeater':
				return $this->clean_repeater( (array) ( $f['fields'] ?? array() ), $raw );

			case 'collection':
				// Liste de références stables (UUID ou ID numérique), jamais de contenu métier.
				if ( ! is_array( $raw ) ) {
					return array();
				}
				$refs = array();
				foreach ( array_values( $raw ) as $ref ) {
					if ( count( $refs ) >= 50 ) {
						break;
					}
					$id = is_scalar( $ref ) ? sanitize_text_field( (string) $ref ) : '';
					// UUID (avec tirets) ou ID numérique uniquement.
					if ( '' !== $id && preg_match( '/^[A-Za-z0-9\-]{1,64}$/', $id ) && ! in_array( $id, $refs, true ) ) {
						$refs[] = $id;
					}
				}
				return $refs;

			case 'text':
			default:
				return sanitize_text_field( is_string( $raw ) ? $raw : '' );
		}
	}

	/**
	 * @param array<string,array<string,mixed>> $subfields
	 * @param mixed                             $raw
	 * @return array<int,array<string,mixed>>
	 */
	private function clean_repeater( array $subfields, $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$rows = array();
		$max  = 50; // garde-fou anti-abus
		foreach ( array_values( $raw ) as $row ) {
			if ( count( $rows ) >= $max ) {
				break;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = $this->clean_fields( $subfields, $row );
		}
		return $rows;
	}
}
