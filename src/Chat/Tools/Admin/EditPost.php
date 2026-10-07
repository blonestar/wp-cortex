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
 * Changes the title, excerpt, slug and terms of the post open in the block editor.
 * Nothing is written to the database: the browser applies the change in the editor and
 * the user saves the post (or undoes the change) as usual. Only existing terms can be
 * assigned.
 */
final class EditPost extends AdminTool {

	/**
	 * Existing terms per taxonomy listed in the system prompt, the most used first.
	 */
	private const LISTED_TERMS = 40;

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
		return 'Changes the title, excerpt, slug or terms (categories, tags, ...) of the post open in the editor. Pass only the fields to change. The change is applied in the editor only and is not saved: the user reviews it and saves the post. Call it only when the user asks for the change or accepts a value you suggested.';
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

		$lines[] = 'To change these fields, call edit_post. When the user asks for suggestions (for example titles or an excerpt), only suggest them and wait: call edit_post only after the user picks or accepts one. Only existing terms can be assigned; never invent term names. Editor tools (edit_post and the other editor tools) need no confirmation card: they change the editor right away but do not save the post; after calling them, tell the user to review the changes and save the post.';

		return $lines;
	}

	/**
	 * Queues the changes for the editor.
	 *
	 * @param array       $args    Arguments: title, excerpt, slug, terms.
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
					__( 'These terms do not exist: %s. Only existing terms can be assigned; nothing was changed.', 'wp-cortex' ),
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
