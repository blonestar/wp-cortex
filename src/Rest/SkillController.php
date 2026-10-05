<?php
/**
 * REST API for chat skills.
 *
 * @package WPCortex
 */

namespace WPCortex\Rest;

use WPCortex\Chat\SkillStore;
use WPCortex\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints under /wp-cortex/v1/skills used by the Skills screen and the chat panel.
 */
final class SkillController {

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
			'name'         => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'description'  => array(
				'type'      => 'string',
				'maxLength' => SkillStore::MAX_DESCRIPTION * 2,
			),
			'instructions' => array(
				'type'      => 'string',
				'maxLength' => SkillStore::MAX_INSTRUCTIONS * 2,
			),
			'active'       => array( 'type' => 'boolean' ),
		);

		register_rest_route(
			self::NAMESPACE,
			'/skills',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_skills' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_skill' ),
					'permission_callback' => $permission,
					'args'                => array_merge(
						$fields,
						array(
							'source' => array(
								'type'    => 'string',
								'enum'    => array( SkillStore::SOURCE_USER, SkillStore::SOURCE_AGENT ),
								'default' => SkillStore::SOURCE_USER,
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/skills/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_skill' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_skill' ),
					'permission_callback' => $permission,
					'args'                => $fields,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_skill' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	/**
	 * Only administrators may manage skills, and only while skills are enabled.
	 *
	 * @return bool|WP_Error
	 */
	public function permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! Settings::skills_enabled() ) {
			return new WP_Error( 'wp_cortex_skills_disabled', __( 'Skills are turned off in Cortex > Settings > Admin chat.', 'wp-cortex' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * GET /skills
	 *
	 * @return WP_REST_Response
	 */
	public function list_skills() {
		return rest_ensure_response( array( 'skills' => ( new SkillStore() )->all() ) );
	}

	/**
	 * GET /skills/<id>
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_skill( WP_REST_Request $request ) {
		$skill = ( new SkillStore() )->get( (int) $request['id'] );

		if ( null === $skill ) {
			return $this->not_found();
		}

		return rest_ensure_response( $skill );
	}

	/**
	 * POST /skills
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_skill( WP_REST_Request $request ) {
		$store = new SkillStore();
		$id    = $store->create( $this->fields( $request, array( 'name', 'description', 'instructions', 'active', 'source' ) ), get_current_user_id() );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$response = rest_ensure_response( $store->get( $id ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * PUT/PATCH /skills/<id>: only the fields sent are changed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_skill( WP_REST_Request $request ) {
		$store  = new SkillStore();
		$id     = (int) $request['id'];
		$result = $store->update( $id, $this->fields( $request, array( 'name', 'description', 'instructions', 'active' ) ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $store->get( $id ) );
	}

	/**
	 * DELETE /skills/<id>
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_skill( WP_REST_Request $request ) {
		if ( ! ( new SkillStore() )->delete( (int) $request['id'] ) ) {
			return $this->not_found();
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Request fields that were sent (JSON or form body).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string[]        $keys    Allowed keys.
	 * @return array<string, mixed>
	 */
	private function fields( WP_REST_Request $request, array $keys ): array {
		$fields = array();

		foreach ( $keys as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$fields[ $key ] = $request->get_param( $key );
			}
		}

		return $fields;
	}

	/**
	 * Error for an unknown skill.
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'wp_cortex_not_found', __( 'Skill not found.', 'wp-cortex' ), array( 'status' => 404 ) );
	}
}
