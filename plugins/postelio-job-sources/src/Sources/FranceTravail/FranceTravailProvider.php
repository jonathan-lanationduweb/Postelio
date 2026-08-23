<?php
/**
 * Provider France Travail — API « Offres d'emploi v2 » (source officielle, self-serve).
 *
 * Auth : OAuth2 client_credentials (token mis en cache ~expires_in). Pagination : header
 * `range=p-d` (≤150/appel, plafond ~3150/req) ; total via `Content-Range`. Incrémental :
 * `minCreationDate`/`maxCreationDate`. Gestion 401 (refresh+1 retry) / 403 (config, pas de
 * retry) / 429 (circuit) / 5xx (retry borné). Secrets lus en constantes/env, JAMAIS en base
 * ni en Git. Le serveur ne contacte QUE les hôtes officiels (UrlGuard, anti-SSRF).
 *
 * Réf. (revalidées cette session) : francetravail.io/data/api/offres-emploi/documentation,
 * .../client-credentials, .../consommation-resiliente, .../licence-offres-emploi.
 *
 * @package Postelio\JobSources\Sources\FranceTravail
 */

namespace Postelio\JobSources\Sources\FranceTravail;

use Postelio\JobSources\Sources\HtmlSanitizer;
use Postelio\JobSources\Sources\JobSourceProvider;
use Postelio\JobSources\Sources\NormalizedExternalJob;
use Postelio\JobSources\Sources\PageResult;
use Postelio\JobSources\Sources\RateLimiter;
use Postelio\JobSources\Sources\SyncQuery;
use Postelio\JobSources\Sources\UrlGuard;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_JOBSOURCES_TESTING' ) ) {
		exit;
	}
}

final class FranceTravailProvider implements JobSourceProvider {

	public const KEY = 'france_travail';

	private const TOKEN_URL   = 'https://entreprise.francetravail.fr/connexion/oauth2/access_token?realm=/partenaire';
	private const API_BASE    = 'https://api.francetravail.io/partenaire/offresdemploi';
	private const SCOPE       = 'api_offresdemploiv2 o2dsoffre';
	private const TOKEN_CACHE = 'pst_ft_token';
	private const LICENCE_URL = 'https://francetravail.io/produits-partages/documentation/conditions-dutilisation-api/licence-offres-emploi';
	public const MAPPING_VERSION = 1;

	private RateLimiter $limiter;

	public function __construct( ?RateLimiter $limiter = null ) {
		$this->limiter = $limiter ?: new RateLimiter( self::KEY );
	}

	public function get_key(): string {
		return self::KEY;
	}
	public function get_name(): string {
		return 'France Travail';
	}
	public function supports_incremental(): bool {
		return true;
	}

	public function is_available(): bool {
		if ( ! (bool) apply_filters( 'postelio/job_sources/enabled_' . self::KEY, true ) ) {
			return false;
		}
		return '' !== $this->client_id() && '' !== $this->client_secret();
	}

	/** @return array<string, mixed> */
	public function get_attribution(): array {
		return array(
			'source_key'            => self::KEY,
			'source_label'          => 'France Travail',
			'licence_url'           => self::LICENCE_URL,
			'requires_full_content' => true, // licence Art. 5.3
			'requires_logo'         => true, // licence Art. 5.3
		);
	}

	// --- Secrets (jamais en base ni Git) -------------------------------------

	private function client_id(): string {
		if ( defined( 'POSTELIO_FT_CLIENT_ID' ) ) {
			return (string) POSTELIO_FT_CLIENT_ID;
		}
		return (string) ( getenv( 'POSTELIO_FT_CLIENT_ID' ) ?: '' );
	}
	private function client_secret(): string {
		if ( defined( 'POSTELIO_FT_CLIENT_SECRET' ) ) {
			return (string) POSTELIO_FT_CLIENT_SECRET;
		}
		return (string) ( getenv( 'POSTELIO_FT_CLIENT_SECRET' ) ?: '' );
	}

	// --- OAuth2 --------------------------------------------------------------

