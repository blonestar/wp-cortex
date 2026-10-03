<?php
/**
 * Admin chat agent: LLM tool-calling loop on top of the WordPress AI Client.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WPCortex\Settings;
use WP_AI_Client_Ability_Function_Resolver;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one chat turn: loads history, loops model calls and tool calls, persists the result.
 */
final class ChatAgent {

	private const MAX_ITERATIONS     = 8;
	private const HISTORY_LIMIT      = 30;
	private const MAX_PAYLOAD_CHARS  = 12000;
	private const MAX_RESULT_ITEMS   = 12;
	private const REQUEST_TIMEOUT    = 90.0;
	private const OPEN_POST_FUNCTION = 'open_post';
	private const ABILITIES          = array( 'wp-cortex/search-content', 'wp-cortex/find-duplicates', 'wp-cortex/get-document', 'wp-cortex/list-fields' );

	/**
	 * Conversation store.
	 *
	 * @var ConversationStore
	 */
	private ConversationStore $store;

	/**
	 * Constructor.
	 *
	 * @param ConversationStore|null $store Conversation store.
	 */
	public function __construct( ?ConversationStore $store = null ) {
		$this->store = $store ?? new ConversationStore();
	}

	/**
	 * Registered AI providers and whether each is configured.
	 *
	 * @return array<string, array{name: string, configured: bool}>
	 */
	public static function providers(): array {
		$providers = array();

		if ( ! class_exists( AiClient::class ) ) {
			return $providers;
		}

		try {
			$registry = AiClient::defaultRegistry();

			foreach ( $registry->getRegisteredProviderIds() as $id ) {
				$name = (string) $id;

				try {
					$class = $registry->getProviderClassName( $id );
					$name  = $class::metadata()->getName();
				} catch ( \Throwable $e ) {
					unset( $e );
				}

				$providers[ (string) $id ] = array(
					'name'       => $name,
					'configured' => $registry->isProviderConfigured( $id ),
				);
			}
		} catch ( \Throwable $e ) {
			return array();
		}

		return $providers;
	}

	/**
	 * Whether a registered provider has credentials.
	 *
	 * @param string $provider Provider ID.
	 */
	private function is_provider_configured( string $provider ): bool {
		$providers = self::providers();

		return ! empty( $providers[ $provider ]['configured'] );
	}

	/**
	 * Handles one user message.
	 *
	 * @param int    $conversation_id Conversation ID, 0 to start a new one.
	 * @param string $message         User message.
	 * @param array  $context         Screen context: screen, post_id.
	 * @param int    $user_id         Current user.
	 * @return array{conversation_id: int, title: string, items: array, actions: array}|WP_Error
	 */
	public function respond( int $conversation_id, string $message, array $context, int $user_id ) {
		$message = trim( $message );

		if ( '' === $message ) {
			return new WP_Error( 'wp_cortex_empty_message', __( 'The message is empty.', 'wp-cortex' ), array( 'status' => 400 ) );
		}

		if ( $conversation_id > 0 ) {
			$conversation = $this->store->get( $conversation_id, $user_id );

			if ( null === $conversation ) {
				return new WP_Error( 'wp_cortex_not_found', __( 'Conversation not found.', 'wp-cortex' ), array( 'status' => 404 ) );
			}
		} else {
			$conversation_id = $this->store->create( $user_id );

			if ( $conversation_id < 1 ) {
				return new WP_Error( 'wp_cortex_store_failed', __( 'Could not create the conversation.', 'wp-cortex' ), array( 'status' => 500 ) );
			}

			$conversation = $this->store->get( $conversation_id, $user_id );
		}

		$title      = '' !== $conversation['title'] ? $conversation['title'] : $this->make_title( $message );
		$transcript = $conversation['transcript'];
		$history    = $this->decode_messages( $conversation['messages'] );
		$items      = array(
			array(
				'role' => 'user',
				'text' => $message,
			),
		);
		$actions    = array();

		$history[] = new UserMessage( array( new MessagePart( $message ) ) );

		$outcome = $this->run_loop( $history, $context, $items, $actions );

		if ( is_wp_error( $outcome ) ) {
			$items[]  = array(
				'role' => 'error',
				'text' => $outcome->get_error_message(),
			);
			$actions  = array();
			// The failed turn is not added to the model history.
			$history = null;
		} else {
			$history = $outcome;
		}

		$transcript = array_merge( $transcript, $items );
		$stored     = null === $history ? $conversation['messages'] : array_map( static fn( Message $m ) => $m->toArray(), $this->trim_history( $history ) );

		$this->store->save( $conversation_id, $user_id, $title, $stored, $transcript );

		return array(
			'conversation_id' => $conversation_id,
			'title'           => $title,
			'items'           => $items,
			'actions'         => $actions,
		);
	}

