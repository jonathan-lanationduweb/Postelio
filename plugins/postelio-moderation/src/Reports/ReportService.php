<?php
/**
 * Signalements utilisateurs (réactif) : validation type/reason, visibilité de la ressource,
 * rate-limit, déduplication, rattachement à LA case active (grouping), priorité = max.
 * `reporter_user_id` reste interne (anonymat vis-à-vis du contenu signalé).
 *
 * @package Postelio\Moderation\Reports
 */

namespace Postelio\Moderation\Reports;

use Postelio\Core\ApiError;
use Postelio\Core\Plugin as Core;
use Postelio\Moderation\Cases\CaseService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReportService {

	private ReportRepository $reports;
	private CaseService $cases;

	public function __construct( ReportRepository $reports, CaseService $cases ) {
		$this->reports = $reports;
		$this->cases   = $cases;
	}

	public function reports(): ReportRepository {
		return $this->reports;
	}

	private function dedup_window(): int {
		return (int) apply_filters( 'postelio/moderation/report_dedup_window', DAY_IN_SECONDS );
	}
	private function rate_per_hour(): int {
		return (int) apply_filters( 'postelio/moderation/report_rate_per_hour', 20 );
	}

	/**
	 * @return array{status:string, report_uuid:?string, duplicate:bool}
	 * @throws ApiError
	 */
	public function report( int $reporter_id, string $resource_type, string $resource_uuid, string $reason_code, string $description ): array {
		if ( ! ReasonCodes::is_resource_type( $resource_type ) ) {
			throw ApiError::validation( array( 'resource_type' => 'Type de ressource non supporté.' ) );
		}
		if ( ! ReasonCodes::is_valid_for( $resource_type, $reason_code ) ) {
			throw ApiError::validation( array( 'reason_code' => 'Motif non autorisé pour ce type.' ) );
		}
		if ( '' === trim( $resource_uuid ) || ! $this->resource_visible( $resource_type, $resource_uuid, $reporter_id ) ) {
			throw ApiError::not_found(); // non-divulgation : ressource inconnue/inaccessible
		}
		// Rate limit.
		if ( $this->reports->count_recent_by_reporter( $reporter_id, HOUR_IN_SECONDS ) >= max( 1, $this->rate_per_hour() ) ) {
			throw new ApiError( 'rate_limited', 'Trop de signalements ; réessayez plus tard.' );
		}
		// Déduplication : un seul report identique par fenêtre.
		if ( $this->reports->recent_duplicate( $reporter_id, $resource_type, $resource_uuid, $reason_code, $this->dedup_window() ) ) {
			return array( 'status' => 'received', 'report_uuid' => null, 'duplicate' => true );
		}

		$report_id = $this->reports->insert( array(
			'reporter_user_id' => $reporter_id,
			'resource_type'    => $resource_type,
			'resource_uuid'    => $resource_uuid,
			'reason_code'      => $reason_code,
			'description'      => sanitize_textarea_field( $description ),
		) );

		// Grouping : rattache à LA case active de la ressource (priorité = max).
		$case_id = $this->cases->open_or_attach(
			$resource_type,
			$resource_uuid,
			ReasonCodes::priority_for( $reason_code ),
			'medium',
			'report',
			true,
			array( $reason_code ),
			$reporter_id
		);
		$this->reports->attach_case( $report_id, $case_id );

		Core::instance()->events()->emit( 'moderation.report_created', array(
			'resource_type' => $resource_type, 'resource_uuid' => $resource_uuid, 'reason_code' => $reason_code,
			'audit_resource_type' => 'moderation_report',
		) );

		return array( 'status' => 'received', 'report_uuid' => null, 'duplicate' => false );
	}

	/** Vérifie que la ressource existe et est « connaissable » par le reporter. */
	private function resource_visible( string $type, string $uuid, int $reporter_id ): bool {
		switch ( $type ) {
			case 'job':
				return class_exists( '\\Postelio\\Jobs\\Api\\JobDirectory' ) && \Postelio\Jobs\Api\JobDirectory::id_from_uuid( $uuid ) > 0;
			case 'external_job':
				return class_exists( '\\Postelio\\Jobs\\Api\\JobDirectory' ) && \Postelio\Jobs\Api\JobDirectory::is_external( $uuid );
			case 'company':
				return class_exists( '\\Postelio\\Companies\\Api\\CompanyDirectory' ) && \Postelio\Companies\Api\CompanyDirectory::id_from_uuid( $uuid ) > 0;
			case 'message':
				// Report d'un message = via l'UUID de sa conversation (UUID v4 non devinable).
				// La conversation doit exister ; accès fin (participant) = raffinement futur.
				if ( class_exists( '\\Postelio\\Messaging\\Api\\MessagingDirectory' ) ) {
					return null !== \Postelio\Messaging\Api\MessagingDirectory::get_conversation_context( $uuid );
				}
				return true;
			case 'skill':
			case 'profile':
			default:
				// Types éditoriaux publics (skills/profile) : existence déléguée au futur
				// contrat du domaine ; V1 accepte (filtrable).
				return (bool) apply_filters( 'postelio/moderation/resource_visible', true, $type, $uuid, $reporter_id );
		}
	}
}
