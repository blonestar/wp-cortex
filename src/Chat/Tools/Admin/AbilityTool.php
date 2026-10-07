<?php
/**
 * Admin chat tool backed by a WordPress ability.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\AbstractTool;
use WPCortex\Chat\Tools\ToolContext;
use WP_AI_Client_Ability_Function_Resolver;
use WP_Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Offers one registered ability to the admin chat. The ability's own permission
 * callback still decides whether the current user may run it.
 *
 * Abilities not annotated as read-only may change the site, so they never run when the
 * model calls them: the call is checked and shown to the user as an action card
 * (transcript item "ability_action"), and the ability runs only after the user confirms
 * it (ChatAgent::resolve_action()).
 */
final class AbilityTool extends AbstractTool {

	/**
	 * Transcript role of an action waiting for the user's confirmation.
	 */
	public const ACTION_ROLE = 'ability_action';

	/**
	 * Argument that carries the input of an ability whose input is not an object.
	 */
	public const INPUT_ARGUMENT = 'input';

	/**
	 * Schema keywords whose value is a map of subschemas (a JSON object, also when empty).
	 */
	private const SCHEMA_MAPS = array( 'properties', 'patternProperties', 'definitions', '$defs', 'dependencies' );

	/**
	 * Schema keywords whose value is a list of subschemas.
	 */
	private const SCHEMA_LISTS = array( 'anyOf', 'oneOf', 'allOf' );

	/**
	 * Constructor.
	 *
	 * @param string   $ability      Ability name, for example "wp-cortex/search-content".
	 * @param string[] $instructions System instruction lines for this ability.
	 */
	public function __construct( private string $ability, private array $instructions = array() ) {}

