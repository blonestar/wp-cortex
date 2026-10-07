<?php
/**
 * Tools offered to a chat agent in one turn.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools;

use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WP_AI_Client_Ability_Function_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the tools of a chat: the built-in ones, then those of the theme, then the
 * wp_cortex_admin_chat_tools / wp_cortex_public_chat_tools filter, and keeps those
 * available in this turn.
 *
 * Theme tools are PHP files in wp-cortex/tools/admin/ (admin chat) or
 * wp-cortex/tools/public/ (visitor chat) of the active theme; a child theme file
 * replaces the parent theme file with the same name. Each file is named after the
 * tool and returns a Tool, a definition array (see DefinedTool; for a built-in tool
 * of the same name it overrides only the keys it gives) or false to remove the tool.
 * Files starting with "_" are not loaded.
 *
 * The visitor chat never gets abilities or admin tools, whatever the theme or the
 * filter return.
 */
final class ToolRegistry {

	private const NAME_PATTERN    = '/^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/';
	private const THEME_DIRECTORY = 'wp-cortex/tools';
	private const ADMIN_NAMESPACE = __NAMESPACE__ . '\\Admin\\';

	/**
	 * Available tools keyed by name.
	 *
	 * @var array<string, Tool>
	 */
	private array $tools = array();

	/**
	 * Function declarations of the available tools.
	 *
	 * @var FunctionDeclaration[]
	 */
	private array $declarations = array();

	/**
	 * System instruction lines of the available tools.
	 *
	 * @var string[]
	 */
	private array $instructions = array();

	/**
	 * Constructor.
	 *
	 * @param ToolContext $context Turn context.
	 */
	private function __construct( private ToolContext $context ) {}

	/**
	 * Builds the tools of a turn.
	 *
	 * @param ToolContext $context  Turn context; its scope selects the theme folder and the filter.
	 * @param Tool[]      $builtins Tools of the plugin.
	 */
	public static function build( ToolContext $context, array $builtins ): self {
		$registry = new self( $context );
		$scope    = $context->scope();
		$tools    = array();

		foreach ( $builtins as $tool ) {
			$tools[ $tool->name() ] = $tool;
		}

		foreach ( self::theme_definitions( $scope ) as $name => $value ) {
			$tools = $registry->apply( $tools, $name, $value );
		}

		/**
		 * Filters the tools of the admin chat (wp_cortex_admin_chat_tools) or the visitor
		 * chat (wp_cortex_public_chat_tools).
		 *
		 * Values may be Tool objects or definition arrays (see DefinedTool); an array
		 * overrides only the keys it gives of the tool with the same key. Remove a key to
		 * remove the tool.
		 *
		 * @param array<string, Tool|array> $tools   Tools keyed by name.
		 * @param ToolContext               $context Turn context (AdminContext or PublicContext).
		 */
		$filtered = apply_filters( "wp_cortex_{$scope}_chat_tools", $tools, $context );

		if ( is_array( $filtered ) ) {
			$result = array();

			foreach ( $filtered as $name => $value ) {
				$base   = isset( $tools[ $name ] ) ? array( $name => $tools[ $name ] ) : array();
				$result = array_merge( $result, $registry->apply( $base, (string) $name, $value ) );
			}

			$tools = $result;
		}

		foreach ( $tools as $name => $tool ) {
			$registry->add( $name, $tool );
		}

		return $registry;
	}

	/**
	 * Whether a tool is offered in this turn.
	 *
	 * @param string $name Function name.
	 */
	public function has( string $name ): bool {
		return isset( $this->tools[ $name ] );
	}

	/**
	 * Available tool by name.
	 *
	 * @param string $name Function name.
	 */
	public function get( string $name ): ?Tool {
		return $this->tools[ $name ] ?? null;
	}

	/**
	 * Available tools keyed by name.
	 *
	 * @return array<string, Tool>
	 */
	public function all(): array {
		return $this->tools;
	}

	/**
	 * Function declarations for the model.
	 *
	 * @return FunctionDeclaration[]
	 */
	public function declarations(): array {
		return $this->declarations;
	}

	/**
	 * System instruction lines of the available tools, in tool order.
	 *
	 * @return string[]
	 */
	public function instructions(): array {
		return $this->instructions;
	}

	/**
	 * Runs a function call.
	 *
	 * @param FunctionCall $call Function call from the model.
	 * @return mixed Response, or null when no available tool has this name.
	 */
	public function execute( FunctionCall $call ) {
		$tool = $this->tools[ (string) $call->getName() ] ?? null;

		if ( null === $tool ) {
			return null;
		}

		try {
			return $tool->execute( (array) $call->getArgs(), $this->context );
		} catch ( \Throwable $e ) {
			unset( $e );

			// Details of a failing theme or plugin tool are not for the model (or the visitor).
			return array( 'error' => 'The tool failed. Try another way or tell the user it is not possible right now.' );
		}
	}

