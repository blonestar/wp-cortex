<?php
/**
 * Admin chat tool: open_post.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Opens a post in the editor or on the site. URLs are always built server-side, and only
 * posts returned by tools (or the post being edited) can be opened.
 */
final class OpenPost extends AdminTool {

	public function name(): string {
		return 'open_post';
	}

	public function label(): string {
		return __( 'Open post', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Opens a post in the admin: navigates the user to the post editor (target "edit", default) or to its public page (target "view"). Call it only when the user explicitly asks to open, go to, edit or show a specific post.';
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
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'ID of the post to open, taken from search results.',
				),
				'target'  => array(
					'type'    => 'string',
					'enum'    => array( 'edit', 'view' ),
					'default' => 'edit',
				),
			),
			'required'   => array( 'post_id' ),
		);
	}

	/**
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array( 'Call open_post ONLY when the user explicitly asks to open, go to, edit or show a specific post (including references such as "this one" or "open the first" to earlier results). Otherwise just list the results.' );
	}

	/**
	 * Queues navigation to the post.
	 *
	 * @param array       $args    Arguments: post_id, target.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin   = $this->admin( $context );
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$target  = 'view' === ( $args['target'] ?? 'edit' ) ? 'view' : 'edit';
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		$url     = '';

		if ( ! $admin->is_known_post( $post_id ) ) {
			$error = __( 'Unknown post ID. Only open posts returned by search-content or get-document; search first.', 'wp-cortex' );
		} elseif ( ! $post ) {
			$error = __( 'Post not found.', 'wp-cortex' );
		} elseif ( 'view' === $target ) {
			$error = '';

			if ( ! is_post_publicly_viewable( $post ) && ! current_user_can( 'edit_post', $post_id ) ) {
				$error = __( 'This post cannot be viewed.', 'wp-cortex' );
			} else {
				$url = (string) get_permalink( $post );
			}
		} elseif ( ! current_user_can( 'edit_post', $post_id ) ) {
			$error = __( 'You are not allowed to edit this post.', 'wp-cortex' );
		} else {
			$error = '';
			$url   = (string) get_edit_post_link( $post_id, 'raw' );
		}

		if ( '' !== $error || '' === $url ) {
			return array(
				'ok'    => false,
				'error' => '' !== $error ? $error : __( 'No URL available for this post.', 'wp-cortex' ),
			);
		}

		$admin->navigate(
			array(
				'url'     => $url,
				'post_id' => $post_id,
				'title'   => get_the_title( $post ),
			)
		);

		return array( 'ok' => true );
	}
}
