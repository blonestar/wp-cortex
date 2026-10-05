<?php
/**
 * REST API for issue reports.
 *
 * @package WPCortex
 */

namespace WPCortex\Rest;

use WPCortex\Chat\IssueReportStore;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints under /wp-cortex/v1/issue-reports used by the Issue reports screen.
 */
final class IssueReportController {

	private const NAMESPACE = 'wp-cortex/v1';

	/**
	 * Hooks route registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$permission = array( $this, 'permission' );

		register_rest_route(
			self::NAMESPACE,
			'/issue-reports',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_reports' ),
				'permission_callback' => $permission,
				'args'                => array(
					'status'   => array(
						'type'    => 'string',
						'enum'    => array_merge( array( '' ), IssueReportStore::STATUSES ),
						'default' => '',
					),
					'search'   => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 200,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/issue-reports/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => $permission,
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'enum'     => array_merge( IssueReportStore::STATUSES, array( 'delete' ) ),
						'required' => true,
					),
					'ids'    => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
						'maxItems' => 100,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/issue-reports/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_report' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_report' ),
					'permission_callback' => $permission,
					'args'                => array(
						'status'     => array(
							'type' => 'string',
							'enum' => IssueReportStore::STATUSES,
						),
						'admin_note' => array(
							'type'      => 'string',
							'maxLength' => IssueReportStore::MAX_NOTE * 2,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_report' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	/**
	 * Only administrators may read issue reports.
	 */
	public function permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /issue-reports
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_reports( WP_REST_Request $request ) {
		$store    = new IssueReportStore();
		$per_page = (int) $request->get_param( 'per_page' );
		$result = $store->query(
			(string) $request->get_param( 'status' ),
			(string) $request->get_param( 'search' ),
			(int) $request->get_param( 'page' ),
			$per_page
		);

		return rest_ensure_response(
			array(
				'reports' => $result['reports'],
				'total'   => $result['total'],
				'pages'   => max( 1, (int) ceil( $result['total'] / $per_page ) ),
				'counts'  => $store->counts(),
			)
		);
	}

	/**
	 * GET /issue-reports/<id>
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_report( WP_REST_Request $request ) {
		$report = ( new IssueReportStore() )->get( (int) $request['id'] );

		if ( null === $report ) {
			return $this->not_found();
		}

		return rest_ensure_response( $report );
	}

	/**
	 * PUT/PATCH /issue-reports/<id>: status and administrator note.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_report( WP_REST_Request $request ) {
		$store = new IssueReportStore();
		$id    = (int) $request['id'];
		$data  = array();

		foreach ( array( 'status', 'admin_note' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$data[ $key ] = $request->get_param( $key );
			}
		}

		if ( ! $store->update_admin( array( $id ), $data ) ) {
			return $this->not_found();
		}

		return rest_ensure_response( $store->get( $id ) );
	}

	/**
	 * DELETE /issue-reports/<id>
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_report( WP_REST_Request $request ) {
		if ( ! ( new IssueReportStore() )->delete( array( (int) $request['id'] ) ) ) {
			return $this->not_found();
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /issue-reports/bulk: change the status or delete.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function bulk( WP_REST_Request $request ) {
		$store  = new IssueReportStore();
		$ids    = (array) $request->get_param( 'ids' );
		$action = (string) $request->get_param( 'action' );

		if ( 'delete' === $action ) {
			$count = $store->delete( $ids );
		} else {
			$count = $store->update_admin( $ids, array( 'status' => $action ) );
		}

		return rest_ensure_response( array( 'updated' => $count ) );
	}

	/**
	 * Error for an unknown report.
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'wp_cortex_not_found', __( 'Issue report not found.', 'wp-cortex' ), array( 'status' => 404 ) );
	}
}
