<?php
/**
 * Admin chat tool: clear_cache.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Clears the WordPress caches: the cache of one post right away; the whole object cache
 * and expired transients, every transient or the rewrite rules only after the user
 * confirms the action card. Page caches of plugins and hosts are not touched.
 */
final class ClearCache extends AdminTool implements ConfirmableTool {

	/**
	 * Extra steps of a site-wide clear.
	 */
	private const EXTRAS = array( 'all_transients', 'rewrite_rules' );

	public function name(): string {
		return 'clear_cache';
	}

	public function label(): string {
		return __( 'Clear cache', 'wp-cortex' );
	}

	/**
	 * What else decides how the tool runs.
	 */
	public function availability_note(): string {
		return __( 'Clearing the cache of one post runs right away; clearing the whole site runs only after you confirm it in the chat.', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Clears the WordPress cache. Scope "post" clears the cached data of one post (object cache entries of the post, its meta and terms) and runs right away. Scope "site" flushes the whole object cache and deletes expired transients; it is shown to the user as an action card and runs only after the user confirms it. With scope "site", "also" can add "all_transients" (delete every transient, also unexpired ones; plugins rebuild them, but it may log out API connections cached in transients) and "rewrite_rules" (regenerate the permalink rules, for example when pages return 404 after a change). This tool does not clear page caches of caching plugins, the host or a CDN.';
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
				'scope'   => array(
					'type'        => 'string',
					'enum'        => array( 'post', 'site' ),
					'description' => '"post" for one post, "site" for the whole site.',
				),
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'Post to clear with scope "post"; defaults to the post the user is editing or viewing.',
				),
				'also'    => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => self::EXTRAS,
					),
					'description' => 'Extra steps with scope "site", only when the user asks for them.',
				),
			),
			'required'   => array( 'scope' ),
		);
	}

	/**
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array( 'Call clear_cache only when the user asks to clear or purge the cache. When the user refers to the current page or post, use scope "post"; when they ask for the whole site or do not say what, use scope "site". Add "all_transients" or "rewrite_rules" only when the user asks for them (rewrite rules: also when permalinks or 404 errors are the problem). A site-wide clear needs the user\'s confirmation on the action card: do not claim it ran before they confirm. clear_cache does not clear page caches of caching plugins, the host or a CDN; say so when the user expects that.' );
	}

	/**
	 * Clears one post right away, or shows a site-wide clear as an action card.
	 *
	 * @param array       $args    Arguments: scope, post_id, also.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin = $this->admin( $context );

		if ( 'site' !== ( $args['scope'] ?? '' ) ) {
			$post_id = (int) ( $args['post_id'] ?? 0 );
			$post_id = $post_id > 0 ? $post_id : $admin->post_id();

			if ( $post_id <= 0 || ! get_post( $post_id ) ) {
				return array(
					'ok'    => false,
					'error' => 'No post given and the user is not on a post. Ask which post, or use scope "site".',
				);
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return array(
					'ok'    => false,
					'error' => 'The current user cannot edit this post.',
				);
			}

			clean_post_cache( $post_id );

			return array(
				'ok'                      => true,
				'post_id'                 => $post_id,
				'cleared'                 => 'post',
				'persistent_object_cache' => (bool) wp_using_ext_object_cache(),
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'ok'    => false,
				'error' => 'The current user cannot clear the site cache.',
			);
		}

		$input = array(
			'scope' => 'site',
			'also'  => array_values( array_intersect( self::EXTRAS, array_map( 'strval', (array) ( $args['also'] ?? array() ) ) ) ),
		);
		$id    = wp_generate_uuid4();

		$admin->set_item(
			'action:' . $id,
			array(
				'role'   => AbilityTool::ACTION_ROLE,
				'action' => array(
					'id'          => $id,
					'tool'        => $this->name(),
					'label'       => __( 'Clear the site cache', 'wp-cortex' ),
					'description' => $this->summary( $input['also'] ),
					'destructive' => false,
					'input'       => $input,
					'status'      => 'pending',
					'created'     => time(),
				),
			)
		);

		return array(
			'status' => 'awaiting_confirmation',
			'note'   => 'Nothing has been cleared yet. The user sees an action card and the cache is cleared only if they confirm it. Tell the user briefly what will be cleared and that they can run or cancel it below. Do not call it again.',
		);
	}

	/**
	 * Runs a confirmed site-wide clear.
	 *
	 * @param array $input Input stored with the action card: scope, also.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function run_confirmed( array $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'wp_cortex_forbidden', __( 'You are not allowed to clear the site cache.', 'wp-cortex' ) );
		}

		$also   = array_intersect( self::EXTRAS, (array) ( $input['also'] ?? array() ) );
		$result = array(
			'object_cache_flushed'    => wp_cache_flush(),
			'persistent_object_cache' => (bool) wp_using_ext_object_cache(),
		);

		if ( in_array( 'all_transients', $also, true ) ) {
			$result['transients_deleted'] = $this->delete_all_transients();
		} else {
			delete_expired_transients( true );
			$result['expired_transients_deleted'] = true;
		}

		if ( in_array( 'rewrite_rules', $also, true ) ) {
			flush_rewrite_rules( false );
			$result['rewrite_rules_flushed'] = true;
		}

		return $result;
	}

	/**
	 * What a site-wide clear does, for the action card.
	 *
	 * @param string[] $also Extra steps.
	 */
	private function summary( array $also ): string {
		$steps = array( __( 'Flush the object cache.', 'wp-cortex' ) );

		$steps[] = in_array( 'all_transients', $also, true )
			? __( 'Delete every transient, also unexpired ones.', 'wp-cortex' )
			: __( 'Delete expired transients.', 'wp-cortex' );

		if ( in_array( 'rewrite_rules', $also, true ) ) {
			$steps[] = __( 'Regenerate the permalink rules.', 'wp-cortex' );
		}

		return implode( ' ', $steps );
	}

	/**
	 * Deletes every transient stored in the options table (transients kept in a persistent
	 * object cache are already gone with the flush).
	 *
	 * @return int Number of deleted rows.
	 */
	private function delete_all_transients(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%'
			)
		);

		wp_cache_delete( 'alloptions', 'options' );

		return (int) $deleted;
	}
}
