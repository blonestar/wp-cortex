<?php
/**
 * Shared AI Client prompt setup for the chat agents.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WPCortex\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Builds prompts with the configured provider, model and reasoning level, and reads model replies.
 */
final class PromptFactory {

	private const REQUEST_TIMEOUT = 90.0;

	/**
	 * Prompt builder for one model call.
	 *
	 * @param Message[]             $history   Conversation messages.
	 * @param string                $system    System instruction.
	 * @param FunctionDeclaration[] $functions Function declarations.
	 * @param bool                  $is_public Whether to use the visitor chat's provider and model.
	 * @return \WP_AI_Client_Prompt_Builder|WP_Error
	 */
	public static function builder( array $history, string $system, array $functions, bool $is_public = false ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'wp_cortex_no_ai_client', __( 'The WordPress AI Client is not available. WordPress 7.0 or later is required.', 'wp-cortex' ) );
		}

		// One call: using_abilities() and using_function_declarations() each replace the list.
		$builder = wp_ai_client_prompt( $history )
			->using_system_instruction( $system )
			->using_function_declarations( ...$functions )
			->using_request_options( RequestOptions::fromArray( array( 'timeout' => self::REQUEST_TIMEOUT ) ) );

		$config   = Settings::chat_model_config( $is_public );
		$provider = $config['provider'];
		$model    = $config['model'];

		if ( '' !== $provider ) {
			$builder = $builder->using_provider( $provider );

			if ( '' !== $model ) {
				// An explicit model instance skips the SDK's metadata-based model matching.
				// Some providers (e.g. OpenRouter) do not declare function calling in their
				// model metadata although the API supports it, so the API decides instead.
				try {
					$builder = $builder->using_model( AiClient::defaultRegistry()->getProviderModel( $provider, $model ) );
				} catch ( \Throwable $e ) {
					/* translators: 1: model ID, 2: error message */
					return new WP_Error( 'wp_cortex_model_unavailable', sprintf( __( 'The chat model "%1$s" is not available: %2$s', 'wp-cortex' ), $model, $e->getMessage() ) );
				}

				// Reasoning effort is model specific, so it only applies to an explicit model.
				$reasoning = Reasoning::custom_options( $provider, $config['reasoning'] );

				if ( $reasoning ) {
					$builder = $builder->using_model_config( ModelConfig::fromArray( array( ModelConfig::KEY_CUSTOM_OPTIONS => $reasoning ) ) );
				}
			}
		}

		if ( '' !== $provider && '' !== $model ? ! self::is_provider_configured( $provider ) : ! $builder->is_supported_for_text_generation() ) {
			return new WP_Error( 'wp_cortex_no_provider', self::unsupported_message( $provider ) );
		}

		return $builder;
	}

	/**
	 * Function calls and visible text of a model reply.
	 *
	 * @param Message $message Model message.
	 * @return array{calls: FunctionCall[], text: string}
	 */
	public static function parse( Message $message ): array {
		$calls = array();
		$text  = '';

		foreach ( $message->getParts() as $part ) {
			if ( $part->getType()->isFunctionCall() ) {
				$call = $part->getFunctionCall();

				if ( $call ) {
					$calls[] = $call;
				}
			} elseif ( $part->getType()->isText() ) {
				$channel = $part->getChannel();

				if ( ( null === $channel || $channel->isContent() ) && null !== $part->getText() ) {
					$text .= $part->getText();
				}
			}
		}

		return array(
			'calls' => $calls,
			'text'  => trim( $text ),
		);
	}

	/**
	 * Allows a long tool-calling loop to finish.
	 */
	public static function extend_time_limit(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@set_time_limit( 300 );
		}
	}

	/**
	 * Whether a registered provider has credentials.
	 *
	 * @param string $provider Provider ID.
	 */
	private static function is_provider_configured( string $provider ): bool {
		$providers = ChatAgent::providers();

		return ! empty( $providers[ $provider ]['configured'] );
	}

	/**
	 * Message explaining why generation is not possible.
	 *
	 * @param string $provider Selected provider ID, may be empty.
	 */
	private static function unsupported_message( string $provider ): string {
		$providers  = ChatAgent::providers();
		$configured = array_filter( $providers, static fn( array $p ) => $p['configured'] );

		if ( ! $configured ) {
			return __( 'No AI provider is configured. Add an API key under Settings > Connectors.', 'wp-cortex' );
		}

		if ( '' !== $provider && isset( $providers[ $provider ] ) && ! $providers[ $provider ]['configured'] ) {
			/* translators: %s: provider name. */
			return sprintf( __( 'The selected AI provider (%s) has no API key. Add one under Settings > Connectors or choose another provider in Cortex > Settings.', 'wp-cortex' ), $providers[ $provider ]['name'] );
		}

		return __( 'The selected AI provider or model does not support text generation with tool calls. Check the Chat settings under Cortex > Settings.', 'wp-cortex' );
	}
}
