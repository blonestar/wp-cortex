<?php
/**
 * Tool defined by an array, for example in a theme.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * A new tool, or an override of parts of an existing one, defined by an array.
 *
 * Keys (all optional when overriding a tool, "description" and "callback" required for a new one):
 * - label        string, for example in the settings
 * - description  string|Closure( ToolContext $context, string $inherited ): string
 * - parameters   array|null|Closure( ToolContext $context, ?array $inherited ): ?array
 * - instructions string|string[]|Closure( ToolContext $context, array $inherited ): string|string[]
 * - available    bool|callable( ToolContext $context ): bool
 * - callback     callable( array $args, ToolContext $context, ?Tool $base ): mixed
 *
 * When overriding, "available" can only narrow the availability of the tool: a tool
 * the plugin does not offer (for example because its setting is off) stays hidden.
 */
final class DefinedTool extends AbstractTool {

	/**
	 * Constructor.
	 *
	 * @param string    $name       Function name.
	 * @param array     $definition Definition, see the class description.
	 * @param Tool|null $base       Tool being overridden, null for a new tool.
	 */
	public function __construct( private string $name, private array $definition, private ?Tool $base = null ) {}

	/**
	 * Whether a definition is complete enough to be a tool on its own.
	 *
	 * @param array $definition Definition.
	 */
	public static function is_complete( array $definition ): bool {
		return isset( $definition['description'] ) && isset( $definition['callback'] ) && is_callable( $definition['callback'] );
	}

	public function name(): string {
		return $this->name;
	}

	public function label(): string {
		$inherited = $this->base ? $this->base->label() : $this->name;

		return (string) ( $this->definition['label'] ?? $inherited );
	}

	/**
	 * Whether the tool is offered in this turn.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		if ( $this->base && ! $this->base->is_available( $context ) ) {
			return false;
		}

		if ( ! array_key_exists( 'available', $this->definition ) ) {
			return true;
		}

		$available = $this->definition['available'];

		return (bool) ( is_callable( $available ) ? $available( $context ) : $available );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		$inherited = $this->base ? $this->base->description( $context ) : '';

		return (string) $this->resolve( 'description', $inherited, $context );
	}

	/**
	 * JSON schema of the arguments.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>|null
	 */
	public function parameters( ToolContext $context ): ?array {
		$inherited = $this->base ? $this->base->parameters( $context ) : null;
		$value     = $this->resolve( 'parameters', $inherited, $context );

		return is_array( $value ) && $value ? $value : null;
	}

	/**
	 * Lines added to the system instruction.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		$inherited = $this->base ? $this->base->instructions( $context ) : array();
		$value     = $this->resolve( 'instructions', $inherited, $context );

		return array_values( array_filter( array_map( 'strval', (array) $value ), static fn( string $line ) => '' !== trim( $line ) ) );
	}

	/**
	 * Runs the callback, or the overridden tool when there is none.
	 *
	 * @param array       $args    Arguments from the model.
	 * @param ToolContext $context Turn context.
	 * @return mixed
	 */
	public function execute( array $args, ToolContext $context ) {
		$callback = $this->definition['callback'] ?? null;

		if ( is_callable( $callback ) ) {
			return $callback( $args, $context, $this->base );
		}

		if ( $this->base ) {
			return $this->base->execute( $args, $context );
		}

		return array( 'error' => 'This tool is not available.' );
	}

	/**
	 * Value of a definition key: a closure (or invokable object) gets the context and the inherited value.
	 *
	 * @param string      $key       Definition key.
	 * @param mixed       $inherited Value of the overridden tool or the default.
	 * @param ToolContext $context   Turn context.
	 * @return mixed
	 */
	private function resolve( string $key, $inherited, ToolContext $context ) {
		if ( ! array_key_exists( $key, $this->definition ) ) {
			return $inherited;
		}

		$value = $this->definition[ $key ];

		// Strings and arrays are values, even when they happen to name a function.
		if ( is_object( $value ) && is_callable( $value ) ) {
			return $value( $context, $inherited );
		}

		return $value;
	}
}
