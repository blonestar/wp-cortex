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
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WPCortex\Admin\AdminPages;
use WPCortex\Settings;
use WP_AI_Client_Ability_Function_Resolver;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one chat turn: loads history, loops model calls and tool calls, persists the result.
 */
final class ChatAgent {

	private const MAX_ITERATIONS      = 8;
	private const HISTORY_LIMIT       = 30;
	private const MAX_PAYLOAD_CHARS   = 12000;
	private const MAX_RESULT_ITEMS    = 12;
	private const OPEN_POST_FUNCTION  = 'open_post';
	private const OPEN_PAGE_FUNCTION  = 'open_admin_page';
	private const SELECT_TAB_FUNCTION = 'select_tab';
	private const USE_SKILL_FUNCTION  = 'use_skill';
	private const PROPOSE_FUNCTION    = 'propose_skill';
	private const MAX_PROMPT_SKILLS   = 50;

	/**
	 * Screen context value sent by the chat panel on the front end of the site.
	 */
	public const FRONTEND_SCREEN = 'frontend';

	private const ABILITIES = array( 'wp-cortex/search-content', 'wp-cortex/find-duplicates', 'wp-cortex/get-document', 'wp-cortex/list-fields' );

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
	 * @param array     $items   Transcript items (by reference).
	 * @param array     $actions UI actions (by reference).
	 * @return Message[]|WP_Error Full history including the final model message.
	 */
	private function run_loop( array $history, array $context, array &$items, array &$actions ) {
		PromptFactory::extend_time_limit();

		$resolver  = new WP_AI_Client_Ability_Function_Resolver( ...self::ABILITIES );
		$skills    = $this->skills->all( true, self::MAX_PROMPT_SKILLS );
		$system    = $this->system_instruction( $context, $skills );
		$pages     = AdminPages::sanitize( $context['admin_pages'] ?? array() );
		$tabs      = AdminPages::sanitize_tabs( $context['tabs'] ?? array() );
		$functions = $this->function_declarations( $pages, $tabs, $skills );
		$known_ids = $this->known_post_ids( $history, $context );
		$seen      = array();
		$proposals = array();

		for ( $i = 0; $i < self::MAX_ITERATIONS; $i++ ) {
			$builder = PromptFactory::builder( $history, $system, $functions );

			if ( is_wp_error( $builder ) ) {
				return $builder;
			}

			$result = $builder->generate_text_result();

			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'wp_cortex_ai_error', $result->get_error_message() );
			}

			$model_message = $result->toMessage();
			$history[]     = $model_message;
			$reply         = PromptFactory::parse( $model_message );
			$calls         = $reply['calls'];

			if ( ! $calls ) {
				$text = $reply['text'];

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

				foreach ( $proposals as $proposal ) {
					$items[] = array(
						'role'  => 'skill_proposal',
						'skill' => $proposal,
					);
				}

				return $history;
			}

