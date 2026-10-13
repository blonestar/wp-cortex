<?php
/**
 * Admin chat tool: edit_post.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Changes the title, excerpt, slug, terms and settings (template, parent, order, author,
 * featured image) of the post open in the block editor. Nothing is written to the
 * database: the browser applies the change in the editor and the user saves the post (or
 * undoes the change) as usual. Only existing terms can be assigned (new ones are made by
 * create_terms) and only images that are already in the Media Library.
 */
final class EditPost extends AdminTool {

	/**
	 * Existing terms per taxonomy listed in the system prompt, the most used first.
	 */
	private const LISTED_TERMS = 40;

	/**
	 * Users looked through for an author, and images listed when several match.
	 */
	private const LISTED_AUTHORS = 200;
	private const LISTED_IMAGES  = 8;

	/**
	 * Name of the default template for the model (the editor stores it as an empty string).
	 */
	private const DEFAULT_TEMPLATE = 'default';

	public function name(): string {
		return 'edit_post';
	}

	public function label(): string {
		return __( 'Edit post in the editor', 'wp-cortex' );
	}

	/**
	 * Offered while the block editor of a post the user can edit is open.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		return null !== $this->admin( $context )->editor();
	}

	/**
	 * What else decides whether the tool is offered.
	 */
	public function availability_note(): string {
		return __( 'Only while the block editor of a post is open.', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Changes the title, excerpt, slug, terms (categories, tags, ...) or settings (template, parent, order, author, featured image; those the post type has) of the post open in the editor. Pass only the fields to change. The change is applied in the editor only and is not saved: the user reviews it and saves the post. Call it only when the user asks for the change or accepts a value you suggested.';
	}

	/**
	 * Arguments: the fields the post type has.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function parameters( ToolContext $context ): ?array {
		$editor     = $this->admin( $context )->editor();
		$properties = array(
			'title' => array(
				'type'        => 'string',
				'description' => 'The new post title, as plain text.',
			),
			'slug'  => array(
				'type'        => 'string',
				'description' => 'The new URL slug (lowercase words separated by hyphens).',
			),
		);

		if ( null === $editor || null !== $editor['excerpt'] ) {
			$properties['excerpt'] = array(
				'type'        => 'string',
				'description' => 'The new excerpt, as plain text. An empty string removes it.',
			);
		}

		if ( isset( $editor['template'] ) ) {
			$properties['template'] = array(
				'type'        => 'string',
				'enum'        => array_merge( array( self::DEFAULT_TEMPLATE ), array_keys( $this->templates( $context ) ) ),
				'description' => 'The template of the post, from the list of templates.',
			);
		}

		if ( isset( $editor['parent'] ) ) {
			$properties['parent'] = array(
				'type'        => 'integer',
				'description' => 'ID of the new parent, a post of the same type (find it with search-content); 0 for no parent.',
			);
		}

		if ( isset( $editor['menu_order'] ) ) {
			$properties['menu_order'] = array(
				'type'        => 'integer',
				'description' => 'The order of the post among its siblings (lower comes first).',
			);
		}

		if ( isset( $editor['author'] ) ) {
			$properties['author'] = array(
				'type'        => 'string',
				'description' => 'The new author: display name or username of an existing user who can write posts.',
			);
		}

		if ( isset( $editor['image'] ) ) {
			$properties['featured_image'] = array(
				'type'        => 'string',
				'description' => 'The new featured image: the ID of an image in the Media Library, or words from its title or file name (when several images match, the response lists them). "0" removes the featured image.',
			);
		}

		$taxonomies = $this->taxonomies( $context );

		if ( $taxonomies ) {
			$terms = array();

			foreach ( $taxonomies as $taxonomy => $info ) {
				$terms[ $taxonomy ] = array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => sprintf( 'Names of existing %s; the complete new list (it replaces the current terms, an empty list removes them).', $info['label'] ),
				);
			}

			$properties['terms'] = array(
				'type'        => 'object',
				'description' => 'New terms per taxonomy. Only include the taxonomies to change.',
				'properties'  => $terms,
			);
		}

		return array(
			'type'       => 'object',
			'properties' => $properties,
		);
	}

	/**
	 * System instruction lines: the current values in the editor and the existing terms.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		$editor = (array) $this->admin( $context )->editor();
		$lines  = array(
			'The post editor is open. Values currently in the editor (possibly not saved yet); use them, not the indexed or saved values, when the user refers to the current post:',
			sprintf( '- Title: «%s»', (string) ( $editor['title'] ?? '' ) ),
			sprintf( '- Slug: %s', '' !== (string) ( $editor['slug'] ?? '' ) ? $editor['slug'] : '(generated from the title)' ),
		);

		if ( isset( $editor['excerpt'] ) ) {
			$lines[] = sprintf( '- Excerpt: %s', '' !== $editor['excerpt'] ? '«' . $editor['excerpt'] . '»' : '(none)' );
		}

		$post = get_post( $this->admin( $context )->post_id() );

		if ( isset( $editor['template'] ) ) {
			$templates = array( self::DEFAULT_TEMPLATE . ' (Default template)' );

			foreach ( $this->templates( $context ) as $slug => $name ) {
				$templates[] = $slug === $name ? $slug : $slug . ' (' . $name . ')';
			}

			$lines[] = sprintf( '- Template: %1$s. Templates: %2$s', '' !== $editor['template'] ? $editor['template'] : self::DEFAULT_TEMPLATE, implode( ', ', $templates ) );
		}

		if ( isset( $editor['parent'] ) ) {
			$lines[] = sprintf( '- Parent: %s', $editor['parent'] > 0 ? '«' . get_the_title( $editor['parent'] ) . '» (#' . $editor['parent'] . ')' : '(none)' );
		}

		if ( isset( $editor['menu_order'] ) ) {
			$lines[] = sprintf( '- Order: %d', $editor['menu_order'] );
		}

		if ( isset( $editor['author'] ) ) {
			$author  = get_userdata( $editor['author'] );
			$lines[] = sprintf( '- Author: %s', $author ? $author->display_name : '(unknown)' );
		}

		if ( isset( $editor['image'] ) ) {
			$lines[] = sprintf( '- Featured image: %s', $editor['image'] > 0 && $post ? '«' . get_the_title( $editor['image'] ) . '» (#' . $editor['image'] . ')' : '(none)' );
		}

		foreach ( $this->taxonomies( $context ) as $taxonomy => $info ) {
			$current = $this->term_names( (array) ( $editor['terms'][ $taxonomy ] ?? array() ), $taxonomy );
			$common  = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => self::LISTED_TERMS,
					'fields'     => 'names',
				)
			);

			$lines[] = sprintf(
				'- %1$s (%2$s): %3$s. Existing terms, the most used first: %4$s',
				$info['label'],
				$taxonomy,
				$current ? implode( ', ', $current ) : '(none)',
				is_array( $common ) && $common ? implode( ', ', array_map( array( $this, 'decode' ), $common ) ) : '(none)'
			);
		}

		$lines[] = 'To change these fields, call edit_post. When the user asks for suggestions (for example titles or an excerpt), only suggest them and wait: call edit_post only after the user picks or accepts one. edit_post assigns existing terms only; never invent term names. Editor tools (edit_post and the other editor tools) need no confirmation card: they change the editor right away but do not save the post; after calling them, tell the user to review the changes and save the post (do not save it yourself unless the user asked for that).';

		return $lines;
	}

	/**
	 * Queues the changes for the editor.
	 *
	 * @param array       $args    Arguments: title, excerpt, slug, terms, template, parent, menu_order, author, featured_image.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin   = $this->admin( $context );
		$editor  = (array) $admin->editor();
		$changes = array();
		$labels  = array();

		if ( array_key_exists( 'title', $args ) ) {
			$title = trim( sanitize_text_field( (string) $args['title'] ) );

			if ( '' === $title || mb_strlen( $title ) > EditorState::MAX_TITLE_LENGTH ) {
				/* translators: %d: maximum number of characters */
				return $this->error( sprintf( __( 'The title must be plain text of 1 to %d characters.', 'wp-cortex' ), EditorState::MAX_TITLE_LENGTH ) );
			}

