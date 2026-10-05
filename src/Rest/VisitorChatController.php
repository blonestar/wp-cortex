<?php
/**
 * REST API for stored visitor chats.
 *
 * @package WPCortex
 */

namespace WPCortex\Rest;

use WPCortex\Chat\VisitorChatMailer;
use WPCortex\Chat\VisitorChatStore;
use WPCortex\Chat\VisitorChatSummarizer;
use WPCortex\Chat\VisitorImages;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints under /wp-cortex/v1/visitor-chats used by the Visitor chats screen.
 */
final class VisitorChatController {

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
		$fields     = array(
			'is_read'    => array( 'type' => 'boolean' ),
			'admin_note' => array(
				'type'      => 'string',
				'maxLength' => VisitorChatStore::MAX_NOTE * 2,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/visitor-chats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_chats' ),
				'permission_callback' => $permission,
				'args'                => array(
					'filter'   => array(
						'type'    => 'string',
						'enum'    => array( '', VisitorChatStore::FILTER_UNREAD, VisitorChatStore::FILTER_CONTACT ),
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
			'/visitor-chats/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => $permission,
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'enum'     => array( 'read', 'unread', 'delete' ),
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
			'/visitor-chats/(?P<id>\d+)/summary',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'summarize' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/visitor-chats/(?P<id>\d+)/images/(?P<name>' . VisitorImages::NAME_PATTERN . ')',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_image' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/visitor-chats/(?P<id>\d+)/forward',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'forward' ),
				'permission_callback' => $permission,
				'args'                => array(
					'to'      => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 2000,
					),
					'message'   => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => VisitorChatMailer::MAX_MESSAGE,
					),
					'summarize' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/visitor-chats/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_chat' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_chat' ),
					'permission_callback' => $permission,
					'args'                => $fields,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_chat' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	/**
	 * Only administrators may read visitor chats.
	 */
	public function permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /visitor-chats
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_chats( WP_REST_Request $request ) {
		$store    = new VisitorChatStore();
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = $store->query(
			(string) $request->get_param( 'filter' ),
			(string) $request->get_param( 'search' ),
			(int) $request->get_param( 'page' ),
			$per_page
		);

		return rest_ensure_response(
			array(
				'chats'  => $result['chats'],
				'total'  => $result['total'],
				'pages'  => max( 1, (int) ceil( $result['total'] / $per_page ) ),
				'counts' => $store->counts(),
			)
		);
	}

	/**
	 * GET /visitor-chats/<id>
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_chat( WP_REST_Request $request ) {
		$chat = ( new VisitorChatStore() )->get( (int) $request['id'] );

		if ( null === $chat ) {
			return $this->not_found();
		}

		return rest_ensure_response( $chat );
	}

	/**
	 * GET /visitor-chats/<id>/images/<name>: an image the visitor attached, as base64.
	 *
	 * Images live in the protected data directory and are never linked directly; the
	 * admin screen loads them through this route (with the REST nonce) instead.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_image( WP_REST_Request $request ) {
		$name  = (string) $request['name'];
		$path  = VisitorImages::path( (int) $request['id'], $name );
		$bytes = '' !== $path ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $bytes ) {
			return new WP_Error( 'wp_cortex_not_found', __( 'Image not found.', 'wp-cortex' ), array( 'status' => 404 ) );
		}

		$response = rest_ensure_response(
			array(
				'name' => $name,
				'mime' => VisitorImages::mime( $name ),
				'data' => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			)
		);
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}

	/**
	 * PUT/PATCH /visitor-chats/<id>: read state and administrator note.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_chat( WP_REST_Request $request ) {
		$store = new VisitorChatStore();
		$id    = (int) $request['id'];
		$data  = array();

		foreach ( array( 'is_read', 'admin_note' ) as $key ) {
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
	 * DELETE /visitor-chats/<id>
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_chat( WP_REST_Request $request ) {
		if ( ! ( new VisitorChatStore() )->delete( array( (int) $request['id'] ) ) ) {
			return $this->not_found();
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /visitor-chats/<id>/summary: (re)generates the AI summary.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function summarize( WP_REST_Request $request ) {
		$store = new VisitorChatStore();
		$id    = (int) $request['id'];
		$chat  = $store->get( $id );

		if ( null === $chat ) {
			return $this->not_found();
		}

		$summary = ( new VisitorChatSummarizer() )->summarize( $chat );

		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$store->save_summary( $id, $summary );

		return rest_ensure_response( $store->get( $id ) );
	}

	/**
	 * POST /visitor-chats/<id>/forward: emails the conversation, by default with a summary
	 * generated first when it is missing or outdated.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function forward( WP_REST_Request $request ) {
		$store = new VisitorChatStore();
		$id    = (int) $request['id'];
		$chat  = $store->get( $id );

		if ( null === $chat ) {
			return $this->not_found();
		}

		$to = VisitorChatMailer::parse_recipients( (string) $request->get_param( 'to' ) );

		if ( is_wp_error( $to ) ) {
			return $to;
		}

		// Include an up-to-date summary: generate it when missing or outdated.
		if ( $request->get_param( 'summarize' ) && ( '' === $chat['summary'] || $chat['summary_stale'] ) ) {
			$summary = ( new VisitorChatSummarizer() )->summarize( $chat );

			if ( is_wp_error( $summary ) ) {
				return new WP_Error(
					$summary->get_error_code(),
					/* translators: %s: error message. */
					sprintf( __( 'The summary could not be generated, so nothing was sent: %s Uncheck "Include an up-to-date summary" to send without it.', 'wp-cortex' ), $summary->get_error_message() ),
					array( 'status' => 502 )
				);
			}

			$store->save_summary( $id, $summary );
			$chat = $store->get( $id );
		}

		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$sent    = ( new VisitorChatMailer() )->forward( $chat, $to, $message );

		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		$store->mark_forwarded( $id, $to );

		return rest_ensure_response( $store->get( $id ) );
	}

	/**
	 * POST /visitor-chats/bulk: mark as read or unread, or delete.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function bulk( WP_REST_Request $request ) {
		$store  = new VisitorChatStore();
		$ids    = (array) $request->get_param( 'ids' );
		$action = (string) $request->get_param( 'action' );

		if ( 'delete' === $action ) {
			$count = $store->delete( $ids );
		} else {
			$count = $store->update_admin( $ids, array( 'is_read' => 'read' === $action ) );
		}

		return rest_ensure_response( array( 'updated' => $count ) );
	}

	/**
	 * Error for an unknown conversation.
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'wp_cortex_not_found', __( 'Conversation not found.', 'wp-cortex' ), array( 'status' => 404 ) );
	}
}