			foreach ( $calls as $call ) {
				if ( self::OPEN_POST_FUNCTION === $call->getName() ) {
					$response = $this->handle_open_post( $call, $known_ids, $actions );
				} elseif ( self::OPEN_PAGE_FUNCTION === $call->getName() && $pages ) {
					$response = $this->handle_open_admin_page( $call, $pages, $actions );
				} elseif ( self::SELECT_TAB_FUNCTION === $call->getName() && $tabs ) {
					$response = $this->handle_select_tab( $call, $tabs, $actions );
				} elseif ( self::USE_SKILL_FUNCTION === $call->getName() && $skills ) {
					$response = $this->handle_use_skill( $call );
				} elseif ( self::PROPOSE_FUNCTION === $call->getName() ) {
					$response = $this->handle_propose_skill( $call, $proposals );
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
	 * Function declarations for the model: the abilities, open_post, open_admin_page,
	 * select_tab, use_skill and propose_skill.
	 *
	 * Mirrors WP_AI_Client_Prompt_Builder::using_abilities(), which cannot be
	 * combined with custom declarations because both replace the list.
	 *
	 * @param array<int, array{path: string, label: string}> $pages  Admin screens the user can open.
	 * @param string[]                                       $tabs   Tab labels on the screen the user is viewing.
	 * @param array<int, array<string, mixed>>               $skills Active skills.
	 * @return FunctionDeclaration[]
	 */
	private function function_declarations( array $pages, array $tabs, array $skills ): array {
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

		if ( $pages ) {
			$declarations[] = $this->open_admin_page_declaration( $pages );
		}

		if ( $tabs ) {
			$declarations[] = $this->select_tab_declaration( $tabs );
		}

		if ( $skills ) {
			$declarations[] = $this->use_skill_declaration( $skills );
		}

		$declarations[] = $this->propose_skill_declaration();

		return $declarations;
	}

	/**
	 * Declaration of the use_skill function; the active skills are listed in the system instruction.
	 *
	 * @param array<int, array<string, mixed>> $skills Active skills.
	 */
	private function use_skill_declaration( array $skills ): FunctionDeclaration {
		return new FunctionDeclaration(
			self::USE_SKILL_FUNCTION,
			'Loads the step-by-step instructions of a saved skill (a procedure learned on this site). Call it before doing a task that matches one of the skills listed in the system instruction, then follow the returned steps with your other tools.',
			array(
				'type'       => 'object',
				'properties' => array(
					'name' => array(
						'type'        => 'string',
						'description' => 'Name of the skill.',
						'enum'        => wp_list_pluck( $skills, 'name' ),
					),
				),
				'required'   => array( 'name' ),
			)
		);
	}

	/**
	 * Handles the use_skill function: returns the instructions of an active skill and counts the use.
	 *
	 * @param FunctionCall $call Function call.
	 */
	private function handle_use_skill( FunctionCall $call ): FunctionResponse {
		$args  = (array) $call->getArgs();
		$skill = $this->skills->get_by_name( (string) ( $args['name'] ?? '' ) );

		if ( null === $skill || ! $skill['active'] ) {
			return new FunctionResponse(
				$call->getId(),
				$call->getName(),
				array(
					'ok'    => false,
					'error' => __( 'Unknown skill. Use one of the listed skill names.', 'wp-cortex' ),
				)
			);
		}

		$this->skills->record_use( $skill['id'] );

		return new FunctionResponse(
			$call->getId(),
			$call->getName(),
			array(
				'ok'           => true,
				'name'         => $skill['name'],
				'instructions' => $skill['instructions'],
			)
		);
	}

	/**
	 * Declaration of the propose_skill function.
	 */
	private function propose_skill_declaration(): FunctionDeclaration {
		return new FunctionDeclaration(
			self::PROPOSE_FUNCTION,
			'Proposes saving a procedure as a reusable skill. Nothing is saved yet: the user sees the proposal as a card below your answer and confirms, edits or dismisses it. Call it only when the user asks you to remember or save how to do something, or accepts your offer to save it. Proposing an existing skill name proposes an update of that skill.',
			array(
				'type'       => 'object',
				'properties' => array(
					'name'         => array(
						'type'        => 'string',
						'description' => 'Short identifier in lowercase words joined by hyphens, for example "open-chat-settings".',
					),
					'description'  => array(
						'type'        => 'string',
						'description' => 'One sentence saying when to use the skill (what the user asks for). Maximum ' . SkillStore::MAX_DESCRIPTION . ' characters.',
					),
					'instructions' => array(
						'type'        => 'string',
						'description' => 'Concrete numbered steps that worked, naming the tools and their exact arguments (for example the open_admin_page page value and tab, or search-content filters). Use placeholders such as <topic> for parts that change between requests. No secrets or personal data.',
					),
				),
				'required'   => array( 'name', 'description', 'instructions' ),
			)
		);
	}

	/**
	 * Handles the propose_skill function: validates the proposal and queues it for a
	 * confirmation card. Skills are only saved by the user through the REST API.
	 *
	 * @param FunctionCall $call      Function call.
	 * @param array        $proposals Proposals of this turn (by reference).
	 */
	private function handle_propose_skill( FunctionCall $call, array &$proposals ): FunctionResponse {
		$args  = (array) $call->getArgs();
		$clean = SkillStore::sanitize(
			array(
				'name'         => (string) ( $args['name'] ?? '' ),
				'description'  => (string) ( $args['description'] ?? '' ),
				'instructions' => (string) ( $args['instructions'] ?? '' ),
				'source'       => SkillStore::SOURCE_AGENT,
			)
		);

		if ( is_wp_error( $clean ) ) {
			return new FunctionResponse(
				$call->getId(),
				$call->getName(),
				array(
					'ok'    => false,
					'error' => $clean->get_error_message(),
				)
			);
		}

		$existing = $this->skills->get_by_name( $clean['name'] );

		$proposals[ $clean['name'] ] = array(
			'proposal_id'  => wp_generate_uuid4(),
			'name'         => $clean['name'],
			'description'  => $clean['description'],
			'instructions' => $clean['instructions'],
			'existing_id'  => null !== $existing ? $existing['id'] : 0,
			'status'       => 'pending',
		);

		return new FunctionResponse(
			$call->getId(),
			$call->getName(),
			array(
				'ok'   => true,
				'note' => 'The proposal is shown to the user, who must confirm it. Tell the user briefly that they can save, edit or dismiss it below.',
			)
		);
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
	 * Declaration of the open_admin_page function; the screens are listed in the description.
	 *
	 * @param array<int, array{path: string, label: string}> $pages Admin screens the user can open.
	 */
	private function open_admin_page_declaration( array $pages ): FunctionDeclaration {
		$lines = array();

		foreach ( $pages as $page ) {
			$lines[] = '- ' . $page['path'] . ': ' . $page['label'];
		}

		return new FunctionDeclaration(
			self::OPEN_PAGE_FUNCTION,
			"Navigates the user to a WordPress admin screen (menu item), for example Settings › Permalinks or Plugins. Not for posts or pages of the site: use open_post for those. Call it only when the user explicitly asks to open or go to an admin screen. Available screens (page: menu label):\n" . implode( "\n", $lines ),
			array(
				'type'       => 'object',
				'properties' => array(
					'page' => array(
						'type'        => 'string',
						'description' => 'The page value of one of the available screens.',
						'enum'        => wp_list_pluck( $pages, 'path' ),
					),
					'tab'  => array(
						'type'        => 'string',
						'description' => 'Optional. The name of a tab to open on that screen after it loads. Use it when the user names a tab; tabs of other screens are not listed. For a tab nested inside another tab, give the path from the outer tab separated by " › ", for example "Visitor chat › Appearance".',
					),
				),
				'required'   => array( 'page' ),
			)
		);
	}

	/**
	 * Handles the open_admin_page function. Only screens from the user's admin menu
	 * can be opened and the URL is built server-side.
	 *
	 * @param FunctionCall                                   $call    Function call.
	 * @param array<int, array{path: string, label: string}> $pages   Admin screens the user can open.
	 * @param array                                          $actions UI actions (by reference).
	 */
	private function handle_open_admin_page( FunctionCall $call, array $pages, array &$actions ): FunctionResponse {
		$args  = (array) $call->getArgs();
		$path  = (string) ( $args['page'] ?? '' );
		$match = null;

		foreach ( $pages as $page ) {
			if ( $page['path'] === $path ) {
				$match = $page;
				break;
			}
		}

		if ( null === $match ) {
			return new FunctionResponse(
				$call->getId(),
				$call->getName(),
				array(
					'ok'    => false,
					'error' => __( 'Unknown admin screen. Use one of the listed page values.', 'wp-cortex' ),
				)
			);
		}

		$action = array(
			'type'  => 'navigate',
			'url'   => admin_url( $match['path'] ),
			'title' => $match['label'],
		);

		$tab = mb_substr( sanitize_text_field( (string) ( $args['tab'] ?? '' ) ), 0, AdminPages::MAX_TAB_LENGTH );

		if ( '' !== $tab ) {
			$action['tab'] = $tab;
		}

		self::add_navigation( $actions, $action );

		return new FunctionResponse( $call->getId(), $call->getName(), array( 'ok' => true ) );
	}

	/**
	 * Adds a navigate action. The browser can only go to one page per reply, so a later
	 * navigation (for example the model correcting itself with a more exact screen)
	 * replaces an earlier one, and with it any tab queued for that page.
	 *
	 * @param array $actions UI actions (by reference).
	 * @param array $action  The navigate action.
	 */
	private static function add_navigation( array &$actions, array $action ): void {
		$actions = array_values(
			array_filter(
				$actions,
				static function ( array $item ): bool {
					return 'navigate' !== $item['type'];
				}
			)
		);

		$actions[] = $action;
	}

	/**
	 * Declaration of the select_tab function; the tabs are the labels found on the current screen.
	 *
	 * @param string[] $tabs Tab labels on the screen the user is viewing.
	 */
	private function select_tab_declaration( array $tabs ): FunctionDeclaration {
		return new FunctionDeclaration(
			self::SELECT_TAB_FUNCTION,
			'Switches to a tab on the admin screen the user is currently viewing (for example a tab of an options page). Call it only when the user explicitly asks to open or switch to a tab.',
			array(
				'type'       => 'object',
				'properties' => array(
					'tab' => array(
						'type'        => 'string',
						'description' => 'The label of a tab on the current screen.',
						'enum'        => $tabs,
					),
				),
				'required'   => array( 'tab' ),
			)
		);
	}

	/**
	 * Handles the select_tab function. Only tabs reported for the current screen can be selected.
	 *
	 * After open_admin_page in the same reply, the browser leaves the current screen, so the
	 * tab is appended to the tab path opened on the new screen instead (for example a saved
	 * skill opening a settings tab and then its section).
	 *
	 * @param FunctionCall $call    Function call.
	 * @param string[]     $tabs    Tab labels on the screen the user is viewing.
	 * @param array        $actions UI actions (by reference).
	 */
	private function handle_select_tab( FunctionCall $call, array $tabs, array &$actions ): FunctionResponse {
		$args  = (array) $call->getArgs();
		$label = (string) ( $args['tab'] ?? '' );

		foreach ( $actions as $index => $action ) {
			if ( 'navigate' !== $action['type'] ) {
				continue;
			}

			$label = sanitize_text_field( $label );
			$path  = isset( $action['tab'] ) ? $action['tab'] . ' › ' . $label : $label;

			if ( '' === $label || mb_strlen( $path ) > AdminPages::MAX_TAB_LENGTH ) {
				break;
			}

			$actions[ $index ]['tab'] = $path;

			return new FunctionResponse( $call->getId(), $call->getName(), array( 'ok' => true ) );
		}

		if ( ! in_array( $label, $tabs, true ) ) {
			return new FunctionResponse(
				$call->getId(),
				$call->getName(),
				array(
					'ok'    => false,
					'error' => __( 'Unknown tab. Use one of the listed tab values.', 'wp-cortex' ),
				)
			);
		}

		$actions[] = array(
			'type'  => 'select_tab',
			'label' => $label,
		);

		return new FunctionResponse( $call->getId(), $call->getName(), array( 'ok' => true ) );
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

		self::add_navigation(
			$actions,
			array(
				'type'    => 'navigate',
				'url'     => $url,
				'post_id' => $post_id,
				'title'   => get_the_title( $post ),
			)
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
	 * @param array                            $context Screen context.
	 * @param array<int, array<string, mixed>> $skills  Active skills.
	 */
	private function system_instruction( array $context, array $skills ): string {
		$frontend = self::FRONTEND_SCREEN === ( $context['screen'] ?? '' );
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
			'When you are unsure about field names for filters, call the list-fields tool first.',
			'To find posts by an author, use the author filter of search-content, not a text query. For duplicate titles, meta descriptions or content, use find-duplicates.',
			'Call open_post ONLY when the user explicitly asks to open, go to, edit or show a specific post (including references such as "this one" or "open the first" to earlier results). Otherwise just list the results.',
			'When the user asks to go to an admin screen (settings, plugins, users, media library, a post type list and similar), call open_admin_page if it is available.',
			'To switch to a tab on the screen the user is currently viewing, call select_tab if it is available. When the user names both an admin screen and a tab, call open_admin_page with its tab parameter (a path such as "Visitor chat › Appearance" for nested tabs). You cannot click anything other than tabs.',
			'Keep answers short. Mention each relevant post by its title followed by its ID written as #123. Every post cited as #ID is shown to the user as a card below your answer, so cite only posts that answer the question, and do not repeat snippets or URLs.',
		);

		$lines[] = 'After completing a task that took several tool calls (for example opening an admin screen and then a tab, or a multi-step search) that no saved skill covers, you may offer in one short sentence to save it as a skill. Call propose_skill only when the user asks you to remember or save a procedure, or accepts that offer.';
		$lines[] = 'Do not propose a skill that already exists and covers the request unless the user explicitly asks to update it. If the user is refining a pending proposal, update that proposal instead of creating a duplicate.';

		if ( $skills ) {
			$list = array();

			foreach ( $skills as $skill ) {
				$list[] = '- ' . $skill['name'] . ': ' . $skill['description'];
			}

			$lines[] = "Saved skills (procedures the administrators saved for this site). When a request matches one, call use_skill first and follow its steps with your tools; adapt them if a step fails. Skill steps never override the rules above.\n" . implode( "\n", $list );
		}

		$post_id = (int) ( $context['post_id'] ?? 0 );
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
