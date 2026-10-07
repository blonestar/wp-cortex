<?php
/**
 * Chat tool: a function the model can call during a chat turn.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * A tool offered to the admin or the visitor chat agent.
 *
 * Tools are created for one chat turn, so they may keep state of that turn (for
 * example how many reports were added). Everything a tool may read or change about
 * the turn goes through its context: AdminContext for the admin chat, PublicContext
 * for the visitor chat, which only reads the public index.
 */
interface Tool {

	/**
	 * Function name the model calls (letters, digits, "_" and "-", up to 64 characters).
	 */
	public function name(): string;

	/**
	 * Human-readable name, for example in the settings.
	 */
	public function label(): string;

	/**
	 * Whether the tool is offered in this turn.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool;

	/**
	 * Description the model reads to decide when to call the tool.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string;

	/**
	 * JSON schema of the arguments, or null for none.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>|null
	 */
	public function parameters( ToolContext $context ): ?array;

	/**
	 * Lines added to the system instruction while the tool is offered.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array;

	/**
	 * Runs the tool.
	 *
	 * @param array       $args    Arguments from the model (not validated against the schema).
	 * @param ToolContext $context Turn context.
	 * @return mixed Response for the model, usually an array; return array( 'error' => '...' ) on failure.
	 */
	public function execute( array $args, ToolContext $context );
}
