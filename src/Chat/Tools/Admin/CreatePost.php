<?php
/**
 * Admin chat tool: create_post.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a new draft of any post type the user can create (posts, pages, custom post
 * types) and opens it in the editor. It writes to the database, so a call is only shown
 * as an action card and the draft is created after the user confirms it. It never
 * publishes: the draft is filled with the editor tools and saved or published by the user.
 */
final class CreatePost extends AdminTool implements ConfirmableTool {

	/**
	 * Longest title and excerpt, most characters of content.
	 */
	private const MAX_TITLE_LENGTH   = 200;
	private const MAX_EXCERPT_LENGTH = 1000;
	private const MAX_CONTENT_LENGTH = 20000;

	public function name(): string {
		return 'create_post';
	}

	public function label(): string {
		return __( 'Create post', 'wp-cortex' );
	}

	/**
	 * Offered when the user can create posts of at least one post type.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		return array() !== self::post_types();
	}

	/**
	 * What else decides how the tool runs.
	 */
	public function availability_note(): string {
		return __( 'Only post types you can create. The draft is created only after you confirm it in the chat, and is never published by this tool.', 'wp-cortex' );
	}

	/**
	 * Description for the model, listing the post types.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		$lines = array();

		foreach ( self::post_types() as $name => $type ) {
			$lines[] = sprintf( '- %1$s: "%2$s" (plural "%3$s"), %4$s editor', $name, $type['singular'], $type['plural'], $type['block_editor'] ? 'block' : 'classic' );
		}

		return "Creates a new draft of a post type (post, page or a custom post type) and opens it in the editor. It is shown to the user as an action card and runs only after the user confirms it. Post types the user can create:\n" . implode( "\n", $lines );
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
				'post_type' => array(
					'type'        => 'string',
					'enum'        => array_keys( self::post_types() ),
					'description' => 'Post type of the new post, matched to what the user asked for by its name or labels.',
				),
				'title'     => array(
					'type'        => 'string',
					'description' => 'Title of the new post.',
				),
				'content'   => array(
					'type'        => 'string',
					'description' => 'Optional. Text of the post: paragraphs separated by a blank line, inline HTML only (strong, em, a, code). Only text the user gave or asked you to write.',
				),
				'excerpt'   => array(
					'type'        => 'string',
					'description' => 'Optional. Excerpt of the post, plain text.',
				),
			),
			'required'   => array( 'post_type', 'title' ),
		);
	}

	/**
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array( 'When the user asks to create a new post, page or item of a custom post type (for example "create a new testimonial"), call create_post once with the post type whose name or labels match the request, in any language; when several post types could match or none does, ask which one. It only creates a draft after the user confirms an action card, then opens it in the editor; it never publishes. Do not use it to change an existing post. Fields, terms, blocks and the featured image are filled with the editor tools once the draft is open, from the user\'s next message on.' );
	}

	/**
	 * Shows the new draft as an action card.
	 *
	 * @param array       $args    Arguments: post_type, title, content, excerpt.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin     = $this->admin( $context );
		$types     = self::post_types();
		$post_type = (string) ( $args['post_type'] ?? '' );

		if ( ! isset( $types[ $post_type ] ) ) {
			return $this->error( 'Unknown post type, or the user cannot create posts of it.' );
		}

		$title = trim( sanitize_text_field( (string) ( $args['title'] ?? '' ) ) );

		if ( '' === $title || mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			return $this->error( sprintf( 'Pass a title of at most %d characters.', self::MAX_TITLE_LENGTH ) );
		}

		$content = is_scalar( $args['content'] ?? null ) ? trim( (string) $args['content'] ) : '';
		$excerpt = is_scalar( $args['excerpt'] ?? null ) ? trim( sanitize_textarea_field( (string) $args['excerpt'] ) ) : '';

		if ( mb_strlen( $content ) > self::MAX_CONTENT_LENGTH || mb_strlen( $excerpt ) > self::MAX_EXCERPT_LENGTH ) {
			return $this->error( sprintf( 'The content may have at most %1$d characters and the excerpt %2$d. Create the draft with less and add the rest in the editor.', self::MAX_CONTENT_LENGTH, self::MAX_EXCERPT_LENGTH ) );
		}

		// Opening the draft leaves the current page.
		$unsaved = $admin->has_unsaved_changes();

		foreach ( $admin->actions() as $action ) {
			if ( in_array( $action['type'] ?? '', ReloadPage::EDIT_ACTIONS, true ) ) {
				$unsaved = true;
			}
		}

		if ( $unsaved ) {
			return $this->error( 'The current page has unsaved changes, and opening the new draft would leave it. Ask the user to save them first.' );
		}

		$type        = $types[ $post_type ];
		$paragraphs  = self::paragraphs( $type['supports_editor'] ? $content : '' );
		$excerpt     = $type['supports_excerpt'] ? $excerpt : '';
		$id          = wp_generate_uuid4();
		$description = sprintf(
			/* translators: %s: post title */
			__( 'Title: “%s”.', 'wp-cortex' ),
			$title
		);

		if ( $paragraphs ) {
			/* translators: %d: number of paragraphs */
			$description .= ' ' . sprintf( _n( 'Content: %d paragraph.', 'Content: %d paragraphs.', count( $paragraphs ), 'wp-cortex' ), count( $paragraphs ) );
		}

		if ( '' !== $excerpt ) {
			$description .= ' ' . __( 'With an excerpt.', 'wp-cortex' );
		}

		$description .= ' ' . __( 'Running this creates a draft (not published) and opens it in the editor.', 'wp-cortex' );

		$admin->set_item(
			'action:' . $id,
			array(
				'role'   => AbilityTool::ACTION_ROLE,
				'action' => array(
					'id'          => $id,
					'tool'        => $this->name(),
					/* translators: %s: singular post type name, for example "Testimonial" */
					'label'       => sprintf( __( 'Create a new %s draft', 'wp-cortex' ), $type['singular'] ),
					'description' => $description,
					'destructive' => false,
					'input'       => array(
						'post_type'  => $post_type,
						'title'      => $title,
						'paragraphs' => $paragraphs,
						'excerpt'    => $excerpt,
					),
					'status'      => 'pending',
					'created'     => time(),
				),
			)
		);

		$response = array(
			'status' => 'awaiting_confirmation',
			'note'   => 'Nothing has been created yet. The user sees an action card; the draft is created and opened in the editor only if they confirm it. Tell the user briefly what will be created and that they can run or cancel it below. Do not call it again, and do not call open_post or open_admin_page in this reply.',
		);

		if ( '' !== $content && ! $type['supports_editor'] ) {
			$response['note'] .= ' This post type has no content editor, so the content was left out.';
		}

		return $response;
	}

	/**
	 * Creates the confirmed draft and opens it in the editor.
	 *
	 * @param array $input Input stored with the action card: post_type, title, paragraphs, excerpt.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function run_confirmed( array $input ) {
		$types     = self::post_types();
		$post_type = (string) ( $input['post_type'] ?? '' );
		$title     = trim( sanitize_text_field( (string) ( $input['title'] ?? '' ) ) );

		if ( ! isset( $types[ $post_type ] ) ) {
			return new \WP_Error( 'wp_cortex_forbidden', __( 'You are not allowed to create posts of this type.', 'wp-cortex' ) );
		}

		$paragraphs = array_values( array_filter( array_map( array( EditorState::class, 'inline_html' ), array_map( 'strval', (array) ( $input['paragraphs'] ?? array() ) ) ) ) );

		if ( $types[ $post_type ]['block_editor'] ) {
			$content = implode(
				"\n\n",
				array_map(
					static function ( string $html ): string {
						return "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->";
					},
					$paragraphs
				)
			);
		} else {
			$content = implode( "\n\n", $paragraphs );
		}

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $post_type,
					'post_status'  => 'draft',
					'post_author'  => get_current_user_id(),
					'post_title'   => $title,
					'post_content' => $content,
					'post_excerpt' => sanitize_textarea_field( (string) ( $input['excerpt'] ?? '' ) ),
				)
			),
			true
		);

		if ( is_wp_error( $post_id ) || $post_id < 1 ) {
			return new \WP_Error( 'wp_cortex_post_failed', __( 'The draft could not be created.', 'wp-cortex' ) );
		}

		$url = (string) get_edit_post_link( $post_id, 'raw' );

		$result = array(
			'id'        => $post_id,
			'title'     => get_the_title( $post_id ),
			'post_type' => $post_type,
			'status'    => 'draft',
			'note'      => '' !== $url
				? 'The draft exists now and opens in the editor right after your reply. Tell the user it is open and that they can ask you to fill in its fields, terms, blocks or featured image there. The editor tools are available from their next message on.'
				: 'The draft exists now, but this post type has no editor screen to open.',
		);

		if ( '' !== $url ) {
			$result['ui_action'] = array(
				'type'    => 'navigate',
				'url'     => $url,
				'title'   => $result['title'],
				'post_id' => $post_id,
			);
		}

		return $result;
	}

	/**
	 * Post types the user can create, with an admin screen in the menu (so internal post
	 * types of WordPress and plugins are left out).
	 *
	 * @return array<string, array{singular: string, plural: string, block_editor: bool, supports_editor: bool, supports_excerpt: bool}> Keyed by post type name.
	 */
	private static function post_types(): array {
		$types = array();

		// Not loaded on REST requests.
		if ( ! function_exists( 'use_block_editor_for_post_type' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $name => $type ) {
			if ( 'attachment' === $name || empty( $type->show_in_menu ) || ! current_user_can( $type->cap->create_posts ) ) {
				continue;
			}

			$types[ $name ] = array(
				'singular'         => (string) $type->labels->singular_name,
				'plural'           => (string) $type->labels->name,
				'block_editor'     => use_block_editor_for_post_type( $name ),
				'supports_editor'  => post_type_supports( $name, 'editor' ),
				'supports_excerpt' => post_type_supports( $name, 'excerpt' ),
			);
		}

		return $types;
	}

	/**
	 * Paragraphs of the content: split at blank lines, inline HTML only.
	 *
	 * @param string $content Content from the model.
	 * @return string[]
	 */
	private static function paragraphs( string $content ): array {
		$paragraphs = array();

		foreach ( preg_split( '/\R\s*\R/', $content ) ?: array() as $paragraph ) {
			$html = EditorState::inline_html( (string) preg_replace( '/\s*\R\s*/', ' ', $paragraph ) );

			if ( '' !== $html ) {
				$paragraphs[] = $html;
			}
		}

		return $paragraphs;
	}

	/**
	 * Error response.
	 *
	 * @param string $message Message.
	 * @return array{ok: false, error: string}
	 */
	private function error( string $message ): array {
		return array(
			'ok'    => false,
			'error' => $message,
		);
	}
}