	/**
	 * Adds a tool when it is available, with its declaration and instruction lines.
	 * A tool that throws while being described is left out.
	 *
	 * @param string $name Function name.
	 * @param Tool   $tool Tool.
	 */
	private function add( string $name, Tool $tool ): void {
		try {
			if ( ! $tool->is_available( $this->context ) ) {
				return;
			}

			$parameters  = $tool->parameters( $this->context );
			$declaration = new FunctionDeclaration( $name, $tool->description( $this->context ), $parameters ? $parameters : null );
			$lines       = $tool->instructions( $this->context );
		} catch ( \Throwable $e ) {
			unset( $e );
			return;
		}

		$this->tools[ $name ] = $tool;
		$this->declarations[] = $declaration;
		$this->instructions   = array_merge( $this->instructions, array_values( array_map( 'strval', $lines ) ) );
	}

	/**
	 * Applies one theme or filter value to the tool list.
	 *
	 * @param array<string, Tool> $tools Tools keyed by name.
	 * @param string              $name  Key (file name or filter key).
	 * @param mixed               $value Tool, definition array or false.
	 * @return array<string, Tool>
	 */
	private function apply( array $tools, string $name, $value ): array {
		$base = $tools[ $name ] ?? null;

		if ( false === $value ) {
			unset( $tools[ $name ] );

			return $tools;
		}

		if ( $value instanceof Tool ) {
			$tool = $value;
		} elseif ( is_array( $value ) && ( $base || DefinedTool::is_complete( $value ) ) ) {
			$tool = new DefinedTool( $name, $value, $base );
		} else {
			self::doing_it_wrong( sprintf( 'The chat tool "%s" must be a Tool object or an array with at least a description and a callback.', $name ) );

			return $tools;
		}

		if ( ! $this->is_allowed( $tool, null !== $base ) ) {
			return $tools;
		}

		// A tool object may be stored under another key: its own name is what the model calls.
		// An override with the same name keeps the position (and so the instruction order).
		if ( $tool->name() !== $name ) {
			unset( $tools[ $name ] );
		}

		$tools[ $tool->name() ] = $tool;

		return $tools;
	}

	/**
	 * Whether a tool may be added: a valid name, abilities only in the admin chat (and
	 * only by overriding one or as an AbilityTool), no admin tools in the visitor chat.
	 *
	 * @param Tool $tool     Tool.
	 * @param bool $override Whether it replaces a tool of the same name.
	 */
	private function is_allowed( Tool $tool, bool $override ): bool {
		$name       = $tool->name();
		$is_ability = str_starts_with( $name, WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( '' ) );
		$is_admin   = str_starts_with( get_class( $tool ), self::ADMIN_NAMESPACE );

		if ( ! preg_match( self::NAME_PATTERN, $name ) ) {
			self::doing_it_wrong( sprintf( 'Invalid chat tool name "%s": use letters, digits, "_" and "-", up to 64 characters.', $name ) );

			return false;
		}

		if ( ToolContext::PUBLIC === $this->context->scope() && ( $is_ability || $is_admin ) ) {
			self::doing_it_wrong( sprintf( 'The chat tool "%s" is an admin tool and cannot be offered to visitors.', $name ) );

			return false;
		}

		if ( $is_ability && ! $override && ! $tool instanceof Admin\AbilityTool ) {
			self::doing_it_wrong( sprintf( 'The chat tool name "%s" is reserved for abilities.', $name ) );

			return false;
		}

		return true;
	}

	/**
	 * Values returned by the theme's tool files for a scope; child theme files replace
	 * parent theme files with the same name.
	 *
	 * @param string $scope ToolContext::ADMIN or ToolContext::PUBLIC.
	 * @return array<string, mixed>
	 */
	private static function theme_definitions( string $scope ): array {
		$definitions = array();
		$directories = array_unique( array( get_template_directory(), get_stylesheet_directory() ) );
		$load        = static function ( string $file ) {
			return include $file;
		};

		foreach ( $directories as $directory ) {
			$files = glob( trailingslashit( $directory ) . self::THEME_DIRECTORY . '/' . $scope . '/*.php' );

			foreach ( $files ? $files : array() as $file ) {
				$name = basename( $file, '.php' );

				if ( str_starts_with( $name, '_' ) ) {
					continue;
				}

				$definitions[ $name ] = $load( $file );
			}
		}

		return $definitions;
	}

	/**
	 * Reports a misconfigured tool to developers.
	 *
	 * @param string $message Message.
	 */
	private static function doing_it_wrong( string $message ): void {
		_doing_it_wrong( __CLASS__, esc_html( $message ), '0.10.0' );
	}
}
