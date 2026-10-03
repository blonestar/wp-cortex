<?php
/**
 * REST API for the visitor chat.
 *
 * @package WPCortex
 */

namespace WPCortex\Rest;

use WPCortex\Chat\PublicChatAgent;
use WPCortex\Chat\RateLimiter;
use WPCortex\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The only endpoint open to visitors: POST /wp-cortex/v1/public-chat/message.
 *
 * It answers from the public index only, is available only while the visitor chat is
 * enabled and is limited per client IP.
 */
final class PublicChatController {

	private const NAMESPACE = 'wp-cortex/v1';

	/**
	 * Hooks route registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/public-chat/message',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'message' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'message' => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
						'maxLength' => PublicChatAgent::MAX_MESSAGE_LENGTH,
					),
					'history' => array(
						'type'     => 'array',
						'default'  => array(),
						'maxItems' => 50,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'role' => array(
									'type' => 'string',
									'enum' => array( 'user', 'assistant' ),
								),
								'text' => array( 'type' => 'string' ),
							),
						),
					),
					'post_id' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);
	}

	/**
	 * Open to everyone while the visitor chat is enabled.
	 *
	 * @return true|WP_Error
	 */
	public function permission() {
		if ( ! Settings::get( 'public_chat_enabled' ) ) {
			return new WP_Error( 'wp_cortex_public_chat_disabled', __( 'The chat is not available.', 'wp-cortex' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * POST /public-chat/message
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function message( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) && ! RateLimiter::allow( (int) Settings::get( 'public_chat_rate_limit' ) ) ) {
			return new WP_Error( 'wp_cortex_rate_limited', __( 'You have sent too many messages. Please try again later.', 'wp-cortex' ), array( 'status' => 429 ) );
		}

		$history = $request->get_param( 'history' );

		$result = ( new PublicChatAgent() )->respond(
			(string) $request->get_param( 'message' ),
			is_array( $history ) ? $history : array(),
			absint( $request->get_param( 'post_id' ) )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
