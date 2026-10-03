<?php
/**
 * Lists the models of a registered AI provider, cached in a transient.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Contracts\CachesDataInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Model listing for the chat settings, generic over any registered provider.
 */
final class ModelCatalog {

	public const TRANSIENT_PREFIX = 'wp_cortex_models_';

	private const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Models of a provider that support text generation.
	 *
	 * @param string $provider Provider ID.
	 * @param bool   $refresh  Bypass and rewrite the cache.
	 * @return array<int, array{id: string, name: string, tools: bool}>|WP_Error
	 */
	public function models( string $provider, bool $refresh = false ) {
		$provider = sanitize_key( $provider );

		if ( '' === $provider ) {
			return new WP_Error( 'wp_cortex_no_provider', __( 'No provider selected.', 'wp-cortex' ), array( 'status' => 400 ) );
		}

		if ( ! $refresh ) {
			$cached = $this->cached( $provider );

			if ( null !== $cached ) {
				return $cached;
			}
		}

		$models = $this->fetch( $provider, $refresh );

		if ( is_wp_error( $models ) ) {
			return $models;
		}

		set_transient( self::TRANSIENT_PREFIX . $provider, $models, self::TTL );

		return $models;
	}

	/**
	 * Cached models only, never performs an HTTP request.
	 *
	 * @param string $provider Provider ID.
	 * @return array<int, array{id: string, name: string, tools: bool}>|null Null when not cached.
	 */
	public function cached( string $provider ): ?array {
		$cached = get_transient( self::TRANSIENT_PREFIX . sanitize_key( $provider ) );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Asks the provider for its models.
	 *
	 * @param string $provider Provider ID.
	 * @param bool   $refresh  Invalidate the AI Client's own model cache first.
	 * @return array<int, array{id: string, name: string, tools: bool}>|WP_Error
	 */
	private function fetch( string $provider, bool $refresh ) {
		if ( ! class_exists( AiClient::class ) ) {
			return new WP_Error( 'wp_cortex_no_ai_client', __( 'The WordPress AI Client is not available. WordPress 7.0 or later is required.', 'wp-cortex' ), array( 'status' => 500 ) );
		}

		try {
			$registry = AiClient::defaultRegistry();

			if ( ! $registry->hasProvider( $provider ) ) {
				return new WP_Error( 'wp_cortex_unknown_provider', __( 'Unknown AI provider.', 'wp-cortex' ), array( 'status' => 400 ) );
			}

			if ( ! $registry->isProviderConfigured( $provider ) ) {
				return new WP_Error( 'wp_cortex_provider_not_configured', __( 'This provider has no API key. Add one under Settings > Connectors.', 'wp-cortex' ), array( 'status' => 400 ) );
			}

			$class     = $registry->getProviderClassName( $provider );
			$directory = $class::modelMetadataDirectory();

			if ( $refresh && $directory instanceof CachesDataInterface ) {
				$directory->invalidateCaches();
			}

			$models = array();

			foreach ( $directory->listModelMetadata() as $metadata ) {
				$row = $this->normalize( $metadata );

				if ( null !== $row ) {
					$models[] = $row;
				}
			}
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'wp_cortex_models_failed',
				/* translators: %s: error message. */
				sprintf( __( 'Could not load the model list: %s', 'wp-cortex' ), $e->getMessage() ),
				array( 'status' => 502 )
			);
		}

		// Some providers never declare function calling in their metadata; then it cannot be told per model.
		if ( $models && ! array_filter( $models, static fn( array $m ): bool => $m['tools'] ) ) {
			foreach ( $models as $index => $model ) {
				$models[ $index ]['tools'] = true;
			}
		}

		usort(
			$models,
			static function ( array $a, array $b ): int {
				if ( $a['tools'] !== $b['tools'] ) {
					return $a['tools'] ? -1 : 1;
				}

				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $models;
	}

	/**
	 * Converts model metadata to a list row; null for models without text generation.
	 *
	 * @param ModelMetadata $metadata Model metadata.
	 * @return array{id: string, name: string, tools: bool}|null
	 */
	private function normalize( ModelMetadata $metadata ): ?array {
		$text = false;

		foreach ( $metadata->getSupportedCapabilities() as $capability ) {
			if ( $capability->isTextGeneration() ) {
				$text = true;
				break;
			}
		}

		if ( ! $text ) {
			return null;
		}

		$options = $metadata->getSupportedOptions();
		$tools   = ! $options;

		foreach ( $options as $option ) {
			if ( $option->getName()->isFunctionDeclarations() ) {
				$tools = true;
				break;
			}
		}

		$id = $metadata->getId();

		return array(
			'id'    => $id,
			'name'  => '' !== $metadata->getName() ? $metadata->getName() : $id,
			'tools' => $tools,
		);
	}
}
