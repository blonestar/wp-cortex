<?php
/**
 * REST API of the lead integrations.
 *
 * @package WPCortex
 */

namespace WPCortex\Rest;

use WPCortex\Integrations\Dispatcher;
use WPCortex\Integrations\IntegrationRegistry;
use WPCortex\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-cortex/v1/integrations/<id>/test: sends a made-up lead with the settings in the
 * form (saved or not), for the Send test lead button under Settings > Leads > Integrations.
 */
final class IntegrationController {

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
			'/integrations/(?P<id>[a-z0-9_-]{1,40})/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'settings' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
	}

	/**
	 * Only administrators, and only while leads are on.
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
	 * POST /integrations/<id>/test
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test( WP_REST_Request $request ) {
		$integration = IntegrationRegistry::get( (string) $request['id'] );

		if ( ! $integration ) {
			return new WP_Error( 'wp_cortex_integration_not_found', __( 'Integration not found.', 'wp-cortex' ), array( 'status' => 404 ) );
		}

		// A secret left empty in the form is the saved one.
		$saved    = (array) Settings::get( 'integrations' );
		$stored   = is_array( $saved[ $integration->id() ] ?? null ) ? $saved[ $integration->id() ] : array();
		$settings = IntegrationRegistry::sanitize_one( $integration, (array) $request->get_param( 'settings' ), $stored );
		$result   = Dispatcher::test( $integration, $settings );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'sent' => true ) );
	}
}