	/**
	 * Function name: the ability name in the form the WordPress AI Client uses.
	 */
	public function name(): string {
		return WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $this->ability );
	}

	/**
	 * Ability name.
	 */
	public function ability_name(): string {
		return $this->ability;
	}

	public function label(): string {
		$ability = $this->ability();

		return $ability ? $ability->get_label() : $this->ability;
	}

	/**
	 * Whether the ability may change the site and so runs only after the user confirms it:
	 * every ability not annotated as read-only.
	 */
	public function requires_confirmation(): bool {
		return ! self::is_read_only( $this->ability() );
	}

	/**
	 * Whether an ability is annotated as read-only.
	 *
	 * @param WP_Ability|null $ability Ability.
	 */
	public static function is_read_only( ?WP_Ability $ability ): bool {
		$annotations = $ability ? (array) $ability->get_meta_item( 'annotations', array() ) : array();

		return true === ( $annotations['readonly'] ?? null );
	}

	/**
	 * Whether an ability is annotated as destructive.
	 *
	 * @param WP_Ability|null $ability Ability.
	 */
	public static function is_destructive( ?WP_Ability $ability ): bool {
		$annotations = $ability ? (array) $ability->get_meta_item( 'annotations', array() ) : array();

		return true === ( $annotations['destructive'] ?? null );
	}

	/**
	 * Whether the ability needs a confirmation in the chat.
	 */
	public function availability_note(): string {
		return $this->requires_confirmation() ? __( 'May change the site: runs only after you confirm it in the chat.', 'wp-cortex' ) : '';
	}

	/**
	 * Available when the ability is registered.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		return null !== $this->ability();
	}

	/**
	 * Description of the ability.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		$ability = $this->ability();

		return $ability ? $ability->get_description() : '';
	}

	/**
	 * Input schema of the ability, prepared for the model. Providers only accept an
	 * object schema: an ability without input (no schema or "type": "null") gets no
	 * arguments, an object schema that also allows null becomes a plain object schema,
	 * and any other input is wrapped in the INPUT_ARGUMENT argument.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>|null
	 */
	public function parameters( ToolContext $context ): ?array {
		$ability = $this->ability();
		$schema  = $ability ? $ability->get_input_schema() : array();

		if ( empty( $schema ) || ! is_array( $schema ) ) {
			return null;
		}

		$schema = self::client_schema( wp_prepare_json_schema_for_client( $schema ) );
		$types  = self::schema_types( $schema );

		if ( ! $types || array( 'null' ) === $types ) {
			return null;
		}

		if ( in_array( 'object', $types, true ) ) {
			// An object that accepts no properties at all is an ability without input.
			if ( ! isset( $schema['properties'] ) && false === ( $schema['additionalProperties'] ?? true ) ) {
				return null;
			}

			$schema['type'] = 'object';

			return $schema;
		}

		return array(
			'type'       => 'object',
			'properties' => array( self::INPUT_ARGUMENT => $schema ),
			'required'   => in_array( 'null', $types, true ) ? array() : array( self::INPUT_ARGUMENT ),
		);
	}

	/**
	 * Input of the ability from the model's arguments: the wrapped value for an ability
	 * whose input is not an object, null for an ability without input.
	 *
	 * @param array $args Arguments from the model.
	 * @return mixed
	 */
	private function input( array $args ) {
		$ability = $this->ability();
		$schema  = $ability ? $ability->get_input_schema() : array();
		$types   = is_array( $schema ) ? self::schema_types( $schema ) : array();

		if ( ! $types || array( 'null' ) === $types ) {
			return null;
		}

		if ( in_array( 'object', $types, true ) ) {
			return ! empty( $args ) ? $args : null;
		}

		return $args[ self::INPUT_ARGUMENT ] ?? null;
	}

	/**
	 * Fixes what PHP arrays cannot express in JSON: an empty "properties" (or another map
	 * of subschemas) is encoded as [] instead of {}, which providers reject. Empty maps
	 * are dropped, in every subschema.
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	private static function client_schema( array $schema ): array {
		foreach ( self::SCHEMA_MAPS as $keyword ) {
			if ( ! array_key_exists( $keyword, $schema ) ) {
				continue;
			}

			if ( ! is_array( $schema[ $keyword ] ) || ! $schema[ $keyword ] ) {
				unset( $schema[ $keyword ] );
				continue;
			}

			foreach ( $schema[ $keyword ] as $name => $subschema ) {
				if ( is_array( $subschema ) ) {
					$schema[ $keyword ][ $name ] = self::client_schema( $subschema );
				}
			}
		}

		foreach ( self::SCHEMA_LISTS as $keyword ) {
			if ( isset( $schema[ $keyword ] ) && is_array( $schema[ $keyword ] ) ) {
				$schema[ $keyword ] = array_map(
					static function ( $subschema ) {
						return is_array( $subschema ) ? self::client_schema( $subschema ) : $subschema;
					},
					$schema[ $keyword ]
				);
			}
		}

		foreach ( array( 'items', 'additionalProperties', 'not' ) as $keyword ) {
			if ( ! isset( $schema[ $keyword ] ) || ! is_array( $schema[ $keyword ] ) ) {
				continue;
			}

			if ( ! $schema[ $keyword ] ) {
				// An empty schema allows anything.
				$schema[ $keyword ] = (object) array();
			} elseif ( wp_is_numeric_array( $schema[ $keyword ] ) ) {
				$schema[ $keyword ] = array_map(
					static function ( $subschema ) {
						return is_array( $subschema ) ? self::client_schema( $subschema ) : $subschema;
					},
					$schema[ $keyword ]
				);
			} else {
				$schema[ $keyword ] = self::client_schema( $schema[ $keyword ] );
			}
		}

		return $schema;
	}

	/**
	 * JSON Schema types of a schema ("type" may be a string or a list).
	 *
	 * @param array $schema Schema.
	 * @return string[]
	 */
	private static function schema_types( array $schema ): array {
		return array_values( array_filter( array_map( 'strval', (array) ( $schema['type'] ?? array() ) ) ) );
	}

	/**
	 * System instruction lines for this ability.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		if ( ! $this->requires_confirmation() ) {
			return $this->instructions;
		}

		return array_merge(
			$this->instructions,
			array( 'Tools that can change the site never run right away: the user sees the call as an action card and must confirm it. Call such a tool only when the user asks for that change, once, with complete arguments; then briefly say what it will do and ask the user to confirm or cancel it below. Never claim the change was made before the user confirmed it.' )
		);
	}

	/**
	 * Runs a read-only ability, or checks the call of any other ability and shows it to
	 * the user for confirmation. Errors are returned like WP_AI_Client_Ability_Function_Resolver
	 * returns them.
	 *
	 * @param array       $args    Arguments from the model.
	 * @param ToolContext $context Turn context.
	 * @return mixed
	 */
	public function execute( array $args, ToolContext $context ) {
		if ( ! $this->requires_confirmation() ) {
			$result = self::run( $this->ability, $this->input( $args ) );

			return is_wp_error( $result ) ? self::error( $result ) : $result;
		}

		$ability = $this->ability();

		if ( ! $ability || ! $context instanceof AdminContext ) {
			return self::error( self::not_found( $this->ability ) );
		}

		$input = $ability->normalize_input( $this->input( $args ) );
		$check = $ability->validate_input( $input );

		if ( true === $check ) {
			$check = $ability->check_permissions( $input );
		}

		if ( true !== $check ) {
			return is_wp_error( $check ) ? self::error( $check ) : array(
				'error' => 'The current user is not allowed to run this ability.',
				'code'  => 'ability_forbidden',
			);
		}

		$id = wp_generate_uuid4();

		$context->set_item(
			'action:' . $id,
			array(
				'role'   => self::ACTION_ROLE,
				'action' => array(
					'id'          => $id,
					'ability'     => $this->ability,
					'label'       => $ability->get_label(),
					'description' => $ability->get_description(),
					'destructive' => self::is_destructive( $ability ),
					'input'       => $input,
					'status'      => 'pending',
					'created'     => time(),
				),
			)
		);

		return array(
			'status' => 'awaiting_confirmation',
			'note'   => 'Nothing has changed yet. The call is shown to the user as an action card; it runs only if the user confirms it. Tell the user briefly what it will do and that they can run or cancel it below. Do not call it again.',
		);
	}

	/**
	 * Runs an ability with the current user's permissions.
	 *
	 * @param string $name  Ability name.
	 * @param mixed  $input Input (arguments).
	 * @return mixed|\WP_Error Result.
	 */
	public static function run( string $name, $input ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;

		if ( ! $ability ) {
			return self::not_found( $name );
		}

		return $ability->execute( null === $input || array() === $input ? null : $input );
	}

	/**
	 * Error for a missing ability.
	 *
	 * @param string $name Ability name.
	 */
	private static function not_found( string $name ): \WP_Error {
		return new \WP_Error( 'ability_not_found', sprintf( 'Ability "%s" not found', $name ) );
	}

	/**
	 * Error response for a WP_Error.
	 *
	 * @param \WP_Error $error Error.
	 * @return array<string, mixed>
	 */
	private static function error( \WP_Error $error ): array {
		return array(
			'error' => $error->get_error_message(),
			'code'  => $error->get_error_code(),
			'data'  => $error->get_error_data(),
		);
	}

	/**
	 * Registered ability, or null.
	 */
	private function ability(): ?WP_Ability {
		return function_exists( 'wp_get_ability' ) ? wp_get_ability( $this->ability ) : null;
	}
}
