<?php
/**
 * Endpoints utilisateur de signalement :
 *   POST /moderation/reports           (générique : resource_type, resource_uuid, reason_code, description?)
 *   GET  /me/moderation/reports        (ses propres signalements, statut générique)
 *
 * @package Postelio\Moderation\Http
 */

namespace Postelio\Moderation\Http;

use Postelio\Core\Permissions\Guard;
use Postelio\Core\Rest\Controller;
use Postelio\Moderation\Cases\CaseRepository;
use Postelio\Moderation\Reports\ReportService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReportController extends Controller {

	private ReportService $reports;

	public function __construct( ReportService $reports ) {
		$this->reports = $reports;
	}

	public function register_routes(): void {
		$ns = $this->namespace();
		register_rest_route( $ns, '/moderation/reports', array(
			'methods' => 'POST', 'permission_callback' => Guard::require_cap( 'pst_report_content' ), 'callback' => $this->guarded( array( $this, 'create' ) ),
		) );
		register_rest_route( $ns, '/me/moderation/reports', array(
			'methods' => 'GET', 'permission_callback' => Guard::require_cap( 'pst_report_content' ), 'callback' => $this->guarded( array( $this, 'mine' ) ),
		) );
	}

	public function create( \WP_REST_Request $r ): \WP_REST_Response {
		$b    = (array) $r->get_json_params();
		$res  = $this->reports->report(
			get_current_user_id(),
			(string) ( $b['resource_type'] ?? '' ),
			(string) ( $b['resource_uuid'] ?? '' ),
			(string) ( $b['reason_code'] ?? '' ),
			(string) ( $b['description'] ?? '' )
		);
		return $this->ok( array( 'status' => $res['status'], 'duplicate' => $res['duplicate'] ), array(), 201 );
	}

	public function mine(): \WP_REST_Response {
		$uid   = get_current_user_id();
		$cases = new CaseRepository();
		$items = array();
		foreach ( $this->reports->reports()->list_for_reporter( $uid, 50 ) as $rep ) {
			$case    = ! empty( $rep['case_id'] ) ? $cases->get( (int) $rep['case_id'] ) : null;
			$items[] = ModerationPresenter::report_user_view( $rep, $case );
		}
		return $this->ok( $items );
	}
}
