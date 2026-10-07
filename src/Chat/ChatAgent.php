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
	 * Seconds an action card can be confirmed after the assistant proposed it.
	 */
	private const ACTION_TTL = HOUR_IN_SECONDS;

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

		$title = '' !== $conversation['title'] ? $conversation['title'] : $this->make_title( $message );

		return $this->turn(
			$conversation,
			$title,
			$message,
			array(
				array(
					'role' => 'user',
					'text' => $message,
				),
			),
			$context,
			$user_id
		);
	}

	/**
	 * Runs or cancels an action the assistant proposed (an ability that may change the
	 * site), then lets the assistant answer with the outcome.
	 *
	 * Only the arguments stored with the action card are used, never values from the
	 * browser; the ability must still be allowed under Settings > Chat tools and checks
	 * its own permissions.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $action_id       Action ID from the card.
	 * @param bool   $run             True to run the action, false to cancel it.
	 * @param array  $context         Screen context: screen, post_id, admin_pages, tabs.
	 * @param int    $user_id         Current user.
	 * @return array{conversation_id: int, title: string, items: array, actions: array}|WP_Error
	 */
	public function resolve_action( int $conversation_id, string $action_id, bool $run, array $context, int $user_id ) {
		$conversation = $this->store->get( $conversation_id, $user_id );

		if ( null === $conversation ) {
			return new WP_Error( 'wp_cortex_not_found', __( 'Conversation not found.', 'wp-cortex' ), array( 'status' => 404 ) );
		}

		$index = null;

		foreach ( $conversation['transcript'] as $key => $item ) {
			if ( AbilityTool::ACTION_ROLE === ( $item['role'] ?? '' ) && $action_id === (string) ( $item['action']['id'] ?? '' ) ) {
				$index = $key;
				break;
			}
		}

		if ( null === $index ) {
			return new WP_Error( 'wp_cortex_not_found', __( 'Action not found.', 'wp-cortex' ), array( 'status' => 404 ) );
		}

		$action = (array) $conversation['transcript'][ $index ]['action'];

		if ( 'pending' !== ( $action['status'] ?? '' ) ) {
			return new WP_Error( 'wp_cortex_action_resolved', __( 'This action was already run or cancelled.', 'wp-cortex' ), array( 'status' => 409 ) );
		}

		$name    = (string) ( $action['ability'] ?? '' );
		$expired = time() - (int) ( $action['created'] ?? 0 ) > self::ACTION_TTL;
		$allowed = in_array( $name, Settings::chat_abilities(), true ) && Settings::tool_enabled( ToolContext::ADMIN, ( new AbilityTool( $name ) )->name() );

		if ( ! $run ) {
			$action['status'] = 'cancelled';
			$note             = sprintf( 'I cancelled the action "%1$s" (%2$s). Do not run it.', $action['label'] ?? $name, $name );
		} elseif ( $expired || ! $allowed ) {
			$action['status'] = 'failed';
			$action['error']  = $expired ? __( 'The action expired. Ask the assistant again.', 'wp-cortex' ) : __( 'This ability is no longer allowed for the chat.', 'wp-cortex' );
			$note             = sprintf( 'I tried to run the action "%1$s" (%2$s), but it was not run: %3$s', $action['label'] ?? $name, $name, $action['error'] );
		} else {
			// Mark the card first, so a second click cannot run the action twice.
			$action['status'] = 'running';

			$conversation['transcript'][ $index ]['action'] = $action;
			$this->store->save( $conversation_id, $user_id, $conversation['title'], $conversation['messages'], $conversation['transcript'] );

			$result = AbilityTool::run( $name, $action['input'] ?? null );
			$failed = is_wp_error( $result );

			$action['status'] = $failed ? 'failed' : 'done';

			if ( $failed ) {
				$action['error'] = $result->get_error_message();
			}

			$note = sprintf(
				$failed ? 'I confirmed the action "%1$s" (%2$s); it failed. Error: %3$s' : 'I confirmed the action "%1$s" (%2$s) and it ran. Result (JSON): %3$s',
				$action['label'] ?? $name,
				$name,
				$failed ? $action['error'] : (string) wp_json_encode( $this->limit_payload( $result ), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE )
			);
		}

		$conversation['transcript'][ $index ]['action'] = $action;

		return $this->turn(
			$conversation,
			$conversation['title'],
			$note . "\n(Sent by the chat interface after the user's choice on the action card. Tell the user the outcome in one or two sentences.)",
			array(
				array(
					'role'   => AbilityTool::ACTION_ROLE,
					'action' => $action,
				),
			),
			$context,
			$user_id
		);
	}

	/**
	 * Runs one model turn for a message and stores the conversation.
	 *
	 * @param array  $conversation Stored conversation (its transcript may already be updated).
	 * @param string $title        Conversation title.
	 * @param string $message      Message for the model.
	 * @param array  $items        Items of this turn shown before the answer; a user item is
	 *                             added to the transcript, an action item replaces its card.
	 * @param array  $context      Screen context.
	 * @param int    $user_id      Current user.
	 * @return array{conversation_id: int, title: string, items: array, actions: array}
	 */
	private function turn( array $conversation, string $title, string $message, array $items, array $context, int $user_id ): array {
		$conversation_id = (int) $conversation['id'];
		$transcript      = $conversation['transcript'];
		$history         = $this->decode_messages( $conversation['messages'] );
		$actions         = array();

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

		// A resolved action card is already updated in place in the transcript.
		$resolved   = array_filter( $items, static fn( $item ) => AbilityTool::ACTION_ROLE === ( $item['role'] ?? '' ) && 'pending' !== ( $item['action']['status'] ?? '' ) );
		$new_items  = array_values( array_diff_key( $items, $resolved ) );
		$transcript = $this->merge_skill_proposals( $transcript, $new_items );
		$items      = array_merge( array_values( $resolved ), $new_items );
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

		$tools   = ToolRegistry::build( $turn, self::builtin_tools() );
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
	 * Every admin chat tool for Settings > Chat tools, without the abilities of other
	 * plugins (those are listed by ability_catalog()).
	 *
	 * @return array<string, array<string, mixed>> See ToolRegistry::catalog().
	 */
	public static function tool_catalog(): array {
		$context = new AdminContext( array(), get_current_user_id(), array(), new SkillStore() );

		return ToolRegistry::catalog( $context, self::builtin_tools( false ) );
	}

	/**
	 * Registered abilities of other plugins and WordPress the admin chat can be allowed to
	 * use, for Settings > Chat tools, ordered by category and label.
	 *
	 * @return array<string, array{name: string, function: string, label: string, description: string, category: string, read_only: bool, destructive: bool, usable: bool}>
	 */
	public static function ability_catalog(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$categories = function_exists( 'wp_get_ability_categories' ) ? wp_get_ability_categories() : array();
		$rows       = array();

		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();

			if ( str_starts_with( $name, 'wp-cortex/' ) ) {
				continue;
			}

			$tool     = new AbilityTool( $name );
			$category = $ability->get_category();

			$rows[ $name ] = array(
				'name'        => $name,
				'function'    => $tool->name(),
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
				'category'    => isset( $categories[ $category ] ) ? $categories[ $category ]->get_label() : $category,
				'read_only'   => AbilityTool::is_read_only( $ability ),
				'destructive' => AbilityTool::is_destructive( $ability ),
				'usable'      => strlen( $tool->name() ) <= 64,
			);
		}

		uasort( $rows, static fn( $a, $b ) => strcasecmp( $a['category'], $b['category'] ) ?: strcasecmp( $a['label'], $b['label'] ) );

		return $rows;
	}

	/**
	 * Built-in tools of the admin chat, before the theme and the
	 * wp_cortex_admin_chat_tools filter change them: the plugin's tools and abilities,
	 * then the abilities of other plugins allowed under Settings > Chat tools.
	 *
	 * @param bool $with_abilities Whether to add the allowed abilities of other plugins.
	 * @return Tool[]
	 */
	private static function builtin_tools( bool $with_abilities = true ): array {
		$tools = array(
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

		if ( ! $with_abilities ) {
			return $tools;
		}

		foreach ( Settings::chat_abilities() as $name ) {
			$tool = new AbilityTool( $name );

			// Function names longer than providers accept are left out (and marked in the settings).
			if ( ! str_starts_with( $name, 'wp-cortex/' ) && strlen( $tool->name() ) <= 64 ) {
				$tools[] = $tool;
			}
		}

		return $tools;
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