	public function authenticate( bool $force = false ): string {
		if ( ! $force ) {
			$cached = get_transient( self::TOKEN_CACHE );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}
		if ( ! UrlGuard::api_host_allowed( self::TOKEN_URL ) ) {
			throw new \RuntimeException( 'token_host_forbidden' );
		}
		$resp = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $this->client_id(),
					'client_secret' => $this->client_secret(),
					'scope'         => self::SCOPE,
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			throw new \RuntimeException( 'token_http_error' );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			throw new \RuntimeException( 'token_refused_' . $code );
		}
		$token = (string) $data['access_token'];
		$ttl   = max( 60, (int) ( $data['expires_in'] ?? 1400 ) - 60 ); // marge de sécurité
		set_transient( self::TOKEN_CACHE, $token, $ttl );
		return $token;
	}

	// --- Fetch ---------------------------------------------------------------

	public function fetch_page( SyncQuery $query ): PageResult {
		$last = $query->offset + $query->limit - 1;
		$args = array_merge(
			$query->criteria,
			array( 'range' => $query->offset . '-' . $last )
		);
		$url  = self::API_BASE . '/v2/offres/search?' . http_build_query( $args );

		$resp = $this->api_get( $url );
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 204 === $code ) {
			return new PageResult( array(), 0, false );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || ! isset( $body['resultats'] ) || ! is_array( $body['resultats'] ) ) {
			throw new \RuntimeException( 'invalid_payload' );
		}
		// Total via Content-Range: "offres p-d/t".
		$range = (string) wp_remote_retrieve_header( $resp, 'content-range' );
		$total = 0;
		if ( preg_match( '#/(\d+)\s*$#', $range, $m ) ) {
			$total = (int) $m[1];
		}
		$fetched  = $query->offset + count( $body['resultats'] );
		$has_more = $total > $fetched && $fetched < 3150; // plafond FT
		return new PageResult( $body['resultats'], $total, $has_more );
	}

	/** @return array<string, mixed>|null */
	public function fetch_offer( string $external_id ): ?array {
		$url  = self::API_BASE . '/v2/offres/' . rawurlencode( $external_id );
		$resp = $this->api_get( $url );
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code && 206 !== $code ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		return is_array( $body ) ? $body : null;
	}

	/**
	 * GET authentifié résilient : throttle + circuit + 401(refresh,1 retry) + 5xx(retry borné).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function api_get( string $url, int $attempt = 0 ) {
		if ( ! UrlGuard::api_host_allowed( $url ) ) {
			throw new \RuntimeException( 'api_host_forbidden' );
		}
		if ( $this->limiter->is_open() ) {
			throw new \RuntimeException( 'circuit_open' );
		}
		$this->limiter->throttle();
		$token = $this->authenticate();
		$resp  = wp_remote_get( $url, array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ) ) );

		if ( is_wp_error( $resp ) ) {
			$this->limiter->record_failure();
			throw new \RuntimeException( 'http_error' );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );

		if ( 401 === $code && $attempt < 1 ) {
			$this->authenticate( true ); // token périmé → refresh + 1 retry
			return $this->api_get( $url, $attempt + 1 );
		}
		if ( 403 === $code ) {
			$this->limiter->record_failure();
			throw new \RuntimeException( 'forbidden_scope' ); // config/scope : pas de retry
		}
		if ( 429 === $code ) {
			$this->limiter->record_failure();
			throw new \RuntimeException( 'rate_limited' );
		}
		if ( $code >= 500 && $attempt < 2 ) {
			usleep( (int) ( pow( 2, $attempt ) * 500_000 ) ); // backoff 0.5s,1s
			return $this->api_get( $url, $attempt + 1 );
		}
		if ( $code >= 400 ) {
			$this->limiter->record_failure();
			throw new \RuntimeException( 'http_' . $code );
		}
		$this->limiter->record_success();
		return $resp;
	}

	// --- Normalisation -------------------------------------------------------

	public function normalize( array $raw ): ?NormalizedExternalJob {
		$id = (string) ( $raw['id'] ?? '' );
		$title = trim( (string) ( $raw['intitule'] ?? '' ) );
		if ( '' === $id || '' === $title ) {
			return null; // sans id ou titre : inexploitable
		}
		$n              = new NormalizedExternalJob();
		$n->source_key  = self::KEY;
		$n->external_id = $id;
		$n->title       = $title;
		$n->description = HtmlSanitizer::clean( isset( $raw['description'] ) ? (string) $raw['description'] : null );

		$ent = is_array( $raw['entreprise'] ?? null ) ? $raw['entreprise'] : array();
		$n->company_name     = isset( $ent['nom'] ) && '' !== trim( (string) $ent['nom'] ) ? sanitize_text_field( (string) $ent['nom'] ) : null;
		$n->company_logo_url = isset( $ent['logo'] ) && UrlGuard::safe_redirect_url( (string) $ent['logo'] ) ? esc_url_raw( (string) $ent['logo'] ) : null;

		$lieu = is_array( $raw['lieuTravail'] ?? null ) ? $raw['lieuTravail'] : array();
		$n->ville         = isset( $lieu['libelle'] ) ? sanitize_text_field( (string) $lieu['libelle'] ) : null;
		$n->commune_insee = isset( $lieu['commune'] ) ? preg_replace( '/[^0-9AB]/i', '', (string) $lieu['commune'] ) : null;
		$n->code_postal   = isset( $lieu['codePostal'] ) ? preg_replace( '/[^0-9]/', '', (string) $lieu['codePostal'] ) : null;
		$n->latitude      = isset( $lieu['latitude'] ) ? (float) $lieu['latitude'] : null;
		$n->longitude     = isset( $lieu['longitude'] ) ? (float) $lieu['longitude'] : null;

		$n->contract_code_source = isset( $raw['typeContrat'] ) ? sanitize_text_field( (string) $raw['typeContrat'] ) : null;
		$n->contract_normalized  = self::normalize_contract( (string) ( $raw['typeContrat'] ?? '' ) );
		$n->nature_contract      = isset( $raw['natureContrat'] ) ? sanitize_text_field( (string) $raw['natureContrat'] ) : null;
		$n->alternance           = ! empty( $raw['alternance'] );

		$n->rome_code  = isset( $raw['romeCode'] ) ? sanitize_text_field( (string) $raw['romeCode'] ) : null;
		$n->rome_label = isset( $raw['romeLibelle'] ) ? sanitize_text_field( (string) $raw['romeLibelle'] ) : null;
		$n->naf_code   = isset( $raw['codeNAF'] ) ? sanitize_text_field( (string) $raw['codeNAF'] ) : null;
		$n->sector_label = isset( $raw['secteurActiviteLibelle'] ) ? sanitize_text_field( (string) $raw['secteurActiviteLibelle'] ) : null;

		$n->experience_code  = isset( $raw['experienceExige'] ) ? sanitize_text_field( (string) $raw['experienceExige'] ) : null;
		$n->experience_label = isset( $raw['experienceLibelle'] ) ? sanitize_text_field( (string) $raw['experienceLibelle'] ) : null;

		$sal = is_array( $raw['salaire'] ?? null ) ? $raw['salaire'] : array();
		$n->salary_text  = isset( $sal['libelle'] ) && '' !== trim( (string) $sal['libelle'] ) ? sanitize_text_field( (string) $sal['libelle'] ) : null;
		$n->working_time = isset( $raw['dureeTravailLibelleConverti'] ) ? sanitize_text_field( (string) $raw['dureeTravailLibelleConverti'] ) : ( isset( $raw['dureeTravailLibelle'] ) ? sanitize_text_field( (string) $raw['dureeTravailLibelle'] ) : null );

		$n->source_published_at = self::to_utc( (string) ( $raw['dateCreation'] ?? '' ) );
		$n->source_updated_at   = self::to_utc( (string) ( $raw['dateActualisation'] ?? '' ) );

		// URL de redirection candidat (peut pointer vers un partenaire légitime).
		$origine = is_array( $raw['origineOffre'] ?? null ) ? $raw['origineOffre'] : array();
		$contact = is_array( $raw['contact'] ?? null ) ? $raw['contact'] : array();
		$src_url = (string) ( $origine['urlOrigine'] ?? '' );
		$app_url = (string) ( $contact['urlPostulation'] ?? ( $contact['urlRecruteur'] ?? '' ) );
		$n->external_url       = UrlGuard::safe_redirect_url( $src_url ) ? esc_url_raw( $src_url ) : null;
		$n->external_apply_url = UrlGuard::safe_redirect_url( $app_url ) ? esc_url_raw( $app_url ) : ( $n->external_url );

		// Contenu complémentaire conservé pour l'AFFICHAGE CONFORME (licence Art. 5.3),
		// sans stocker le JSON brut complet.
		$n->source_metadata = self::keep_display_metadata( $raw, $origine );

		return $n;
	}

	/** @return array<string, mixed> */
	private static function keep_display_metadata( array $raw, array $origine ): array {
		$meta = array();
		foreach ( array( 'competences', 'formations', 'langues', 'permis', 'qualitesProfessionnelles', 'qualificationLibelle', 'nombrePostes', 'accessibleTH', 'deplacementLibelle', 'trancheEffectifEtab' ) as $k ) {
			if ( isset( $raw[ $k ] ) && ( ! is_array( $raw[ $k ] ) || ! empty( $raw[ $k ] ) ) ) {
				$meta[ $k ] = $raw[ $k ];
			}
		}
		if ( isset( $origine['origine'] ) ) {
			$meta['origine'] = (string) $origine['origine']; // 1=FT, 2=Partenaire
		}
		if ( isset( $origine['partenaires'] ) && is_array( $origine['partenaires'] ) ) {
			$meta['partenaires'] = $origine['partenaires'];
		}
		return $meta;
	}

	private static function normalize_contract( string $ft ): ?string {
		$map = array( 'CDI' => 'CDI', 'CDD' => 'CDD', 'MIS' => 'interim', 'SAI' => 'CDD', 'LIB' => 'freelance', 'FRA' => 'franchise', 'CCE' => 'CDI', 'DIN' => 'freelance', 'DDI' => 'CDD' );
		$ft  = strtoupper( trim( $ft ) );
		return '' === $ft ? null : ( $map[ $ft ] ?? 'autre' );
	}

	private static function to_utc( string $iso ): ?string {
		$iso = trim( $iso );
		if ( '' === $iso ) {
			return null;
		}
		try {
			$dt = new \DateTime( $iso );
			$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