	/**
	 * Runs the model/tool loop.
	 *
	 * @param Message[] $history Messages ending with the new user message.
	 * @param array     $context Screen context.
	 * @param array     $items   Transcript items (by reference).
	 * @param array     $actions UI actions (by reference).
	 * @return Message[]|WP_Error Full history including the final model message.
	 */
	private function run_loop( array $history, array $context, array &$items, array &$actions ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'wp_cortex_no_ai_client', __( 'The WordPress AI Client is not available. WordPress 7.0 or later is required.', 'wp-cortex' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@set_time_limit( 300 );
		}

		$resolver  = new WP_AI_Client_Ability_Function_Resolver( ...self::ABILITIES );
		$system    = $this->system_instruction( $context );
		$functions = $this->function_declarations();
		$known_ids = $this->known_post_ids( $history, $context );
		$seen      = array();

		for ( $i = 0; $i < self::MAX_ITERATIONS; $i++ ) {
			// One call: using_abilities() and using_function_declarations() each replace the list.
			$builder = wp_ai_client_prompt( $history )
				->using_system_instruction( $system )
				->using_function_declarations( ...$functions )
				->using_request_options( RequestOptions::fromArray( array( 'timeout' => self::REQUEST_TIMEOUT ) ) );

			$provider = (string) Settings::get( 'chat_provider' );
			$model    = (string) Settings::get( 'chat_model' );

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
					$reasoning = Reasoning::custom_options( $provider, (string) Settings::get( 'chat_reasoning' ) );

					if ( $reasoning ) {
						$builder = $builder->using_model_config( ModelConfig::fromArray( array( ModelConfig::KEY_CUSTOM_OPTIONS => $reasoning ) ) );
					}
				}
			}

			if ( '' !== $provider && '' !== $model ? ! $this->is_provider_configured( $provider ) : ! $builder->is_supported_for_text_generation() ) {
				return new WP_Error( 'wp_cortex_no_provider', $this->unsupported_message( $provider ) );
			}

