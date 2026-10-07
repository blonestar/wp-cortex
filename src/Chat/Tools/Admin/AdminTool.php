<?php
/**
 * Base class for the built-in admin chat tools.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\AbstractTool;
use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Admin tools need the admin turn context.
 */
abstract class AdminTool extends AbstractTool {

	/**
	 * The context as an AdminContext.
	 *
	 * @param ToolContext $context Turn context.
	 * @throws \InvalidArgumentException When the tool is used outside the admin chat.
	 */
	protected function admin( ToolContext $context ): AdminContext {
		if ( ! $context instanceof AdminContext ) {
			throw new \InvalidArgumentException( 'Admin chat tools need an AdminContext.' );
		}

		return $context;
	}
}
