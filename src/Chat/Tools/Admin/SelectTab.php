<?php
/**
 * Admin chat tool: select_tab.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Admin\AdminPages;
use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Switches to a tab on the current admin screen. Only tabs reported for that screen
 * can be selected.
 */
final class SelectTab extends AdminTool {

	public function name(): string {
		return 'select_tab';
	}

	public function label(): string {
		return __( 'Select tab', 'wp-cortex' );
	}

	/**
	 * Offered when the browser reported tabs on the current screen.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		return (bool) $this->admin( $context )->tabs();
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Switches to a tab on the admin screen the user is currently viewing (for example a tab of an options page). Call it only when the user explicitly asks to open or switch to a tab.';
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
				'tab' => array(
					'type'        => 'string',
					'description' => 'The label of a tab on the current screen.',
					'enum'        => $this->admin( $context )->tabs(),
				),
			),
			'required'   => array( 'tab' ),
		);
	}

	/**
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array( 'To switch to a tab on the screen the user is currently viewing, call select_tab. You cannot click anything other than tabs.' );
	}

	/**
	 * Queues the tab selection.
	 *
	 * After open_admin_page in the same reply, the browser leaves the current screen, so the
	 * tab is appended to the tab path opened on the new screen instead (for example a saved
	 * skill opening a settings tab and then its section).
	 *
	 * @param array       $args    Arguments: tab.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin   = $this->admin( $context );
		$label   = (string) ( $args['tab'] ?? '' );
		$current = $admin->navigation_tab();

		if ( null !== $current ) {
			$label = sanitize_text_field( $label );
			$path  = '' !== $current ? $current . ' › ' . $label : $label;

			if ( '' !== $label && mb_strlen( $path ) <= AdminPages::MAX_TAB_LENGTH ) {
				$admin->set_navigation_tab( $path );

				return array( 'ok' => true );
			}
		}

		if ( ! in_array( $label, $admin->tabs(), true ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Unknown tab. Use one of the listed tab values.', 'wp-cortex' ),
			);
		}

		$admin->add_action(
			array(
				'type'  => 'select_tab',
				'label' => $label,
			)
		);

		return array( 'ok' => true );
	}
}
