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
use WPCortex\Chat\Tools\Admin\AbilityTool;
use WPCortex\Chat\Tools\Admin\AdminContext;
use WPCortex\Chat\Tools\Admin\OpenAdminPage;
use WPCortex\Chat\Tools\Admin\OpenPost;
use WPCortex\Chat\Tools\Admin\ProposeSkill;
use WPCortex\Chat\Tools\Admin\SelectTab;
use WPCortex\Chat\Tools\Admin\UseSkill;
use WPCortex\Chat\Tools\AgentLoop;
use WPCortex\Chat\Tools\Tool;
use WPCortex\Chat\Tools\ToolContext;
use WPCortex\Chat\Tools\ToolRegistry;
use WPCortex\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one chat turn: loads history, loops model calls and tool calls, persists the result.
 *
 * The tools live in Chat\Tools\Admin; the theme (wp-cortex/tools/admin/) and the
 * wp_cortex_admin_chat_tools filter can add, change or remove them (ToolRegistry).
 */
final class ChatAgent {

	private const MAX_ITERATIONS    = 8;
	private const HISTORY_LIMIT     = 30;
	private const MAX_PAYLOAD_CHARS = 12000;
	private const MAX_RESULT_ITEMS  = 12;
	private const MAX_PROMPT_SKILLS = 50;

	/**
	 * Screen context value sent by the chat panel on the front end of the site.
	 */
	public const FRONTEND_SCREEN = AdminContext::FRONTEND_SCREEN;

	/**
	 * Conversation store.
	 *
	 * @var ConversationStore
	 */
	private ConversationStore $store;

	/**
	 * Skill store.
	 *
	 * @var SkillStore
	 */
	private SkillStore $skills;

