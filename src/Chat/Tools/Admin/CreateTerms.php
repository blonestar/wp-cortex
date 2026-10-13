<?php
/**
 * Admin chat tool: create_terms.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Creates new terms (tags, categories, terms of other taxonomies) for the post open in
 * the block editor. Unlike the editor tools it writes to the database, so a call is only
 * shown as an action card and the terms are created after the user confirms it; they are
 * then added to the post in the editor, which the user saves as usual.
 */
final class CreateTerms extends AdminTool implements ConfirmableTool {

	/**
	 * Most terms per call, and the longest term name.
	 */
	private const MAX_TERMS       = 10;
	private const MAX_NAME_LENGTH = 100;

	public function name(): string {
		return 'create_terms';
	}

	public function label(): string {
		return __( 'Create tags and categories', 'wp-cortex' );
	}

	/**
	 * Offered while the block editor is open and the user can create terms in a taxonomy
	 * of the post.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		$admin = $this->admin( $context );

		return null !== $admin->editor() && array() !== self::taxonomies( (string) get_post_type( $admin->post_id() ) );
	}

	/**
	 * What else decides how the tool runs.
	 */
	public function availability_note(): string {
		return __( 'Only while the block editor of a post is open. The terms are created only after you confirm them in the chat.', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Creates new terms (tags, categories, ...) that do not exist yet and adds them to the post open in the editor. It is shown to the user as an action card and runs only after the user confirms it. Use it only when the user asks for a term that does not exist; assign existing terms with edit_post.';
	}

	/**
	 * Arguments.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function parameters( ToolContext $context ): ?array {
		$taxonomy = array(
			'type'        => 'string',
			'description' => 'Taxonomy of the new terms.',
		);
		$names    = array_keys( self::taxonomies( (string) get_post_type( $this->admin( $context )->post_id() ) ) );

		if ( $names ) {
			$taxonomy['enum'] = $names;
		}

		return array(
			'type'       => 'object',
			'properties' => array(
				'taxonomy' => $taxonomy,
				'names'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Names of the new terms, written as they should appear on the site.',
				),
				'parent'   => array(
					'type'        => 'string',
					'description' => 'Name of the existing term the new terms go under, for taxonomies with a hierarchy (categories). Omit for top-level terms.',
				),
			),
			'required'   => array( 'taxonomy', 'names' ),
		);
	}

	/**
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array( 'When the user asks for a tag, category or other term that does not exist yet, call create_terms once with every new term of that taxonomy; it needs the user\'s confirmation on an action card and then adds the terms to the post in the editor. Do not create terms the user did not ask for, and check the existing terms first: a term that differs only in spelling or case is the existing one.' );
	}

	/**
	 * Shows the new terms as an action card.
	 *
	 * @param array       $args    Arguments: taxonomy, names, parent.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$admin      = $this->admin( $context );
		$taxonomies = self::taxonomies( (string) get_post_type( $admin->post_id() ) );
		$taxonomy   = (string) ( $args['taxonomy'] ?? '' );

		if ( ! isset( $taxonomies[ $taxonomy ] ) ) {
			return $this->error( 'Unknown taxonomy, or the user cannot create terms in it.' );
		}

		$new      = array();
		$existing = array();

		foreach ( array_slice( (array) ( $args['names'] ?? array() ), 0, self::MAX_TERMS ) as $name ) {
			$name = is_scalar( $name ) ? trim( sanitize_text_field( (string) $name ) ) : '';

			if ( '' === $name || mb_strlen( $name ) > self::MAX_NAME_LENGTH ) {
				continue;
			}

			if ( term_exists( $name, $taxonomy ) ) {
				$existing[] = $name;
			} elseif ( ! in_array( mb_strtolower( $name ), array_map( 'mb_strtolower', $new ), true ) ) {
				$new[] = $name;
			}
		}

		if ( ! $new ) {
			return $this->error( $existing ? sprintf( 'These terms already exist: %s. Assign them with edit_post.', implode( ', ', $existing ) ) : 'Pass the names of the new terms.' );
		}

		$parent = 0;
		$under  = trim( sanitize_text_field( (string) ( $args['parent'] ?? '' ) ) );

		if ( '' !== $under ) {
			$term = is_taxonomy_hierarchical( $taxonomy ) ? get_term_by( 'name', $under, $taxonomy ) : null;

			if ( ! $term ) {
				return $this->error( 'The parent must be the name of an existing term of a taxonomy with a hierarchy.' );
			}

			$parent = (int) $term->term_id;
		}

		$label = $taxonomies[ $taxonomy ]['label'];
		$id    = wp_generate_uuid4();

		$admin->set_item(
			'action:' . $id,
			array(
				'role'   => AbilityTool::ACTION_ROLE,
				'action' => array(
					'id'          => $id,
					'tool'        => $this->name(),
					/* translators: %s: taxonomy name, for example "Tags" */
					'label'       => sprintf( __( 'Create new terms in %s', 'wp-cortex' ), $label ),
					'description' => $parent > 0
						/* translators: 1: comma-separated term names, 2: parent term name */
						? sprintf( __( 'New: %1$s (under “%2$s”). Running this creates them on the site and adds them to the post in the editor.', 'wp-cortex' ), implode( ', ', $new ), $under )
						/* translators: %s: comma-separated term names */
						: sprintf( __( 'New: %s. Running this creates them on the site and adds them to the post in the editor.', 'wp-cortex' ), implode( ', ', $new ) ),
					'destructive' => false,
					'input'       => array(
						'taxonomy' => $taxonomy,
						'names'    => $new,
						'parent'   => $parent,
						'post_id'  => $admin->post_id(),
					),
					'status'      => 'pending',
					'created'     => time(),
				),
			)
		);

		$response = array(
			'status' => 'awaiting_confirmation',
			'note'   => 'Nothing has been created yet. The user sees an action card; the terms are created and added to the post in the editor only if they confirm it. Tell the user briefly which terms will be created and that they can run or cancel it below. Do not call it again, and do not assign these terms with edit_post.',
		);

		if ( $existing ) {
			$response['existing'] = $existing;
			$response['note']    .= ' The terms under "existing" already exist: assign them with edit_post.';
		}

		return $response;
	}

	/**
	 * Creates the confirmed terms and adds them to the post in the editor.
	 *
	 * @param array $input Input stored with the action card: taxonomy, names, parent, post_id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function run_confirmed( array $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$taxonomy   = (string) ( $input['taxonomy'] ?? '' );
		$taxonomies = self::taxonomies( (string) get_post_type( $post_id ) );

		if ( $post_id < 1 || ! isset( $taxonomies[ $taxonomy ] ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'wp_cortex_forbidden', __( 'You are not allowed to create these terms.', 'wp-cortex' ) );
		}

		$ids     = array();
		$created = array();

		foreach ( array_slice( (array) ( $input['names'] ?? array() ), 0, self::MAX_TERMS ) as $name ) {
			$name = trim( sanitize_text_field( (string) $name ) );
			$term = '' === $name ? null : wp_insert_term( $name, $taxonomy, array( 'parent' => absint( $input['parent'] ?? 0 ) ) );

			if ( is_wp_error( $term ) && $term->get_error_data( 'term_exists' ) ) {
				// Created in the meantime: use the existing term.
				$ids[] = (int) $term->get_error_data( 'term_exists' );
			} elseif ( is_array( $term ) ) {
				$ids[]     = (int) $term['term_id'];
				$created[] = $name;
			}
		}

		if ( ! $ids ) {
			return new \WP_Error( 'wp_cortex_terms_failed', __( 'The terms could not be created.', 'wp-cortex' ) );
		}

		return array(
			'created'   => $created,
			'taxonomy'  => $taxonomy,
			'note'      => 'The terms exist on the site now and were added to the post in the editor; the post itself is not saved yet.',
			'ui_action' => array(
				'type'    => 'edit_post',
				'post_id' => $post_id,
				'changes' => array(
					'add_terms' => array( $taxonomies[ $taxonomy ]['rest_base'] => $ids ),
				),
				'labels'  => array( $taxonomies[ $taxonomy ]['label'] ),
			),
		);
	}

	/**
	 * Taxonomies of a post type in which the user can create terms: like the editor,
	 * `edit_terms` for taxonomies with a hierarchy and `assign_terms` for the others.
	 *
	 * @param string $post_type Post type.
	 * @return array<string, array{rest_base: string, label: string}> Keyed by taxonomy name.
	 */
	private static function taxonomies( string $post_type ): array {
		$taxonomies = array();

		foreach ( '' !== $post_type ? EditorState::taxonomies( $post_type ) : array() as $name => $info ) {
			$taxonomy = get_taxonomy( $name );

			if ( $taxonomy && current_user_can( $taxonomy->hierarchical ? $taxonomy->cap->edit_terms : $taxonomy->cap->assign_terms ) ) {
				$taxonomies[ $name ] = $info;
			}
		}

		return $taxonomies;
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
