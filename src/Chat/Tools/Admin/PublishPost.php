<?php
/**
 * Admin chat tool: publish_post.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Publishes the post open in the block editor, or schedules it for a date. It always
 * shows an action card: the browser publishes the post through the editor only after
 * the user confirms it.
 */
final class PublishPost extends AdminTool implements ConfirmableTool {

	/**
	 * Date format of the editor: site time without a time zone.
	 */
	private const EDITOR_DATE = 'Y-m-d\TH:i:s';

	public function name(): string {
		return 'publish_post';
	}

	public function label(): string {
		return __( 'Publish or schedule post', 'wp-cortex' );
	}

	/**
	 * Offered while the block editor is open on a post the user can publish.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		$post = EditorSave::post( $this->admin( $context ) );

		return null !== $post && current_user_can( 'publish_post', $post->ID );
	}

	/**
	 * What else decides whether and how the tool runs.
	 */
	public function availability_note(): string {
		return __( 'Only while the block editor of a post you can publish is open. The post is published or scheduled only after you confirm it in the chat.', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return sprintf(
			'Publishes the post open in the editor, with everything changed there, or schedules it when "date" is given. It never runs right away: the user sees an action card and the post is published or scheduled only after they confirm it. Not for a post that is already published (use save_post to update it). The site time is now %1$s (%2$s).',
			current_datetime()->format( 'Y-m-d H:i' ),
			wp_timezone_string()
		) . EditorSave::state_line( $this->admin( $context ) );
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
				'date' => array(
					'type'        => 'string',
					'description' => 'Only to schedule: when the post is published, in site time, as "YYYY-MM-DD HH:MM". It must be in the future. Omit it to publish now.',
				),
			),
		);
	}

	/**
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array( 'Call publish_post only when the user asks to publish or schedule the post open in the editor. Pass "date" only when the user names a date or time; when they give a time without a date or the date is unclear, ask first. It needs the user\'s confirmation on the action card: do not claim the post was published or scheduled.' );
	}

	/**
	 * Shows publishing or scheduling as an action card.
	 *
	 * @param array       $args    Arguments: date.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin = $this->admin( $context );
		$post  = EditorSave::post( $admin );

		if ( null === $post ) {
			return EditorSave::error( __( 'No post is open in the editor.', 'wp-cortex' ) );
		}

		if ( ! current_user_can( 'publish_post', $post->ID ) ) {
			return EditorSave::error( __( 'The current user cannot publish this post. It can be submitted for review with set_post_status.', 'wp-cortex' ) );
		}

		if ( EditorSave::is_live( $post->post_status ) ) {
			return EditorSave::error( __( 'The post is already published. Use save_post to put its changes online.', 'wp-cortex' ) );
		}

		$input = array( 'post_id' => $post->ID );
		$title = EditorSave::title( $post );
		$raw   = trim( (string) ( $args['date'] ?? '' ) );

		if ( '' === $raw ) {
			return EditorSave::card(
				$admin,
				$this->name(),
				__( 'Publish the post', 'wp-cortex' ),
				/* translators: %s: post title */
				sprintf( __( '“%s” is published with everything changed in the editor and becomes visible on the site.', 'wp-cortex' ), $title ),
				$input
			);
		}

		$date = self::parse_date( $raw );

		if ( is_string( $date ) ) {
			return EditorSave::error( $date );
		}

		$input['date'] = $date->format( self::EDITOR_DATE );

		return EditorSave::card(
			$admin,
			$this->name(),
			__( 'Schedule the post', 'wp-cortex' ),
			sprintf(
				/* translators: 1: post title, 2: date and time */
				__( '“%1$s” is saved with everything changed in the editor and published automatically on %2$s (site time).', 'wp-cortex' ),
				$title,
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $date->getTimestamp() )
			),
			$input
		);
	}

	/**
	 * Sends a confirmed publish or schedule to the editor.
	 *
	 * @param array $input Input stored with the action card: post_id, date.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function run_confirmed( array $input ) {
		$action = array(
			'mode'   => 'publish',
			'status' => 'publish',
		);

		if ( isset( $input['date'] ) ) {
			$date = self::parse_date( (string) $input['date'] );

			if ( is_string( $date ) ) {
				return new \WP_Error( 'wp_cortex_invalid_date', $date );
			}

			$action['status'] = 'future';
			$action['date']   = $date->format( self::EDITOR_DATE );
		}

		return EditorSave::confirmed( $input, 'publish_post', $action );
	}

	/**
	 * Reads a date in site time that must be in the future.
	 *
	 * @param string $raw Date and time.
	 * @return \DateTimeImmutable|string The date, or an error message.
	 */
	private static function parse_date( string $raw ) {
		$date = preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $raw ) ? date_create_immutable( $raw, wp_timezone() ) : false;

		if ( ! $date ) {
			return __( 'The date must be given as "YYYY-MM-DD HH:MM" in site time.', 'wp-cortex' );
		}

		if ( $date->getTimestamp() <= time() ) {
			return __( 'The date is not in the future. Omit the date to publish now, or ask the user for a later date.', 'wp-cortex' );
		}

		return $date;
	}
}