			$result = $builder->generate_text_result();

			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'wp_cortex_ai_error', $result->get_error_message() );
			}

			$model_message = $result->toMessage();
			$history[]     = $model_message;
			$calls         = array();
			$text_chunks   = array();

			foreach ( $model_message->getParts() as $part ) {
				if ( $part->getType()->isFunctionCall() ) {
					$call = $part->getFunctionCall();

					if ( $call ) {
						$calls[] = $call;
					}
				} elseif ( $part->getType()->isText() ) {
					$channel = $part->getChannel();

					if ( ( null === $channel || $channel->isContent() ) && null !== $part->getText() ) {
						$text_chunks[] = $part->getText();
					}
				}
			}

			if ( ! $calls ) {
				$text = trim( implode( '', $text_chunks ) );

				if ( '' === $text ) {
					$text = __( 'I could not produce an answer. Please try rephrasing your question.', 'wp-cortex' );
				}

				$items[] = array(
					'role' => 'assistant',
					'text' => $text,
				);

				$cards = $this->cited_cards( $text, $known_ids, $seen );

				if ( $cards ) {
					$items[] = array(
						'role'    => 'results',
						'results' => $cards,
					);
				}

				return $history;
			}

			foreach ( $calls as $call ) {
				if ( self::OPEN_POST_FUNCTION === $call->getName() ) {
					$response = $this->handle_open_post( $call, $known_ids, $actions );
				} elseif ( $resolver->is_ability_call( $call ) ) {
					$response  = $resolver->execute_ability( $call );
					$known_ids = array_merge( $known_ids, $this->response_post_ids( $response ) );
					$this->collect_seen( $response, $seen );
					$response = $this->limit_payload( $response );
				} else {
					$response = new FunctionResponse(
						$call->getId(),
						$call->getName(),
						array( 'error' => __( 'Unknown function.', 'wp-cortex' ) )
					);
				}

				// One message per response: OpenAI-compatible providers map a message to a
				// "tool" role message only when the function response is its only part.
				$history[] = new UserMessage( array( new MessagePart( $response ) ) );
			}
		}

		return new WP_Error( 'wp_cortex_too_many_steps', __( 'The assistant needed too many steps to answer. Please try a more specific question.', 'wp-cortex' ) );
	}

	/**
	 * Message explaining why generation is not possible.
	 *
	 * @param string $provider Selected provider ID, may be empty.
	 */
	private function unsupported_message( string $provider ): string {
		$providers  = self::providers();
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

	/**
	 * Function declarations for the model: the abilities plus open_post.
	 *
	 * Mirrors WP_AI_Client_Prompt_Builder::using_abilities(), which cannot be
	 * combined with custom declarations because both replace the list.
	 *
	 * @return FunctionDeclaration[]
	 */
	private function function_declarations(): array {
		$declarations = array();

		foreach ( self::ABILITIES as $name ) {
			$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;

			if ( ! $ability ) {
				continue;
			}

			$schema = wp_prepare_json_schema_for_client( $ability->get_input_schema() );

			$declarations[] = new FunctionDeclaration(
				WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $name ),
				$ability->get_description(),
				! empty( $schema ) ? $schema : null
			);
		}

		$declarations[] = $this->open_post_declaration();

		return $declarations;
	}

	/**
	 * Declaration of the open_post function.
	 */
	private function open_post_declaration(): FunctionDeclaration {
		return new FunctionDeclaration(
			self::OPEN_POST_FUNCTION,
			'Opens a post in the admin: navigates the user to the post editor (target "edit", default) or to its public page (target "view"). Call it only when the user explicitly asks to open, go to, edit or show a specific post.',
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'ID of the post to open, taken from search results.',
					),
					'target'  => array(
						'type'    => 'string',
						'enum'    => array( 'edit', 'view' ),
						'default' => 'edit',
					),
				),
				'required'   => array( 'post_id' ),
			)
		);
	}

	/**
	 * Post IDs the model has legitimately seen: tool results in the history and the post being edited.
	 *
	 * @param Message[] $history Messages.
	 * @param array     $context Screen context.
	 * @return int[]
	 */
	private function known_post_ids( array $history, array $context ): array {
		$ids = array();

		if ( ! empty( $context['post_id'] ) ) {
			$ids[] = (int) $context['post_id'];
		}

		foreach ( $history as $message ) {
			foreach ( $message->getParts() as $part ) {
				$response = $part->getFunctionResponse();

				if ( $response ) {
					$ids = array_merge( $ids, $this->response_post_ids( $response ) );
				}
			}
		}

		return $ids;
	}

	/**
	 * Post IDs contained in an ability response (search results or a single document).
	 *
	 * @param FunctionResponse $response Response.
	 * @return int[]
	 */
	private function response_post_ids( FunctionResponse $response ): array {
		$payload = $response->getResponse();

		if ( ! is_array( $payload ) ) {
			return array();
		}

		if ( isset( $payload['partial_json'] ) && is_string( $payload['partial_json'] ) ) {
			preg_match_all( '/"id":(\d+)/', $payload['partial_json'], $matches );

			return array_map( 'intval', $matches[1] );
		}

		$ids = array();

		if ( isset( $payload['results'] ) && is_array( $payload['results'] ) ) {
			foreach ( $payload['results'] as $row ) {
				if ( isset( $row['id'] ) ) {
					$ids[] = (int) $row['id'];
				}
			}
		}

		if ( isset( $payload['groups'] ) && is_array( $payload['groups'] ) ) {
			foreach ( $payload['groups'] as $group ) {
				foreach ( (array) ( $group['posts'] ?? array() ) as $row ) {
					if ( isset( $row['id'] ) ) {
						$ids[] = (int) $row['id'];
					}
				}
			}
		}

		if ( isset( $payload['id'] ) && is_numeric( $payload['id'] ) ) {
			$ids[] = (int) $payload['id'];
		}

		return $ids;
	}

	/**
	 * Handles the open_post function. URLs are always built server-side, and only
	 * posts returned by tools (or the post being edited) can be opened.
	 *
	 * @param FunctionCall $call      Function call.
	 * @param int[]        $known_ids Post IDs seen in tool results.
	 * @param array        $actions   UI actions (by reference).
	 */
	private function handle_open_post( FunctionCall $call, array $known_ids, array &$actions ): FunctionResponse {
		$args    = (array) $call->getArgs();
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$target  = 'view' === ( $args['target'] ?? 'edit' ) ? 'view' : 'edit';
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		$url     = '';

		if ( ! in_array( $post_id, $known_ids, true ) ) {
			$error = __( 'Unknown post ID. Only open posts returned by search-content or get-document; search first.', 'wp-cortex' );
		} elseif ( ! $post ) {
			$error = __( 'Post not found.', 'wp-cortex' );
		} elseif ( 'view' === $target ) {
			$error = '';

			if ( ! is_post_publicly_viewable( $post ) && ! current_user_can( 'edit_post', $post_id ) ) {
				$error = __( 'This post cannot be viewed.', 'wp-cortex' );
			} else {
				$url = (string) get_permalink( $post );
			}
		} elseif ( ! current_user_can( 'edit_post', $post_id ) ) {
			$error = __( 'You are not allowed to edit this post.', 'wp-cortex' );
		} else {
			$error = '';
			$url   = (string) get_edit_post_link( $post_id, 'raw' );
		}

		if ( '' !== $error || '' === $url ) {
			return new FunctionResponse(
				$call->getId(),
				$call->getName(),
				array(
					'ok'    => false,
					'error' => '' !== $error ? $error : __( 'No URL available for this post.', 'wp-cortex' ),
				)
			);
		}

		$actions[] = array(
			'type'    => 'navigate',
			'url'     => $url,
			'post_id' => $post_id,
			'title'   => get_the_title( $post ),
		);

		return new FunctionResponse( $call->getId(), $call->getName(), array( 'ok' => true ) );
	}

	/**
	 * Remembers post rows returned by a tool (search results, duplicate groups or a
	 * single document) so cards can reuse their data and snippets.
	 *
	 * @param FunctionResponse $response Response.
	 * @param array            $seen     Rows keyed by post ID (by reference).
	 */
	private function collect_seen( FunctionResponse $response, array &$seen ): void {
		$payload = $response->getResponse();

		if ( ! is_array( $payload ) ) {
			return;
		}

		$rows = isset( $payload['results'] ) && is_array( $payload['results'] ) ? $payload['results'] : array();

		foreach ( (array) ( $payload['groups'] ?? array() ) as $group ) {
			$rows = array_merge( $rows, (array) ( $group['posts'] ?? array() ) );
		}

		if ( isset( $payload['id'], $payload['title'] ) ) {
			$rows[] = $payload;
		}

		foreach ( $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );

			// Rows without URLs (duplicate groups) are left to the WordPress fallback in cited_cards().
			if ( $id < 1 || ! isset( $row['url'] ) ) {
				continue;
			}

			$card = array(
				'id'        => $id,
				'title'     => (string) ( $row['title'] ?? '' ),
				'post_type' => (string) ( $row['post_type'] ?? '' ),
				'status'    => (string) ( $row['status'] ?? '' ),
				'author'    => (string) ( $row['author'] ?? '' ),
				'url'       => (string) ( $row['url'] ?? '' ),
				'edit_url'  => (string) ( $row['edit_url'] ?? '' ),
				'snippet'   => (string) ( $row['snippet'] ?? '' ),
			);

			// Keep an earlier snippet when a later row (e.g. get-document) has none.
			if ( '' === $card['snippet'] && isset( $seen[ $id ] ) ) {
				$card['snippet'] = $seen[ $id ]['snippet'];
			}

			$seen[ $id ] = $card;
		}
	}

	/**
	 * Cards for the posts the answer cites as #ID, in order of appearance.
	 *
	 * Only IDs returned by tools (or the post being edited) are accepted; posts not
	 * seen in this turn are built from WordPress.
	 *
	 * @param string $text      Answer text.
	 * @param int[]  $known_ids Post IDs seen in tool results.
	 * @param array  $seen      Rows from this turn keyed by post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function cited_cards( string $text, array $known_ids, array $seen ): array {
		preg_match_all( '/(?<![\w&])#(\d+)\b/', $text, $matches );

		$cards = array();

		foreach ( array_unique( array_map( 'intval', $matches[1] ) ) as $id ) {
			if ( count( $cards ) >= self::MAX_RESULT_ITEMS ) {
				break;
			}

			if ( ! in_array( $id, $known_ids, true ) ) {
				continue;
			}

			if ( isset( $seen[ $id ] ) ) {
				$cards[] = $seen[ $id ];
				continue;
			}

			$post = get_post( $id );

			if ( ! $post || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}

			$cards[] = array(
				'id'        => $id,
				'title'     => get_the_title( $post ),
				'post_type' => $post->post_type,
				'status'    => $post->post_status,
				'author'    => $post->post_author ? (string) get_the_author_meta( 'display_name', (int) $post->post_author ) : '',
				'url'       => (string) get_permalink( $post ),
				'edit_url'  => (string) get_edit_post_link( $id, 'raw' ),
				'snippet'   => '',
			);
		}

		return $cards;
	}

	/**
	 * Truncates oversized tool payloads.
	 *
	 * @param FunctionResponse $response Response.
	 */
	private function limit_payload( FunctionResponse $response ): FunctionResponse {
		$json = (string) wp_json_encode( $response->getResponse(), JSON_PARTIAL_OUTPUT_ON_ERROR );

		if ( strlen( $json ) <= self::MAX_PAYLOAD_CHARS ) {
			return $response;
		}

		return new FunctionResponse(
			$response->getId(),
			$response->getName(),
			array(
				'truncated'    => true,
				'partial_json' => mb_strcut( $json, 0, self::MAX_PAYLOAD_CHARS, 'UTF-8' ),
			)
		);
	}

	/**
	 * Builds the system instruction.
	 *
	 * @param array $context Screen context.
	 */
	private function system_instruction( array $context ): string {
		$lines = array(
			sprintf(
				'You are Cortex, an assistant inside the WordPress admin of the site "%1$s" (%2$s). Today is %3$s.',
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				home_url(),
				wp_date( 'Y-m-d' )
			),
			'Answer in the language the user writes in.',
			'Use your tools to find site content. Never invent posts or post IDs; only mention content returned by tools.',
			'When you are unsure about field names for filters, call the list-fields tool first.',
			'To find posts by an author, use the author filter of search-content, not a text query. For duplicate titles, meta descriptions or content, use find-duplicates.',
			'Call open_post ONLY when the user explicitly asks to open, go to, edit or show a specific post (including references such as "this one" or "open the first" to earlier results). Otherwise just list the results.',
			'Keep answers short. Mention each relevant post by its title followed by its ID written as #123. Every post cited as #ID is shown to the user as a card below your answer, so cite only posts that answer the question, and do not repeat snippets or URLs.',
		);

		$post_id = (int) ( $context['post_id'] ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( $post && current_user_can( 'edit_post', $post_id ) ) {
			$lines[] = sprintf(
				'The user is currently editing post #%1$d «%2$s» (%3$s, %4$s). This is background context only: use it only when the user refers to this post (for example "this post"), never to narrow other questions.',
				$post_id,
				get_the_title( $post ),
				$post->post_type,
				$post->post_status
			);
		}

		$instruction = implode( "\n", $lines );

		/**
		 * Filters the chat system instruction.
		 *
		 * @param string $instruction System instruction.
		 * @param array  $context     Screen context (screen, post_id).
		 */
		$filtered = apply_filters( 'wp_cortex_chat_system_instruction', $instruction, $context );

		return is_string( $filtered ) ? $filtered : $instruction;
	}

	/**
	 * Title from the first user message.
	 *
	 * @param string $message User message.
	 */
	private function make_title( string $message ): string {
		$title = trim( (string) preg_replace( '/\s+/u', ' ', $message ) );

		return mb_strlen( $title ) > 60 ? rtrim( mb_substr( $title, 0, 59 ) ) . '…' : $title;
	}

	/**
	 * Decodes stored message arrays; unreadable entries drop the whole history to stay consistent.
	 *
	 * @param array $stored Stored Message::toArray() items.
	 * @return Message[]
	 */
	private function decode_messages( array $stored ): array {
		$messages = array();

		try {
			foreach ( $stored as $row ) {
				$messages[] = Message::fromArray( $row );
			}
		} catch ( \Throwable $e ) {
			return array();
		}

		return $this->trim_history( $messages );
	}

	/**
	 * Keeps roughly the last messages, starting at a user message with text so
	 * function call / response pairs stay intact.
	 *
	 * @param Message[] $messages Messages.
	 * @return Message[]
	 */
	private function trim_history( array $messages ): array {
		$count = count( $messages );

		if ( $count <= self::HISTORY_LIMIT ) {
			return $this->from_first_user_text( $messages );
		}

		return $this->from_first_user_text( array_slice( $messages, $count - self::HISTORY_LIMIT ) );
	}

	/**
	 * Drops leading messages until the first user message that has text.
	 *
	 * @param Message[] $messages Messages.
	 * @return Message[]
	 */
	private function from_first_user_text( array $messages ): array {
		foreach ( $messages as $index => $message ) {
			if ( $message->getRole()->isUser() ) {
				foreach ( $message->getParts() as $part ) {
					if ( $part->getType()->isText() ) {
						return array_values( array_slice( $messages, $index ) );
					}
				}
			}
		}

		return array();
	}
}
