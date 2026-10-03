<?php
/**
 * Reasoning (thinking) effort levels per AI provider.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the chat reasoning setting to provider-specific request options.
 *
 * The AI Client has no generic reasoning option, so the level is sent as a provider
 * custom option. Only providers with a known request format offer levels; others can be
 * added through the wp_cortex_chat_reasoning_levels and wp_cortex_chat_reasoning_options filters.
 */
final class Reasoning {

	/**
	 * Effort levels per provider ID, lowest first.
	 */
	private const PROVIDER_LEVELS = array(
		'openai'     => array( 'none', 'low', 'medium', 'high', 'xhigh' ),
		'anthropic'  => array( 'low', 'medium', 'high', 'xhigh', 'max' ),
		'openrouter' => array( 'none', 'minimal', 'low', 'medium', 'high', 'xhigh' ),
	);

	/**
	 * Human-readable labels of all known levels.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'none'    => __( 'None', 'wp-cortex' ),
			'minimal' => __( 'Minimal', 'wp-cortex' ),
			'low'     => __( 'Low', 'wp-cortex' ),
			'medium'  => __( 'Medium', 'wp-cortex' ),
			'high'    => __( 'High', 'wp-cortex' ),
			'xhigh'   => __( 'Extra high', 'wp-cortex' ),
			'max'     => __( 'Max', 'wp-cortex' ),
		);
	}

	/**
	 * Levels a provider supports; empty when reasoning cannot be set for it.
	 *
	 * @param string $provider Provider ID.
	 * @return string[]
	 */
	public static function levels( string $provider ): array {
		$levels = self::PROVIDER_LEVELS[ $provider ] ?? array();

		/**
		 * Filters the reasoning effort levels offered for a provider.
		 *
		 * @param string[] $levels   Level slugs, lowest first.
		 * @param string   $provider Provider ID.
		 */
		$levels = apply_filters( 'wp_cortex_chat_reasoning_levels', $levels, $provider );

		return array_values( array_filter( array_map( 'sanitize_key', (array) $levels ) ) );
	}

	/**
	 * Levels of every registered provider, keyed by level slug, listing the providers that support it.
	 *
	 * @param string[] $providers Provider IDs.
	 * @return array<string, string[]>
	 */
	public static function level_providers( array $providers ): array {
		$map = array();

		foreach ( $providers as $provider ) {
			foreach ( self::levels( $provider ) as $level ) {
				$map[ $level ][] = $provider;
			}
		}

		$order = array_keys( self::labels() );
		uksort(
			$map,
			static function ( string $a, string $b ) use ( $order ): int {
				$pos_a = array_search( $a, $order, true );
				$pos_b = array_search( $b, $order, true );

				return ( false === $pos_a ? PHP_INT_MAX : $pos_a ) <=> ( false === $pos_b ? PHP_INT_MAX : $pos_b );
			}
		);

		return $map;
	}

	/**
	 * Custom request options that apply a level; empty for the provider default or an unsupported level.
	 *
	 * @param string $provider Provider ID.
	 * @param string $level    Level slug.
	 * @return array<string, mixed>
	 */
	public static function custom_options( string $provider, string $level ): array {
		if ( '' === $level || ! in_array( $level, self::levels( $provider ), true ) ) {
			return array();
		}

		switch ( $provider ) {
			case 'openai':
			case 'openrouter':
				$options = array( 'reasoning' => array( 'effort' => $level ) );
				break;
			case 'anthropic':
				// Effort only; the thinking mode itself is left to the model's default.
				$options = array( 'output_config' => array( 'effort' => $level ) );
				break;
			default:
				$options = array();
		}

		/**
		 * Filters the custom request options that apply a reasoning level.
		 *
		 * @param array<string, mixed> $options  Custom options merged into the provider request.
		 * @param string               $provider Provider ID.
		 * @param string               $level    Level slug.
		 */
		$options = apply_filters( 'wp_cortex_chat_reasoning_options', $options, $provider, $level );

		return is_array( $options ) ? $options : array();
	}
}
