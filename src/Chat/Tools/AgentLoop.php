<?php
/**
 * Model/tool loop shared by the chat agents.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WPCortex\Chat\PromptFactory;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Calls the model until it answers without function calls, running the calls through
 * the registry in between. The last allowed model call is told to answer without
 * functions, so a long task ends with a report of what was done instead of an error.
 */
final class AgentLoop {

	/**
	 * System instruction added to the last allowed model call.
	 */
	private const FINAL_STEP_INSTRUCTION = 'You have used every step available for this message. Do not call any more functions: they will not run. Answer now, in the language of the user: say briefly what you did, what is still left to do, and what you could not do with the functions you have. The user can ask you to continue in the next message.';

	/**
	 * Runs the loop.
	 *
	 * @param Message[]     $messages       Messages ending with the new user message.
	 * @param string        $system         System instruction.
	 * @param ToolRegistry  $tools          Tools of the turn.
	 * @param string        $chat           Chat whose model is used: 'admin' or 'public'.
	 * @param int           $max_iterations Maximum number of model calls, the last of which only answers.
	 * @param callable|null $on_response    Optional; gets the function name and the response of
	 *                                      an available tool and returns the response sent to the model.
	 * @return array{messages: Message[], text: string}|WP_Error All messages including the final model message, and its text.
	 */
	public static function run( array $messages, string $system, ToolRegistry $tools, string $chat, int $max_iterations, ?callable $on_response = null ) {
		PromptFactory::extend_time_limit();

		$functions = $tools->declarations();

		for ( $i = 0; $i < $max_iterations; $i++ ) {
			$is_last = $max_iterations - 1 === $i;
			// The functions stay declared: some providers reject a history with function
			// calls when no functions are declared.
			$builder = PromptFactory::builder( $messages, $is_last ? $system . "\n\n" . self::FINAL_STEP_INSTRUCTION : $system, $functions, $chat );

			if ( is_wp_error( $builder ) ) {
				return $builder;
			}

			$result = $builder->generate_text_result();

			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'wp_cortex_ai_error', $result->get_error_message() );
			}

			$model_message = $result->toMessage();
			$reply         = PromptFactory::parse( $model_message );

			if ( $is_last && $reply['calls'] ) {
				// The calls are not run, so they are kept out of the history as well.
				$text       = '' !== $reply['text'] ? $reply['text'] : __( 'I ran out of steps before I could finish this request. Ask me to continue, or split the request into smaller parts.', 'wp-cortex' );
				$messages[] = new ModelMessage( array( new MessagePart( $text ) ) );

				return array(
					'messages' => $messages,
					'text'     => $text,
				);
			}

			$messages[] = $model_message;

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

		// Only reached when no model call is allowed at all.
		return new WP_Error( 'wp_cortex_too_many_steps', __( 'The assistant needed too many steps to answer. Please try a more specific question.', 'wp-cortex' ) );
	}
}
