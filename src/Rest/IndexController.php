<?php
/**
 * REST API for driving and monitoring indexing runs.
 *
 * @package WPCortex
 */

namespace WPCortex\Rest;

use WPCortex\Indexing\IndexRun;
use WPCortex\Indexing\Indexer;
use WPCortex\Storage\Database;
use WPCortex\Storage\Storage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints under /wp-cortex/v1/index used by Settings > Indexing > Status & stats.
 */
final class IndexController {

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
			'/index',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/index/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start' ),
				'permission_callback' => $permission,
				'args'                => array(
					'mode' => array(
						'type'    => 'string',
						'enum'    => array( 'sync', 'rebuild' ),
						'default' => 'sync',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/index/batch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'batch' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/index/storage-check',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'storage_check' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'storage_check' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/index/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Only administrators may see or drive indexing.
	 */
	public function permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /index
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function status() {
		try {
			return rest_ensure_response( $this->payload() );
		} catch ( \Throwable $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * POST /index/start
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start( WP_REST_Request $request ) {
		try {
			IndexRun::start( (string) $request->get_param( 'mode' ) );

			return rest_ensure_response( $this->payload() );
		} catch ( \Throwable $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * POST /index/batch
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function batch() {
		try {
			$result  = IndexRun::batch();
			$payload = $this->payload();

			if ( ! empty( $result['locked'] ) ) {
				$payload['locked'] = true;
			}

			return rest_ensure_response( $payload );
		} catch ( \Throwable $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * POST /index/cancel
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel() {
		try {
			IndexRun::cancel();

			return rest_ensure_response( $this->payload() );
		} catch ( \Throwable $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * GET /index/storage-check returns the cached public access check (running it when
	 * nothing is cached); POST /index/storage-check runs it again.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function storage_check( WP_REST_Request $request ) {
		try {
			return rest_ensure_response( Storage::exposure_report( 'POST' === $request->get_method() ) );
		} catch ( \Throwable $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Builds the status payload shared by all endpoints.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		$stats  = array();
		$errors = array();

		foreach ( Storage::SCOPES as $scope ) {
			try {
				$stats[ $scope ] = Database::get( $scope )->stats();
			} catch ( \Throwable $e ) {
				$stats[ $scope ]  = null;
				$errors[ $scope ] = $e->getMessage();
			}
		}

		$payload = array(
			'run'      => IndexRun::state(),
			'stats'    => $stats,
			'eligible' => Indexer::count_eligible(),
		);

		if ( $errors ) {
			$payload['stats_errors'] = $errors;
		}

		return $payload;
	}

	/**
	 * Converts a throwable into a REST error.
	 *
	 * @param \Throwable $e Exception.
	 */
	private function error( \Throwable $e ): WP_Error {
		return new WP_Error( 'wp_cortex_error', $e->getMessage(), array( 'status' => 500 ) );
	}
}
