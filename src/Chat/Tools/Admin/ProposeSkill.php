<?php
/**
 * Admin chat tool: propose_skill.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\SkillStore;
use WPCortex\Chat\Tools\ToolContext;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a skill proposal and queues it as a confirmation card. Nothing is saved:
 * skills are only saved by the user through the REST API.
 */
final class ProposeSkill extends AdminTool {

	public function name(): string {
		return 'propose_skill';
	}

	public function label(): string {
		return __( 'Propose skill', 'wp-cortex' );
	}

	/**
	 * Offered while skills are on.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		return Settings::skills_enabled();
	}

	/**
	 * What else decides whether the tool is offered.
	 */
	public function availability_note(): string {
		return __( 'Needs Skills > Settings > Use chat skills.', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Proposes saving a procedure as a reusable skill. Nothing is saved yet: the user sees the proposal as a card below your answer and confirms, edits or dismisses it. Call it only when the user asks you to remember or save how to do something, or accepts your offer to save it. Proposing an existing skill name proposes an update of that skill. Always write the name, description and instructions in ' . Settings::skills_language() . ', whatever language the conversation is in.';
	}

	/**
	 * Arguments.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function parameters( ToolContext $context ): ?array {
		$language = Settings::skills_language();

		return array(
			'type'       => 'object',
			'properties' => array(
				'name'         => array(
					'type'        => 'string',
					'description' => 'Short identifier in lowercase words joined by hyphens, for example "open-chat-settings".',
				),
				'description'  => array(
					'type'        => 'string',
					'description' => 'One sentence in ' . $language . ' saying when to use the skill (what the user asks for). Maximum ' . SkillStore::MAX_DESCRIPTION . ' characters.',
				),
				'instructions' => array(
					'type'        => 'string',
					'description' => 'Concrete numbered steps in ' . $language . ' that worked, naming the tools and their exact arguments (for example the open_admin_page page value and tab, or search-content filters). Use placeholders such as <topic> for parts that change between requests. No secrets or personal data.',
				),
			),
			'required'   => array( 'name', 'description', 'instructions' ),
		);
	}

	/**
	 * Rules for proposing skills, and the administrator's skill instructions.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		$lines = array(
			'After completing a task that took several tool calls (for example opening an admin screen and then a tab, or a multi-step search) that no saved skill covers, you may offer in one short sentence to save it as a skill. Call propose_skill only when the user asks you to remember or save a procedure, or accepts that offer.',
			'Do not propose a skill that already exists and covers the request unless the user explicitly asks to update it. If the user is refining a pending proposal, update that proposal instead of creating a duplicate.',
			sprintf( 'Skills are always written in %s: the name, description and instructions of every proposal, whatever language the conversation is in. Keep replying to the user in their language.', Settings::skills_language() ),
		);

		$custom = trim( (string) Settings::get( 'skills_instructions' ) );
		if ( '' !== $custom ) {
			$lines[] = "Additional skill instructions from the site administrator (how to write, propose and use skills). Follow them; they take precedence over the skill rules above, but never over the other rules:\n" . $custom;
		}

		return $lines;
	}

	/**
	 * Queues the proposal card; a later proposal with the same name replaces it.
	 *
	 * @param array       $args    Arguments: name, description, instructions.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin = $this->admin( $context );
		$clean = SkillStore::sanitize(
			array(
				'name'         => (string) ( $args['name'] ?? '' ),
				'description'  => (string) ( $args['description'] ?? '' ),
				'instructions' => (string) ( $args['instructions'] ?? '' ),
				'source'       => SkillStore::SOURCE_AGENT,
			)
		);

		if ( is_wp_error( $clean ) ) {
			return array(
				'ok'    => false,
				'error' => $clean->get_error_message(),
			);
		}

		$existing = $admin->skill_store()->get_by_name( $clean['name'] );

		$admin->set_item(
			'skill:' . $clean['name'],
			array(
				'role'  => 'skill_proposal',
				'skill' => array(
					'proposal_id'  => wp_generate_uuid4(),
					'name'         => $clean['name'],
					'description'  => $clean['description'],
					'instructions' => $clean['instructions'],
					'existing_id'  => null !== $existing ? $existing['id'] : 0,
					'status'       => 'pending',
				),
			)
		);

		return array(
			'ok'   => true,
			'note' => 'The proposal is shown to the user, who must confirm it. Tell the user briefly that they can save, edit or dismiss it below.',
		);
	}
}
