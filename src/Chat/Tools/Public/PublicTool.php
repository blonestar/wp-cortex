<?php
/**
 * Base class for the built-in visitor chat tools.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Public;

use WPCortex\Chat\Tools\AbstractTool;
use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Visitor tools need the visitor turn context, which only reads the public index.
 */
abstract class PublicTool extends AbstractTool {

	/**
	 * The context as a PublicContext.
	 *
	 * @param ToolContext $context Turn context.
	 * @throws \InvalidArgumentException When the tool is used outside the visitor chat.
	 */
	protected function visitor( ToolContext $context ): PublicContext {
		if ( ! $context instanceof PublicContext ) {
			throw new \InvalidArgumentException( 'Visitor chat tools need a PublicContext.' );
		}

		return $context;
	}
}
