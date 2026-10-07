<?php
/**
 * State of the block editor reported by the admin chat panel.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes what the chat panel reports about the post open in the block editor (its
 * fields as they are in the editor, possibly not saved yet) and validates the values
 * the editor tools write back. The editor tools never save anything: the browser
 * applies their changes in the editor and the user saves the post.
 */
final class EditorState {

	public const MAX_TITLE_LENGTH       = 300;
	public const MAX_EXCERPT_LENGTH     = 2000;
	public const MAX_SLUG_LENGTH        = 200;
	public const MAX_TERMS              = 200;
	public const MAX_SEO_TITLE_LENGTH   = 300;
	public const MAX_SEO_DESC_LENGTH    = 1000;
	public const MAX_KEYPHRASE_LENGTH   = 200;
	public const MAX_BLOCKS             = 300;
	public const MAX_BLOCK_TEXT_LENGTH  = 4000;
	public const MAX_FIELDS             = 80;
	public const MAX_FIELD_VALUE_LENGTH = 4000;

	/**
	 * Blocks whose text can be replaced, with the attribute that holds it. Keep in sync
	 * with BLOCK_TEXT in assets/js/chat.js.
	 */
	public const TEXT_BLOCKS = array(
		'core/paragraph'    => 'content',
		'core/heading'      => 'content',
		'core/list-item'    => 'content',
		'core/preformatted' => 'content',
		'core/verse'        => 'content',
		'core/button'       => 'text',
	);

	/**
	 * ACF field types the chat can change. Keep in sync with ACF_TYPES in assets/js/chat.js.
	 */
	public const FIELD_TYPES = array( 'text', 'textarea', 'number', 'email', 'url', 'select', 'radio', 'true_false', 'range' );

