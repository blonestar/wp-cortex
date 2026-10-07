<?php
/**
 * Model/tool loop shared by the chat agents.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WPCortex\Chat\PromptFactory;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Calls the model until it answers without function calls, running the calls through
 * the registry in between.
 */
final class AgentLoop {

	/**
	 * Runs the loop.
	 *
	 * @param Message[]     $messages       Messages ending with the new user message.
	 * @param string        $system         System instruction.
	 * @param ToolRegistry  $tools          Tools of the turn.
	 * @param string        $chat           Chat whose model is used: 'admin' or 'public'.
	 * @param int           $max_iterations Maximum number of model calls.
	 * @param callable|null $on_response    Optional; gets the function name and the response of
	 *                                      an available tool and returns the response sent to the model.
	 * @return array{messages: Message[], text: string}|WP_Error All messages including the final model message, and its text.
	 */
	public static function run( array $messages, string $system, ToolRegistry $tools, string $chat, int $max_iterations, ?callable $on_response = null ) {
		PromptFactory::extend_time_limit();

		$functions = $tools->declarations();

		for ( $i = 0; $i < $max_iterations; $i++ ) {
			$builder = PromptFactory::builder( $messages, $system, $functions, $chat );

			if ( is_wp_error( $builder ) ) {
				return $builder;
			}

			$result = $builder->generate_text_result();

			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'wp_cortex_ai_error', $result->get_error_message() );
			}

			$model_message = $result->toMessage();
			$messages[]    = $model_message;
			$reply         = PromptFactory::parse( $model_message );

			if ( ! $reply['calls'] ) {
				return array(
					'messages' => $messages,
					'text'     => $reply['text'],
				);
			}

			foreach ( $reply['calls'] as $call ) {
				$name    = (string) $call->getName();
				$payload = $tools->execute( $call );

				if ( null === $payload ) {
					$payload = array( 'error' => 'Unknown function.' );
				} elseif ( $on_response ) {
					$payload = $on_response( $name, $payload );
				}

				// One message per response: OpenAI-compatible providers map a message to a
				// "tool" role message only when the function response is its only part.
				$messages[] = new UserMessage( array( new MessagePart( new FunctionResponse( $call->getId(), $name, $payload ) ) ) );
			}
		}

		return new WP_Error( 'wp_cortex_too_many_steps', __( 'The assistant needed too many steps to answer. Please try a more specific question.', 'wp-cortex' ) );
	}
}
