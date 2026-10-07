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
 */
final class AbilityTool extends AbstractTool {

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
	 * Input schema of the ability, prepared for the model.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>|null
	 */
	public function parameters( ToolContext $context ): ?array {
		$ability = $this->ability();
		$schema  = $ability ? wp_prepare_json_schema_for_client( $ability->get_input_schema() ) : array();

		return ! empty( $schema ) ? $schema : null;
	}

	/**
	 * System instruction lines for this ability.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return $this->instructions;
	}

	/**
	 * Runs the ability (which checks its own permissions); errors are returned like
	 * WP_AI_Client_Ability_Function_Resolver returns them.
	 *
	 * @param array       $args    Arguments from the model.
	 * @param ToolContext $context Turn context.
	 * @return mixed
	 */
	public function execute( array $args, ToolContext $context ) {
		$ability = $this->ability();

		if ( ! $ability ) {
			return array(
				'error' => sprintf( 'Ability "%s" not found', $this->ability ),
				'code'  => 'ability_not_found',
			);
		}

		$result = $ability->execute( ! empty( $args ) ? $args : null );

		if ( is_wp_error( $result ) ) {
			return array(
				'error' => $result->get_error_message(),
				'code'  => $result->get_error_code(),
				'data'  => $result->get_error_data(),
			);
		}

		return $result;
	}

	/**
	 * Registered ability, or null.
	 */
	private function ability(): ?WP_Ability {
		return function_exists( 'wp_get_ability' ) ? wp_get_ability( $this->ability ) : null;
	}
}
