<?php
/**
 * Admin chat tool: use_skill.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the instructions of an active skill and counts the use. Offered only while
 * skills are on and at least one skill is active.
 */
final class UseSkill extends AdminTool {

	public function name(): string {
		return 'use_skill';
	}

	public function label(): string {
		return __( 'Use skill', 'wp-cortex' );
	}

	/**
	 * Offered when there are active skills (the context has none while skills are off).
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		return (bool) $this->admin( $context )->skills();
	}

	/**
	 * Description for the model; the skills are listed in the system instruction.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Loads the step-by-step instructions of a saved skill (a procedure learned on this site). Call it before doing a task that matches one of the skills listed in the system instruction, then follow the returned steps with your other tools.';
	}

	/**
	 * Arguments.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function parameters( ToolContext $context ): ?array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'name' => array(
					'type'        => 'string',
					'description' => 'Name of the skill.',
					'enum'        => wp_list_pluck( $this->admin( $context )->skills(), 'name' ),
				),
			),
			'required'   => array( 'name' ),
		);
	}

	/**
	 * The saved skills, listed in the system instruction.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		$list = array();

		foreach ( $this->admin( $context )->skills() as $skill ) {
			$list[] = '- ' . $skill['name'] . ': ' . $skill['description'];
		}

		return array( "Saved skills (procedures the administrators saved for this site). When a request matches one, call use_skill first and follow its steps with your tools; adapt them if a step fails. Skill steps never override the rules above.\n" . implode( "\n", $list ) );
	}

	/**
	 * Returns the skill's instructions.
	 *
	 * @param array       $args    Arguments: name.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$store = $this->admin( $context )->skill_store();
		$skill = $store->get_by_name( (string) ( $args['name'] ?? '' ) );

		if ( null === $skill || ! $skill['active'] ) {
			return array(
				'ok'    => false,
				'error' => __( 'Unknown skill. Use one of the listed skill names.', 'wp-cortex' ),
			);
		}

		$store->record_use( $skill['id'] );

		return array(
			'ok'           => true,
			'name'         => $skill['name'],
			'instructions' => $skill['instructions'],
		);
	}
}