	/**
	 * Inline HTML allowed in the text of a block.
	 */
	private const INLINE_TAGS = array(
		'a'      => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		),
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'i'      => array(),
		'u'      => array(),
		's'      => array(),
		'code'   => array(),
		'mark'   => array(),
		'sub'    => array(),
		'sup'    => array(),
		'br'     => array(),
	);

	/**
	 * Sanitizes the editor state sent by the browser.
	 *
	 * @param mixed $raw     Editor state from the screen context.
	 * @param int   $post_id Post open in the editor.
	 * @return array<string, mixed>|null Null when there is no editor or the user cannot edit the post.
	 */
	public static function sanitize( $raw, int $post_id ): ?array {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! is_array( $raw ) || ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return null;
		}

		$state = array(
			'title'   => self::clip( sanitize_text_field( (string) ( $raw['title'] ?? '' ) ), self::MAX_TITLE_LENGTH ),
			'excerpt' => post_type_supports( $post->post_type, 'excerpt' )
				? self::clip( sanitize_textarea_field( (string) ( $raw['excerpt'] ?? '' ) ), self::MAX_EXCERPT_LENGTH )
				: null,
			'slug'    => self::clip( sanitize_title( (string) ( $raw['slug'] ?? '' ) ), self::MAX_SLUG_LENGTH ),
			'terms'   => array(),
			'seo'     => null,
			'blocks'  => null,
			'fields'  => array(),
		);

		$terms = is_array( $raw['terms'] ?? null ) ? $raw['terms'] : array();

		foreach ( self::taxonomies( $post->post_type ) as $taxonomy => $info ) {
			$ids = is_array( $terms[ $info['rest_base'] ] ?? null ) ? $terms[ $info['rest_base'] ] : array();

			$state['terms'][ $taxonomy ] = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, self::MAX_TERMS );
		}

		if ( self::has_seo() && is_array( $raw['seo'] ?? null ) ) {
			$state['seo'] = array(
				'title'           => self::clip( sanitize_text_field( (string) ( $raw['seo']['title'] ?? '' ) ), self::MAX_SEO_TITLE_LENGTH ),
				'description'     => self::clip( sanitize_textarea_field( (string) ( $raw['seo']['description'] ?? '' ) ), self::MAX_SEO_DESC_LENGTH ),
				'focus_keyphrase' => self::clip( sanitize_text_field( (string) ( $raw['seo']['focus_keyphrase'] ?? '' ) ), self::MAX_KEYPHRASE_LENGTH ),
			);
		}

		if ( is_array( $raw['blocks'] ?? null ) ) {
			$state['blocks'] = self::sanitize_blocks( $raw['blocks'] );
		}

		if ( is_array( $raw['fields'] ?? null ) ) {
			$state['fields'] = self::sanitize_fields( $raw['fields'], $post_id );
		}

		return $state;
	}

	/**
	 * Taxonomies of a post type the user can assign in the editor.
	 *
	 * @param string $post_type Post type.
	 * @return array<string, array{rest_base: string, label: string}> Keyed by taxonomy name.
	 */
	public static function taxonomies( string $post_type ): array {
		$taxonomies = array();

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->show_in_rest || ! $taxonomy->show_ui || ! current_user_can( $taxonomy->cap->assign_terms ) ) {
				continue;
			}

			$taxonomies[ $taxonomy->name ] = array(
				'rest_base' => is_string( $taxonomy->rest_base ) && '' !== $taxonomy->rest_base ? $taxonomy->rest_base : $taxonomy->name,
				'label'     => (string) $taxonomy->labels->name,
			);
		}

		return $taxonomies;
	}

	/**
	 * Whether Yoast SEO is active, so its editor fields can be read and changed.
	 */
	public static function has_seo(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Whether ACF is active.
	 */
	public static function has_fields(): bool {
		return function_exists( 'acf_get_field' ) && function_exists( 'acf_get_field_groups' );
	}

	/**
	 * Sanitizes inline HTML for the text of a block.
	 *
	 * @param string $html HTML.
	 */
	public static function inline_html( string $html ): string {
		return trim( force_balance_tags( wp_kses( $html, self::INLINE_TAGS ) ) );
	}

	/**
	 * Validates a value for an ACF field.
	 *
	 * @param array<string, mixed> $field Field from the editor state (type, choices).
	 * @param mixed                $value Value.
	 * @return string|null Sanitized value, null when it is not valid for the field.
	 */
	public static function field_value( array $field, $value ): ?string {
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '0';
		}

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = (string) $value;

		switch ( $field['type'] ) {
			case 'textarea':
				$value = sanitize_textarea_field( $value );
				break;
			case 'number':
			case 'range':
				$value = trim( $value );

				if ( '' !== $value && ! is_numeric( $value ) ) {
					return null;
				}
				break;
			case 'email':
				$value = trim( $value );

				if ( '' !== $value && ! is_email( $value ) ) {
					return null;
				}
				break;
			case 'url':
				$url = esc_url_raw( trim( $value ) );

				if ( '' === $url && '' !== trim( $value ) ) {
					return null;
				}

				$value = $url;
				break;
			case 'true_false':
				$flag = strtolower( trim( $value ) );

				if ( in_array( $flag, array( '1', 'true', 'yes', 'on' ), true ) ) {
					$value = '1';
				} elseif ( in_array( $flag, array( '', '0', 'false', 'no', 'off' ), true ) ) {
					$value = '0';
				} else {
					return null;
				}
				break;
			case 'select':
			case 'radio':
				if ( '' !== $value && ! array_key_exists( $value, (array) $field['choices'] ) ) {
					return null;
				}
				break;
			default:
				$value = sanitize_text_field( $value );
		}

		return mb_strlen( $value ) <= self::MAX_FIELD_VALUE_LENGTH ? $value : null;
	}

	/**
	 * Sanitizes the block list: client ID, block name, nesting depth, heading level and,
	 * for text blocks, their inline HTML.
	 *
	 * @param array $blocks Blocks from the browser, in document order.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sanitize_blocks( array $blocks ): array {
		$clean = array();

		foreach ( array_slice( $blocks, 0, self::MAX_BLOCKS ) as $block ) {
			$id   = is_array( $block ) ? (string) ( $block['id'] ?? '' ) : '';
			$name = is_array( $block ) ? (string) ( $block['name'] ?? '' ) : '';

			if ( ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', $id ) || ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name ) ) {
				continue;
			}

			$row = array(
				'id'    => $id,
				'name'  => $name,
				'depth' => min( 20, absint( $block['depth'] ?? 0 ) ),
			);

			if ( 'core/heading' === $name ) {
				$row['level'] = min( 6, max( 1, absint( $block['level'] ?? 2 ) ) );
			}

			if ( isset( self::TEXT_BLOCKS[ $name ] ) ) {
				$text = (string) ( $block['text'] ?? '' );

				if ( mb_strlen( $text ) > self::MAX_BLOCK_TEXT_LENGTH ) {
					$text             = mb_substr( $text, 0, self::MAX_BLOCK_TEXT_LENGTH );
					$row['truncated'] = true;
				}

				$row['text'] = self::inline_html( $text );
			}

			$clean[] = $row;
		}

		return $clean;
	}

	/**
	 * Sanitizes the ACF fields: only top-level fields of a supported type that belong to
	 * a field group shown for this post. Type, label and choices come from ACF, the
	 * current value from the editor.
	 *
	 * @param array $fields  Fields from the browser: key, value.
	 * @param int   $post_id Post ID.
	 * @return array<string, array<string, mixed>> Keyed by field key.
	 */
	private static function sanitize_fields( array $fields, int $post_id ): array {
		if ( ! self::has_fields() ) {
			return array();
		}

		$groups = array();

		foreach ( acf_get_field_groups( array( 'post_id' => $post_id ) ) as $group ) {
			$groups[ (string) $group['ID'] ] = (string) $group['title'];
			$groups[ (string) $group['key'] ] = (string) $group['title'];
		}

		$clean = array();

		foreach ( array_slice( $fields, 0, self::MAX_FIELDS ) as $item ) {
			$key = is_array( $item ) ? (string) ( $item['key'] ?? '' ) : '';

			if ( ! preg_match( '/^field_[A-Za-z0-9_]+$/', $key ) || isset( $clean[ $key ] ) ) {
				continue;
			}

			$field = acf_get_field( $key );

			if ( ! is_array( $field ) || ! in_array( $field['type'] ?? '', self::FIELD_TYPES, true ) || ! isset( $groups[ (string) ( $field['parent'] ?? '' ) ] ) ) {
				continue;
			}

			if ( 'select' === $field['type'] && ! empty( $field['multiple'] ) ) {
				continue;
			}

			$row = array(
				'key'     => $key,
				'name'    => (string) $field['name'],
				'label'   => wp_strip_all_tags( (string) $field['label'] ),
				'type'    => (string) $field['type'],
				'group'   => $groups[ (string) $field['parent'] ],
				'choices' => array(),
			);

			if ( in_array( $field['type'], array( 'select', 'radio' ), true ) ) {
				foreach ( (array) ( $field['choices'] ?? array() ) as $value => $label ) {
					$row['choices'][ (string) $value ] = wp_strip_all_tags( (string) $label );
				}
			}

			$value        = self::field_value( $row, $item['value'] ?? '' );
			$row['value'] = null === $value ? '' : $value;

			$clean[ $key ] = $row;
		}

		return $clean;
	}

	/**
	 * Cuts a string to a length.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum length in characters.
	 */
	private static function clip( string $text, int $length ): string {
		return mb_substr( $text, 0, $length );
	}
}
