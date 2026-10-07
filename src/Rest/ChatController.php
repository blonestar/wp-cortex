<?php
/**
 * REST API for the admin chat assistant.
 *
 * @package WPCortex
 */

namespace WPCortex\Rest;

use WPCortex\Admin\AdminPages;
use WPCortex\Chat\ChatAgent;
use WPCortex\Chat\ConversationStore;
use WPCortex\Chat\ModelCatalog;
use WPCortex\Chat\SkillStore;
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
		$context    = array(
			'type'       => 'object',
			'default'    => array(),
			'properties' => array(
				'screen'      => array( 'type' => 'string' ),
				'post_id'     => array( 'type' => 'integer' ),
				'admin_pages' => array(
					'type'     => 'array',
					'maxItems' => AdminPages::MAX_PAGES,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'path'  => array( 'type' => 'string' ),
							'label' => array( 'type' => 'string' ),
						),
					),
				),
				'tabs'        => array(
					'type'     => 'array',
					'maxItems' => AdminPages::MAX_TABS,
					'items'    => array( 'type' => 'string' ),
				),
			),
		);

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
			'/chat/conversations/(?P<id>\d+)/skill-proposals/(?P<proposal>[a-fA-F0-9-]{32,36})',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resolve_skill_proposal' ),
				'permission_callback' => $permission,
				'args'                => array(
					'status'   => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => array( 'saved', 'dismissed' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'skill_id' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/chat/conversations/(?P<id>\d+)/actions/(?P<action>[a-fA-F0-9-]{36})',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resolve_action' ),
				'permission_callback' => $permission,
				'args'                => array(
					'decision' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'run', 'cancel' ),
					),
					'context'  => $context,
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
					'context'         => $context,
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
				'transcript' => $this->decorate_legacy_skill_proposals( $conversation['transcript'] ),
			)
		);
	}

	/**
	 * POST /chat/conversations/<id>/skill-proposals/<proposal>: records the user's
	 * decision so a resolved proposal is not rendered as an active form again.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resolve_skill_proposal( WP_REST_Request $request ) {
		$status   = (string) $request->get_param( 'status' );
		$skill    = array();
		$skill_id = absint( $request->get_param( 'skill_id' ) );

		if ( ! in_array( $status, array( 'saved', 'dismissed' ), true ) ) {
			return new WP_Error( 'wp_cortex_invalid_status', __( 'Invalid skill proposal status.', 'wp-cortex' ), array( 'status' => 400 ) );
		}

		if ( 'saved' === $status ) {
			$skill = ( new SkillStore() )->get( $skill_id );

			if ( null === $skill ) {
				return new WP_Error( 'wp_cortex_not_found', __( 'Skill not found.', 'wp-cortex' ), array( 'status' => 404 ) );
			}
		}

		$updated = ( new ConversationStore() )->resolve_skill_proposal(
			(int) $request['id'],
			get_current_user_id(),
			(string) $request['proposal'],
			$status,
			$skill
		);

		if ( ! $updated ) {
			return new WP_Error( 'wp_cortex_not_found', __( 'Skill proposal not found.', 'wp-cortex' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'resolved' => true, 'status' => $status ) );
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
		$result = ( new ChatAgent() )->respond(
			(int) $request->get_param( 'conversation_id' ),
			(string) $request->get_param( 'message' ),
			$this->screen_context( $request ),
			get_current_user_id()
		);

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /chat/conversations/<id>/actions/<action>: runs or cancels an action card
	 * (an ability that may change the site) and returns the assistant's answer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resolve_action( WP_REST_Request $request ) {
		$result = ( new ChatAgent() )->resolve_action(
			(int) $request['id'],
			strtolower( (string) $request['action'] ),
			'run' === $request->get_param( 'decision' ),
			$this->screen_context( $request ),
			get_current_user_id()
		);

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Sanitized screen context sent by the chat panel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{screen: string, post_id: int, admin_pages: array, tabs: array}
	 */
	private function screen_context( WP_REST_Request $request ): array {
		$context = $request->get_param( 'context' );
		$context = is_array( $context ) ? $context : array();

		return array(
			'screen'      => sanitize_text_field( (string) ( $context['screen'] ?? '' ) ),
			'post_id'     => absint( $context['post_id'] ?? 0 ),
			'admin_pages' => AdminPages::sanitize( $context['admin_pages'] ?? array() ),
			'tabs'        => AdminPages::sanitize_tabs( $context['tabs'] ?? array() ),
		);
	}

	/**
	 * Gives old proposal items a safe read-only state when their skill is already
	 * present. New proposals carry an explicit status and bypass this fallback.
	 *
	 * @param array $transcript Transcript items.
	 * @return array Transcript items with legacy states decorated.
	 */
	private function decorate_legacy_skill_proposals( array $transcript ): array {
		$skills = new SkillStore();

		foreach ( $transcript as &$item ) {
			if ( 'skill_proposal' !== ( $item['role'] ?? '' ) || ! is_array( $item['skill'] ?? null ) ) {
				continue;
			}

			if ( ! array_key_exists( 'proposal_id', $item['skill'] ) ) {
				$item['skill']['proposal_id'] = ConversationStore::skill_proposal_id( $item['skill'] );
			}

			if ( array_key_exists( 'status', $item['skill'] ) ) {
				continue;
			}

			$current = $skills->get_by_name( (string) ( $item['skill']['name'] ?? '' ) );

			if ( null === $current ) {
				continue;
			}

			$existing_id = (int) ( $item['skill']['existing_id'] ?? 0 );
			$same_value  = $current['name'] === (string) ( $item['skill']['name'] ?? '' )
				&& $current['description'] === (string) ( $item['skill']['description'] ?? '' )
				&& $current['instructions'] === (string) ( $item['skill']['instructions'] ?? '' );

			// A new proposal could only have become an existing skill after the
			// proposal was shown. For an update proposal, require an exact match so
			// an unsaved change is still actionable.
			if ( 0 !== $existing_id && ( $existing_id !== $current['id'] || ! $same_value ) ) {
				continue;
			}

			$item['skill']['status']       = 'saved';
			$item['skill']['skill_id']     = $current['id'];
			$item['skill']['legacy']       = true;
			$item['skill']['name']         = $current['name'];
			$item['skill']['description']  = $current['description'];
			$item['skill']['instructions'] = $current['instructions'];
		}
		unset( $item );

		return $transcript;
	}
}
