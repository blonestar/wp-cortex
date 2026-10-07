<?php
/**
 * Base class for chat tools.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Defaults for the optional parts of a tool: always available, no arguments and no
 * system instruction lines.
 */
abstract class AbstractTool implements Tool {

	/**
	 * Human-readable name; defaults to the function name.
	 */
	public function label(): string {
		return $this->name();
	}

	/**
	 * Whether the tool is offered in this turn.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		return true;
	}

	/**
	 * JSON schema of the arguments.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>|null
	 */
	public function parameters( ToolContext $context ): ?array {
		return null;
	}

	/**
	 * Lines added to the system instruction.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array();
	}

	/**
	 * What else decides whether the tool is offered, shown under Settings > Chat tools
	 * (for example another setting); empty when nothing does.
	 */
	public function availability_note(): string {
		return '';
	}
}
