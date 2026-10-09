<?php
/**
 * REST API for the visitor chat.
 *
 * @package WPCortex
 */

namespace WPCortex\Rest;

use WPCortex\Chat\ClientIp;
use WPCortex\Chat\IssueReportStore;
use WPCortex\Chat\PublicChatAgent;
use WPCortex\Chat\RateLimiter;
use WPCortex\Chat\VisitorChatStore;
use WPCortex\Chat\VisitorImages;
use WPCortex\Leads\Attribution;
use WPCortex\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The endpoints open to visitors: POST /wp-cortex/v1/public-chat/message and
 * POST /wp-cortex/v1/public-chat/presence.
 *
 * The message endpoint answers from the public index only, is available only while the
 * visitor chat is enabled and is limited per client IP. While the conversation log is
 * on, each turn is appended to the conversation of the browser's session token, with the
 * visitor's image (when images are on) and the marketing attribution the widget sends
 * (when lead attribution is on, Leads\Attribution) stored next to it, and the presence endpoint
 * records whether that conversation's chat window is still open.
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
	 * Registers the routes.
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
					'message'     => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => PublicChatAgent::MAX_MESSAGE_LENGTH,
					),
					'image'       => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => VisitorImages::MAX_DATA_URL,
					),
					'history'     => array(
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
					'post_id'     => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'session'     => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^([a-f0-9]{32})?$',
					),
					'page_url'    => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => IssueReportStore::MAX_URL,
					),
					'attribution' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/public-chat/presence',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'presence' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'session' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-f0-9]{32}$',
					),
					'open'    => array(
						'type'    => 'boolean',
						'default' => true,
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

		// Stored before the turn, so issue reports of this turn can refer to the image.
		$stored = $image && $chat_id > 0 ? VisitorImages::store( $chat_id, $image ) : '';
		$result = ( new PublicChatAgent() )->respond( $message, is_array( $history ) ? $history : array(), $post_id, $chat_id, $image, (string) $request->get_param( 'page_url' ), $stored );

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

			if ( '' !== $stored ) {
				$visitor['image'] = $stored;
			}

			$log = array( array_merge( $visitor, array_filter( $client ) ) );

			foreach ( $result['items'] as $item ) {
				$log[] = array_merge( $item, array( 'at' => $now ) );
			}

			$store->append( $chat_id, $log, $client );

			$attribution = Attribution::enabled() ? Attribution::from_request( $request->get_param( 'attribution' ) ) : null;

			if ( $attribution ) {
				$store->save_attribution( $chat_id, $attribution );
			}
		}

		// Notices are for the log: the assistant confirms in the visitor's language.
		$result['items'] = array_values( array_filter( $result['items'], static fn( $item ) => 'notice' !== ( $item['role'] ?? '' ) ) );

		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * POST /public-chat/presence: the widget reports that its chat window is open (every
	 * minute while visible) or was closed, so the Visitor chats screen can tell whether
	 * the visitor is still there. Only touches the existing conversation of the session
	 * token; the response is the same whether or not it exists.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function presence( WP_REST_Request $request ) {
		if ( Settings::get( 'public_chat_log' ) ) {
			( new VisitorChatStore() )->touch( (string) $request->get_param( 'session' ), (bool) $request->get_param( 'open' ) );
		}

		$response = rest_ensure_response( array( 'ok' => true ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
