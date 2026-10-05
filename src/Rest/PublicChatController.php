<?php
/**
 * REST API for the visitor chat.
 *
 * @package WPCortex
 */

namespace WPCortex\Rest;

use WPCortex\Chat\ClientIp;
use WPCortex\Chat\PublicChatAgent;
use WPCortex\Chat\RateLimiter;
use WPCortex\Chat\VisitorChatStore;
use WPCortex\Chat\VisitorImages;
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
 * enabled and is limited per client IP. While the conversation log is on, each turn is
 * appended to the conversation of the browser's session token, with the visitor's image
 * (when images are on) stored next to it.
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
						'default'   => '',
						'maxLength' => PublicChatAgent::MAX_MESSAGE_LENGTH,
					),
					'image'   => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => VisitorImages::MAX_DATA_URL,
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
								'text'  => array( 'type' => 'string' ),
								'image' => array( 'type' => 'boolean' ),
							),
						),
					),
					'post_id' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'session' => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^([a-f0-9]{32})?$',
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
		$history  = $request->get_param( 'history' );
		$message  = trim( (string) $request->get_param( 'message' ) );
		$data_url = (string) $request->get_param( 'image' );
		$post_id  = absint( $request->get_param( 'post_id' ) );

		if ( '' === $message && '' === $data_url ) {
			return new WP_Error( 'wp_cortex_empty_message', __( 'The message is empty.', 'wp-cortex' ), array( 'status' => 400 ) );
		}

		if ( '' !== $data_url && ! VisitorImages::enabled() ) {
			return new WP_Error( 'wp_cortex_images_disabled', __( 'Images are not accepted in this chat.', 'wp-cortex' ), array( 'status' => 400 ) );
		}

		if ( ! current_user_can( 'manage_options' ) && ! RateLimiter::allow( (int) Settings::get( 'public_chat_rate_limit' ) ) ) {
			return new WP_Error( 'wp_cortex_rate_limited', __( 'You have sent too many messages. Please try again later.', 'wp-cortex' ), array( 'status' => 429 ) );
		}

		if ( '' !== $data_url && ! current_user_can( 'manage_options' ) && ! RateLimiter::allow( (int) Settings::get( 'public_chat_image_limit' ), 'image' ) ) {
			return new WP_Error( 'wp_cortex_rate_limited', __( 'You have sent too many images. Please try again later or describe the problem in words.', 'wp-cortex' ), array( 'status' => 429 ) );
		}

		$image = null;

		if ( '' !== $data_url ) {
			$image = VisitorImages::process( $data_url );

			if ( is_wp_error( $image ) ) {
				return $image;
			}
		}

		$store   = new VisitorChatStore();
		$chat_id = Settings::get( 'public_chat_log' ) ? $store->open( (string) $request->get_param( 'session' ), $post_id ) : 0;

		$result = ( new PublicChatAgent() )->respond( $message, is_array( $history ) ? $history : array(), $post_id, $chat_id, $image );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $chat_id > 0 ) {
			$now     = current_time( 'mysql', true );
			$client  = Settings::get( 'public_chat_store_ip' ) ? ClientIp::details() : array();
			$visitor = array(
				'role'    => 'user',
				'text'    => mb_substr( $message, 0, PublicChatAgent::MAX_MESSAGE_LENGTH ),
				'post_id' => $post_id,
				'at'      => $now,
			);

			$stored = $image ? VisitorImages::store( $chat_id, $image ) : '';

			if ( '' !== $stored ) {
				$visitor['image'] = $stored;
			}

			$log = array( array_merge( $visitor, array_filter( $client ) ) );

			foreach ( $result['items'] as $item ) {
				$log[] = array_merge( $item, array( 'at' => $now ) );
			}

			$store->append( $chat_id, $log, $client );
		}

		// Notices are for the log: the assistant confirms in the visitor's language.
		$result['items'] = array_values( array_filter( $result['items'], static fn( $item ) => 'notice' !== ( $item['role'] ?? '' ) ) );

		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
