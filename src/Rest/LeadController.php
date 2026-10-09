<?php
/**
 * REST API of the Leads screen.
 *
 * @package WPCortex
 */

namespace WPCortex\Rest;

use WPCortex\Chat\VisitorChatStore;
use WPCortex\Leads\Attribution;
use WPCortex\Leads\LeadPayload;
use WPCortex\Leads\LeadQualifier;
use WPCortex\Leads\LeadReport;
use WPCortex\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints under /wp-cortex/v1/leads: the report, the leads list (also for the CSV
 * export) and the AI rating of leads. Lead statuses are changed through
 * PATCH /visitor-chats/<id>.
 */
final class LeadController {

	private const NAMESPACE = 'wp-cortex/v1';

	/**
	 * Most leads returned by the export.
	 */
	private const MAX_EXPORT = 5000;

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
		$days       = array(
			'type'    => 'integer',
			'enum'    => LeadReport::PERIODS,
			'default' => 30,
		);
		$channel    = array(
			'type'    => 'string',
			'enum'    => array_merge( array( '', 'unknown' ), Attribution::CHANNELS ),
			'default' => '',
		);
		$filters    = array(
			'days'    => $days,
			'channel' => $channel,
			'status'  => array(
				'type'    => 'string',
				'enum'    => array_merge( array( '' ), VisitorChatStore::LEAD_STATUSES ),
				'default' => '',
			),
			'rating'  => array(
				'type'    => 'string',
				'enum'    => array_merge( array( '', 'unrated' ), VisitorChatStore::LEAD_RATINGS ),
				'default' => '',
			),
			'search'  => array(
				'type'      => 'string',
				'default'   => '',
				'maxLength' => 200,
			),
			'orderby' => array(
				'type'    => 'string',
				'enum'    => array( 'lead_at', 'score', 'channel', 'campaign', 'status' ),
				'default' => 'lead_at',
			),
			'order'   => array(
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc' ),
				'default' => 'desc',
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/leads/report',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'report' ),
				'permission_callback' => $permission,
				'args'                => array(
					'days'    => $days,
					'channel' => $channel,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/leads',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_leads' ),
				'permission_callback' => $permission,
				'args'                => array_merge(
					$filters,
					array(
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
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/leads/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $permission,
				'args'                => $filters,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/leads/rate-next',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rate_next' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/leads/(?P<id>\d+)/rate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rate' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Only administrators may read leads, and only while leads are on.
	 *
	 * @return bool|WP_Error
	 */
	public function permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! Settings::leads_enabled() ) {
			return new WP_Error( 'wp_cortex_leads_disabled', __( 'Leads are turned off in Cortex > Settings > Leads.', 'wp-cortex' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * GET /leads/report
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function report( WP_REST_Request $request ) {
		return rest_ensure_response( ( new LeadReport() )->build( (int) $request->get_param( 'days' ), (string) $request->get_param( 'channel' ) ) );
	}

	/**
	 * GET /leads
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_leads( WP_REST_Request $request ) {
		$store    = new VisitorChatStore();
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = $store->query_leads( $this->args( $request, (int) $request->get_param( 'page' ), $per_page ) );

		return rest_ensure_response(
			array(
				'leads'  => $result['leads'],
				'total'  => $result['total'],
				'pages'  => max( 1, (int) ceil( $result['total'] / $per_page ) ),
				'counts' => $store->lead_status_counts(),
			)
		);
	}

	/**
	 * GET /leads/export: every lead matching the filters (up to MAX_EXPORT) as lead
	 * payloads (LeadPayload::build()), for the CSV file the screen builds.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function export( WP_REST_Request $request ) {
		$result = ( new VisitorChatStore() )->query_leads( array_merge( $this->args( $request, 1, self::MAX_EXPORT ), array( 'attribution' => true ) ) );

		return rest_ensure_response(
			array(
				'leads'     => array_map( array( LeadPayload::class, 'build' ), $result['leads'] ),
				'total'     => $result['total'],
				'truncated' => $result['total'] > self::MAX_EXPORT,
			)
		);
	}

	/**
	 * POST /leads/<id>/rate: (re)rates a lead with the AI model.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rate( WP_REST_Request $request ) {
		$store = new VisitorChatStore();
		$id    = (int) $request['id'];
		$error = $this->rate_one( $store, $id );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		return rest_ensure_response( $store->get( $id ) );
	}

	/**
	 * POST /leads/rate-next: rates the newest lead without a rating, so the screen can
	 * rate them one request at a time.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rate_next() {
		$store = new VisitorChatStore();
		$ids   = $store->unrated_lead_ids( 2 );

		if ( ! $ids ) {
			return rest_ensure_response(
				array(
					'rated'     => 0,
					'remaining' => 0,
				)
			);
		}

		$error = $this->rate_one( $store, $ids[0] );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		return rest_ensure_response(
			array(
				'rated'     => $ids[0],
				'remaining' => count( $store->unrated_lead_ids( 1 ) ),
			)
		);
	}

	/**
	 * Rates one lead and stores the rating.
	 *
	 * @param VisitorChatStore $store Store.
	 * @param int              $id    Conversation ID.
	 * @return true|WP_Error
	 */
	private function rate_one( VisitorChatStore $store, int $id ) {
		$chat = $store->get( $id );

		if ( null === $chat || '' === $chat['lead_at'] ) {
			return new WP_Error( 'wp_cortex_not_found', __( 'Lead not found.', 'wp-cortex' ), array( 'status' => 404 ) );
		}

		$rating = ( new LeadQualifier() )->qualify( $chat );

		if ( is_wp_error( $rating ) ) {
			return $rating;
		}

		$store->save_qualification( $id, $rating );

		return true;
	}

	/**
	 * Arguments of VisitorChatStore::query_leads() from the request filters.
	 *
	 * @param WP_REST_Request $request  Request.
	 * @param int             $page     Page.
	 * @param int             $per_page Rows per page.
	 * @return array<string, mixed>
	 */
	private function args( WP_REST_Request $request, int $page, int $per_page ): array {
		$days = (int) $request->get_param( 'days' );

		return array(
			'from'     => $days > 0 ? gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) : '',
			'status'   => (string) $request->get_param( 'status' ),
			'rating'   => (string) $request->get_param( 'rating' ),
			'channel'  => (string) $request->get_param( 'channel' ),
			'search'   => (string) $request->get_param( 'search' ),
			'orderby'  => (string) $request->get_param( 'orderby' ),
			'order'    => (string) $request->get_param( 'order' ),
			'page'     => $page,
			'per_page' => $per_page,
		);
	}
}