	/**
	 * Constructor.
	 *
	 * @param ConversationStore|null $store  Conversation store.
	 * @param SkillStore|null        $skills Skill store.
	 */
	public function __construct( ?ConversationStore $store = null, ?SkillStore $skills = null ) {
		$this->store  = $store ?? new ConversationStore();
		$this->skills = $skills ?? new SkillStore();
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

		$outcome = $this->run_loop( $history, $context, $user_id, $items, $actions );

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

		$transcript = $this->merge_skill_proposals( $transcript, $items );
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
	 * @param int       $user_id Current user.
	 * @param array     $items   Transcript items (by reference).
	 * @param array     $actions UI actions (by reference).
	 * @return Message[]|WP_Error Full history including the final model message.
	 */
	private function run_loop( array $history, array $context, int $user_id, array &$items, array &$actions ) {
		$skills = Settings::skills_enabled() ? $this->skills->all( true, self::MAX_PROMPT_SKILLS ) : array();
		$turn   = new AdminContext( $context, $user_id, $skills, $this->skills );

		$turn->add_known_posts( $this->history_post_ids( $history ) );

		$tools   = ToolRegistry::build( $turn, $this->builtin_tools() );
		$outcome = AgentLoop::run(
			$history,
			$this->system_instruction( $turn, $tools ),
			$tools,
			ToolContext::ADMIN,
			self::MAX_ITERATIONS,
			function ( string $name, $payload ) use ( $turn ) {
				$turn->remember_response( $payload );

				return $this->limit_payload( $payload );
			}
		);

		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		$text = $outcome['text'];

		if ( '' === $text ) {
			$text = __( 'I could not produce an answer. Please try rephrasing your question.', 'wp-cortex' );
		}

		$items[] = array(
			'role' => 'assistant',
			'text' => $text,
		);

		$cards = $this->cited_cards( $text, $turn );

		if ( $cards ) {
			$items[] = array(
				'role'    => 'results',
				'results' => $cards,
			);
		}

		$items   = array_merge( $items, $turn->items() );
		$actions = $turn->actions();

		return $outcome['messages'];
	}

	/**
	 * Built-in tools of the admin chat, before the theme and the
	 * wp_cortex_admin_chat_tools filter change them.
	 *
	 * @return Tool[]
	 */
	private function builtin_tools(): array {
		return array(
			new AbilityTool( 'wp-cortex/search-content', array( 'To find posts by an author, use the author filter of search-content, not a text query.' ) ),
			new AbilityTool( 'wp-cortex/find-duplicates', array( 'For duplicate titles, meta descriptions or content, use find-duplicates.' ) ),
			new AbilityTool( 'wp-cortex/get-document' ),
			new AbilityTool( 'wp-cortex/list-fields', array( 'When you are unsure about field names for filters, call the list-fields tool first.' ) ),
			new OpenPost(),
			new OpenAdminPage(),
			new SelectTab(),
			new ProposeSkill(),
			new UseSkill(),
		);
	}

	/**
	 * Post IDs in the tool results of earlier turns: the model has seen them, so they can
	 * be opened and cited.
	 *
	 * @param Message[] $history Messages.
	 * @return int[]
	 */
	private function history_post_ids( array $history ): array {
		$ids = array();

		foreach ( $history as $message ) {
			foreach ( $message->getParts() as $part ) {
				$response = $part->getFunctionResponse();

				if ( $response ) {
					$ids = array_merge( $ids, AdminContext::post_ids_in( $response->getResponse() ) );
				}
			}
		}

		return $ids;
	}

	/**
	 * Replaces an earlier pending proposal for the same skill instead of adding
	 * another actionable card to the conversation.
	 *
	 * @param array $transcript Existing transcript items.
	 * @param array $items      New transcript items (by reference).
	 * @return array Updated transcript items.
	 */
	private function merge_skill_proposals( array $transcript, array &$items ): array {
		foreach ( $items as &$item ) {
			if ( 'skill_proposal' !== ( $item['role'] ?? '' ) || ! is_array( $item['skill'] ?? null ) ) {
				$transcript[] = $item;
				continue;
			}

			$name       = strtolower( (string) ( $item['skill']['name'] ?? '' ) );
			$replacement = -1;

			for ( $index = count( $transcript ) - 1; $index >= 0; $index-- ) {
				$old = $transcript[ $index ] ?? array();

				if ( 'skill_proposal' !== ( $old['role'] ?? '' ) || ! is_array( $old['skill'] ?? null ) ) {
					continue;
				}

				$old_status = array_key_exists( 'status', $old['skill'] ) ? (string) $old['skill']['status'] : '';
				$old_name   = strtolower( (string) ( $old['skill']['name'] ?? '' ) );

				if ( 'pending' === $old_status && '' !== $name && $name === $old_name ) {
					$replacement = $index;
					break;
				}
			}

			if ( $replacement < 0 ) {
				$transcript[] = $item;
				continue;
			}

			$old_id = (string) ( $transcript[ $replacement ]['skill']['proposal_id'] ?? '' );

			if ( '' !== $old_id ) {
				$item['skill']['proposal_id'] = $old_id;
			}

			$item['skill']['status'] = 'pending';
			array_splice( $transcript, $replacement, 1 );
			$transcript[] = $item;
		}
		unset( $item );

		return $transcript;
	}

	/**
	 * Cards for the posts the answer cites as #ID, in order of appearance.
	 *
	 * Only IDs returned by tools (or the post being edited) are accepted; posts not
	 * seen in this turn are built from WordPress.
	 *
	 * @param string       $text Answer text.
	 * @param AdminContext $turn Turn context.
	 * @return array<int, array<string, mixed>>
	 */
	private function cited_cards( string $text, AdminContext $turn ): array {
		preg_match_all( '/(?<![\w&])#(\d+)\b/', $text, $matches );

		$cards = array();
		$seen  = $turn->seen();

		foreach ( array_unique( array_map( 'intval', $matches[1] ) ) as $id ) {
			if ( count( $cards ) >= self::MAX_RESULT_ITEMS ) {
				break;
			}

			if ( ! $turn->is_known_post( $id ) ) {
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
	 * @param mixed $payload Tool response.
	 * @return mixed
	 */
	private function limit_payload( $payload ) {
		$json = (string) wp_json_encode( $payload, JSON_PARTIAL_OUTPUT_ON_ERROR );

		if ( strlen( $json ) <= self::MAX_PAYLOAD_CHARS ) {
			return $payload;
		}

		return array(
			'truncated'    => true,
			'partial_json' => mb_strcut( $json, 0, self::MAX_PAYLOAD_CHARS, 'UTF-8' ),
		);
	}

	/**
	 * Builds the system instruction: the general rules, the lines of the available tools,
	 * the current post and the administrator's instructions.
	 *
	 * @param AdminContext $turn  Turn context.
	 * @param ToolRegistry $tools Tools of the turn.
	 */
	private function system_instruction( AdminContext $turn, ToolRegistry $tools ): string {
		$frontend = $turn->is_frontend();
		$lines    = array(
			sprintf(
				$frontend
					? 'You are Cortex, an assistant for the administrators of the WordPress site "%1$s" (%2$s). The user is browsing the front end of the site, so admin screens and tabs cannot be opened from here. Today is %3$s.'
					: 'You are Cortex, an assistant inside the WordPress admin of the site "%1$s" (%2$s). Today is %3$s.',
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				home_url(),
				wp_date( 'Y-m-d' )
			),
			'Answer in the language the user writes in.',
			'Use your tools to find site content. Never invent posts or post IDs; only mention content returned by tools.',
		);

		$lines   = array_merge( $lines, $tools->instructions() );
		$lines[] = 'Keep answers short. Mention each relevant post by its title followed by its ID written as #123. Every post cited as #ID is shown to the user as a card below your answer, so cite only posts that answer the question, and do not repeat snippets or URLs.';

		$post_id = $turn->post_id();
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( $post && current_user_can( 'edit_post', $post_id ) ) {
			$lines[] = sprintf(
				'The user is currently %1$s post #%2$d «%3$s» (%4$s, %5$s). This is background context only: use it only when the user refers to this post (for example "this post" or "this page"), never to narrow other questions.',
				$frontend ? 'viewing' : 'editing',
				$post_id,
				get_the_title( $post ),
				$post->post_type,
				$post->post_status
			);
		}

		$custom = trim( (string) Settings::get( 'chat_instructions' ) );
		if ( '' !== $custom ) {
			$lines[] = "\nAdditional instructions from the site administrator. Follow them; they take precedence over the instructions above (for example the answer language), but never over the rule to only cite content returned by tools:\n" . $custom;
		}

		$instruction = implode( "\n", $lines );

		/**
		 * Filters the chat system instruction.
		 *
		 * @param string $instruction System instruction.
		 * @param array  $context     Screen context (screen, post_id).
		 */
		$filtered = apply_filters( 'wp_cortex_chat_system_instruction', $instruction, $turn->screen() );

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
