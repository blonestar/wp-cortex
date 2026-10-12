<?php
/**
 * Interface of built-in admin chat tools that may run only after the user confirms.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * A built-in admin tool that shows some of its calls as an action card (transcript item
 * AbilityTool::ACTION_ROLE with a "tool" key instead of "ability") and runs them only
 * after the user confirms the card (ChatAgent::resolve_action()), with the input stored
 * in the card.
 */
interface ConfirmableTool {

	/**
	 * Runs a confirmed call.
	 *
	 * @param array $input Input stored with the action card.
	 * @return mixed|\WP_Error Result for the model.
	 */
	public function run_confirmed( array $input );
}
