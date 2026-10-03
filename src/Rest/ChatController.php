<?php
/**
 * REST API for the admin chat assistant.
 *
 * @package WPCortex
 */

namespace WPCortex\Rest;

use WPCortex\Chat\ChatAgent;
use WPCortex\Chat\ConversationStore;
use WPCortex\Chat\ModelCatalog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints under /wp-cortex/v1/chat used by the chat panel.
 */
final class ChatController {

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
			'/chat/conversations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_conversations' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/chat/conversations/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conversation' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_conversation' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/chat/models',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'models' ),
				'permission_callback' => $permission,
				'args'                => array(
					'provider' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn( $value ): bool => array_key_exists( (string) $value, ChatAgent::providers() ),
					),
					'refresh'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/chat/message',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'message' ),
				'permission_callback' => $permission,
				'args'                => array(
					'conversation_id' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'message'         => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
						'maxLength' => 4000,
					),
					'context'         => array(
						'type'       => 'object',
						'default'    => array(),
						'properties' => array(
							'screen'  => array( 'type' => 'string' ),
							'post_id' => array( 'type' => 'integer' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Only administrators may use the chat.
	 */
	public function permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /chat/conversations
	 *
	 * @return WP_REST_Response
	 */
	public function list_conversations() {
		return rest_ensure_response(
			array( 'conversations' => ( new ConversationStore() )->list_for_user( get_current_user_id() ) )
		);
	}

	/**
	 * GET /chat/conversations/<id>
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_conversation( WP_REST_Request $request ) {
		$conversation = ( new ConversationStore() )->get( (int) $request['id'], get_current_user_id() );

		if ( null === $conversation ) {
			return new WP_Error( 'wp_cortex_not_found', __( 'Conversation not found.', 'wp-cortex' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'id'         => $conversation['id'],
				'title'      => $conversation['title'],
				'transcript' => $conversation['transcript'],
			)
		);
	}

	/**
	 * DELETE /chat/conversations/<id>
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_conversation( WP_REST_Request $request ) {
		if ( ! ( new ConversationStore() )->delete( (int) $request['id'], get_current_user_id() ) ) {
			return new WP_Error( 'wp_cortex_not_found', __( 'Conversation not found.', 'wp-cortex' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * GET /chat/models
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function models( WP_REST_Request $request ) {
		$provider = (string) $request->get_param( 'provider' );
		$refresh  = rest_sanitize_boolean( $request->get_param( 'refresh' ) );
		$catalog  = new ModelCatalog();
		$cached   = ! $refresh && null !== $catalog->cached( $provider );
		$models   = $catalog->models( $provider, $refresh );

		if ( is_wp_error( $models ) ) {
			return $models;
		}

		return rest_ensure_response(
			array(
				'provider' => $provider,
				'models'   => $models,
				'cached'   => $cached,
			)
		);
	}

	/**
	 * POST /chat/message
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function message( WP_REST_Request $request ) {
		$context = $request->get_param( 'context' );
		$context = is_array( $context ) ? $context : array();

		$result = ( new ChatAgent() )->respond(
			(int) $request->get_param( 'conversation_id' ),
			(string) $request->get_param( 'message' ),
			array(
				'screen'  => sanitize_text_field( (string) ( $context['screen'] ?? '' ) ),
				'post_id' => absint( $context['post_id'] ?? 0 ),
			),
			get_current_user_id()
		);

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