			$changes['title'] = $title;
			$labels[]         = __( 'title', 'wp-cortex' );
		}

		if ( array_key_exists( 'excerpt', $args ) && null !== ( $editor['excerpt'] ?? null ) ) {
			$excerpt = trim( sanitize_textarea_field( (string) $args['excerpt'] ) );

			if ( mb_strlen( $excerpt ) > EditorState::MAX_EXCERPT_LENGTH ) {
				/* translators: %d: maximum number of characters */
				return $this->error( sprintf( __( 'The excerpt may have at most %d characters.', 'wp-cortex' ), EditorState::MAX_EXCERPT_LENGTH ) );
			}

			$changes['excerpt'] = $excerpt;
			$labels[]           = __( 'excerpt', 'wp-cortex' );
		}

		if ( array_key_exists( 'slug', $args ) ) {
			$slug = sanitize_title( (string) $args['slug'] );

			if ( '' === $slug || mb_strlen( $slug ) > EditorState::MAX_SLUG_LENGTH ) {
				/* translators: %d: maximum number of characters */
				return $this->error( sprintf( __( 'The slug must have 1 to %d characters.', 'wp-cortex' ), EditorState::MAX_SLUG_LENGTH ) );
			}

			$changes['slug'] = $slug;
			$labels[]        = __( 'slug', 'wp-cortex' );
		}

		$post = get_post( $admin->post_id() );

		if ( array_key_exists( 'template', $args ) && isset( $editor['template'] ) ) {
			$template = (string) $args['template'];

			if ( self::DEFAULT_TEMPLATE !== $template && ! isset( $this->templates( $context )[ $template ] ) ) {
				return $this->error( __( 'Unknown template. Use one of the listed templates.', 'wp-cortex' ) );
			}

			$changes['template'] = self::DEFAULT_TEMPLATE === $template ? '' : $template;
			$labels[]            = __( 'template', 'wp-cortex' );
		}

		if ( array_key_exists( 'parent', $args ) && isset( $editor['parent'] ) ) {
			$parent = absint( $args['parent'] );
			$found  = $parent > 0 ? get_post( $parent ) : null;

			if ( $parent > 0 && ( ! $found || ! $post || $found->post_type !== $post->post_type || 'trash' === $found->post_status || $parent === $post->ID || in_array( $post->ID, get_post_ancestors( $found ), true ) ) ) {
				return $this->error( __( 'The parent must be another post of the same type that is not below this post.', 'wp-cortex' ) );
			}

			$changes['parent'] = $parent;
			$labels[]          = __( 'parent', 'wp-cortex' );
		}

		if ( array_key_exists( 'menu_order', $args ) && isset( $editor['menu_order'] ) ) {
			$changes['menu_order'] = (int) $args['menu_order'];
			$labels[]              = __( 'order', 'wp-cortex' );
		}

		if ( array_key_exists( 'author', $args ) && isset( $editor['author'] ) && $post ) {
			$author = $this->find_author( (string) $args['author'], $post->post_type );

			if ( is_string( $author ) ) {
				return $this->error( $author );
			}

			$changes['author'] = $author;
			$labels[]          = __( 'author', 'wp-cortex' );
		}

		if ( array_key_exists( 'featured_image', $args ) && isset( $editor['image'] ) ) {
			$image = $this->find_image( (string) $args['featured_image'] );

			if ( is_string( $image ) ) {
				return $this->error( $image );
			}

			$changes['featured_media'] = $image;
			$labels[]                  = __( 'featured image', 'wp-cortex' );
		}

		$taxonomies = $this->taxonomies( $context );
		$missing    = array();

		foreach ( (array) ( $args['terms'] ?? array() ) as $taxonomy => $names ) {
			if ( ! isset( $taxonomies[ $taxonomy ] ) || ! is_array( $names ) ) {
				/* translators: %s: taxonomy name */
				return $this->error( sprintf( __( 'Unknown taxonomy: %s.', 'wp-cortex' ), (string) $taxonomy ) );
			}

			$ids = array();

			foreach ( array_slice( $names, 0, EditorState::MAX_TERMS ) as $name ) {
				$term = $this->find_term( (string) $name, $taxonomy );

				if ( null === $term ) {
					$missing[] = sanitize_text_field( (string) $name );
				} else {
					$ids[] = $term;
				}
			}

			$changes['terms'][ $taxonomies[ $taxonomy ]['rest_base'] ] = array_values( array_unique( $ids ) );
			$labels[] = $taxonomies[ $taxonomy ]['label'];
		}

		if ( $missing ) {
			return $this->error(
				sprintf(
					/* translators: %s: comma-separated term names */
					__( 'These terms do not exist: %s. edit_post only assigns existing terms; nothing was changed. If the user wants new terms and create_terms is available, use it.', 'wp-cortex' ),
					implode( ', ', $missing )
				)
			);
		}

		if ( ! $changes ) {
			return $this->error( __( 'Nothing to change. Pass at least one field.', 'wp-cortex' ) );
		}

		$admin->add_action(
			array(
				'type'    => 'edit_post',
				'post_id' => $admin->post_id(),
				'changes' => $changes,
				'labels'  => $labels,
			)
		);

		return array(
			'ok'      => true,
			'changed' => $labels,
			'saved'   => false,
		);
	}

	/**
	 * Taxonomies the user can assign to the post.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, array{rest_base: string, label: string}>
	 */
	private function taxonomies( ToolContext $context ): array {
		$post_id = $this->admin( $context )->post_id();

		return $post_id > 0 ? EditorState::taxonomies( (string) get_post_type( $post_id ) ) : array();
	}

	/**
	 * Templates the post can use, without the default one.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, string> Template slug or file => name.
	 */
	private function templates( ToolContext $context ): array {
		$post = get_post( $this->admin( $context )->post_id() );

		return $post ? EditorState::templates( $post ) : array();
	}

	/**
	 * User who can be the author of posts of a type, by display name or username.
	 *
	 * @param string $name      Display name or username.
	 * @param string $post_type Post type.
	 * @return int|string User ID, or an error message.
	 */
	private function find_author( string $name, string $post_type ): int|string {
		$type  = get_post_type_object( $post_type );
		$name  = mb_strtolower( trim( $name ) );
		$users = get_users(
			array(
				'capability' => array( $type ? $type->cap->edit_posts : 'edit_posts' ),
				'fields'     => array( 'ID', 'display_name', 'user_login' ),
				'number'     => self::LISTED_AUTHORS,
				'orderby'    => 'display_name',
			)
		);
		$names = array();

		foreach ( $users as $user ) {
			if ( '' !== $name && in_array( $name, array( mb_strtolower( $user->display_name ), mb_strtolower( $user->user_login ) ), true ) ) {
				return (int) $user->ID;
			}

			$names[] = $user->display_name;
		}

		/* translators: %s: comma-separated user names */
		return sprintf( __( 'No such author. Users who can be the author: %s. Nothing was changed.', 'wp-cortex' ), implode( ', ', array_slice( $names, 0, 30 ) ) );
	}

	/**
	 * Image in the Media Library, by ID or by words from its title or file name.
	 *
	 * @param string $value Attachment ID, search words, or "0" / "" for no image.
	 * @return int|string Attachment ID (0 removes the image), or an error message.
	 */
	private function find_image( string $value ): int|string {
		$value = trim( sanitize_text_field( $value ) );

		if ( '' === $value || '0' === $value ) {
			return 0;
		}

		if ( ctype_digit( $value ) ) {
			return wp_attachment_is_image( (int) $value ) ? (int) $value : __( 'That ID is not an image in the Media Library. Nothing was changed.', 'wp-cortex' );
		}

		// Also look in the file names, as the Media Library search does.
		add_filter( 'wp_allow_query_attachment_by_filename', '__return_true' );

		$images = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				's'              => $value,
				'posts_per_page' => self::LISTED_IMAGES,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		remove_filter( 'wp_allow_query_attachment_by_filename', '__return_true' );

		$rows = array();

		foreach ( $images as $image ) {
			if ( 0 === strcasecmp( $image->post_title, $value ) ) {
				return (int) $image->ID;
			}

			$rows[] = sprintf( '#%1$d «%2$s» (%3$s)', $image->ID, $image->post_title, wp_basename( (string) get_attached_file( $image->ID ) ) );
		}

		if ( 1 === count( $images ) ) {
			return (int) $images[0]->ID;
		}

		if ( ! $rows ) {
			return __( 'No image in the Media Library matches. Nothing was changed; ask the user for other words or to upload the image.', 'wp-cortex' );
		}

		/* translators: %s: list of images */
		return sprintf( __( 'Several images match, newest first: %s. Nothing was changed; ask the user which one, then pass its ID.', 'wp-cortex' ), implode( '; ', $rows ) );
	}

	/**
	 * Names of terms.
	 *
	 * @param int[]  $ids      Term IDs.
	 * @param string $taxonomy Taxonomy.
	 * @return string[]
	 */
	private function term_names( array $ids, string $taxonomy ): array {
		if ( ! $ids ) {
			return array();
		}

		$names = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'include'    => $ids,
				'hide_empty' => false,
				'fields'     => 'names',
			)
		);

		return is_array( $names ) ? array_map( array( $this, 'decode' ), $names ) : array();
	}

	/**
	 * Term name as plain text (names are stored with HTML entities).
	 *
	 * @param string $name Term name.
	 */
	private function decode( string $name ): string {
		return wp_specialchars_decode( $name, ENT_QUOTES );
	}

	/**
	 * Existing term by name or slug.
	 *
	 * @param string $name     Term name or slug.
	 * @param string $taxonomy Taxonomy.
	 * @return int|null Term ID.
	 */
	private function find_term( string $name, string $taxonomy ): ?int {
		$name = trim( wp_specialchars_decode( $name, ENT_QUOTES ) );

		if ( '' === $name ) {
			return null;
		}

		$term = get_term_by( 'name', $name, $taxonomy );

		if ( ! $term ) {
			$term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
		}

		return $term ? (int) $term->term_id : null;
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
