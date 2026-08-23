<?php
/**
 * DTO normalisé, PROVIDER-AGNOSTIC. Le reste du plugin ne voit que cette structure, jamais
 * le payload France Travail brut. Ne contient que les champs Postelio utiles + un
 * `source_metadata` minimal pour l'affichage conforme (licence : afficher la totalité du
 * contenu fourni). Aucun JSON brut complet n'est conservé.
 *
 * @package Postelio\JobSources\Sources
 */

namespace Postelio\JobSources\Sources;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'POSTELIO_JOBSOURCES_TESTING' ) ) {
		exit;
	}
}

final class NormalizedExternalJob {

	public string $source_key = '';
	public string $external_id = '';
	public string $title = '';
	public ?string $description = null;
	public ?string $company_name = null;
	public ?string $company_logo_url = null;
	public ?string $ville = null;
	public ?string $commune_insee = null;
	public ?string $code_postal = null;
	public ?float $latitude = null;
	public ?float $longitude = null;
	public ?string $contract_code_source = null;
	public ?string $contract_normalized = null;
	public ?string $nature_contract = null;
	public ?string $rome_code = null;
	public ?string $rome_label = null;
	public ?string $naf_code = null;
	public ?string $sector_label = null;
	public ?string $experience_code = null;
	public ?string $experience_label = null;
	public ?string $salary_text = null;
	public ?string $working_time = null;
	public bool $alternance = false;
	public ?string $source_published_at = null; // UTC 'Y-m-d H:i:s'
	public ?string $source_updated_at = null;
	public ?string $external_url = null;
	public ?string $external_apply_url = null;
	public string $application_mode = 'external_redirect';

	/** @var array<string, mixed> Champs complémentaires conservés pour l'affichage conforme. */
	public array $source_metadata = array();

	/**
	 * Empreinte de contenu (détection changé/inchangé), calculée sur les champs affichés.
	 */
	public function content_hash(): string {
		$payload = array(
			$this->title, $this->description, $this->company_name, $this->company_logo_url,
			$this->ville, $this->commune_insee, $this->code_postal, $this->latitude, $this->longitude,
			$this->contract_code_source, $this->contract_normalized, $this->nature_contract,
			$this->rome_code, $this->rome_label, $this->naf_code, $this->sector_label,
			$this->experience_code, $this->experience_label, $this->salary_text, $this->working_time,
			$this->alternance, $this->source_published_at, $this->source_updated_at,
			$this->external_url, $this->external_apply_url, $this->source_metadata,
		);
		return sha1( (string) wp_json_encode( $payload ) );
	}

	/** @return array<string, mixed> Ligne prête pour l'upsert repository. */
	public function to_row(): array {
		return array(
			'source_key'           => $this->source_key,
			'external_id'          => $this->external_id,
			'title'                => $this->title,
			'description'          => $this->description,
			'company_name'         => $this->company_name,
			'company_logo_url'     => $this->company_logo_url,
			'ville'                => $this->ville,
			'commune_insee'        => $this->commune_insee,
			'code_postal'          => $this->code_postal,
			'latitude'             => $this->latitude,
			'longitude'            => $this->longitude,
			'contract_code_source' => $this->contract_code_source,
			'contract_normalized'  => $this->contract_normalized,
			'nature_contract'      => $this->nature_contract,
			'rome_code'            => $this->rome_code,
			'rome_label'           => $this->rome_label,
			'naf_code'             => $this->naf_code,
			'sector_label'         => $this->sector_label,
			'experience_code'      => $this->experience_code,
			'experience_label'     => $this->experience_label,
			'salary_text'          => $this->salary_text,
			'working_time'         => $this->working_time,
			'alternance'           => $this->alternance ? 1 : 0,
			'source_published_at'  => $this->source_published_at,
			'source_updated_at'    => $this->source_updated_at,
			'external_url'         => $this->external_url,
			'external_apply_url'   => $this->external_apply_url,
			'application_mode'     => $this->application_mode,
			'source_metadata'      => $this->source_metadata,
			'content_hash'         => $this->content_hash(),
		);
	}
}
