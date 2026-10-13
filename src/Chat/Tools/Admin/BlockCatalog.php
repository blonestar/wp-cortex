<?php
/**
 * Block types and patterns the admin chat can insert in the block editor.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * What list_block_types and edit_content know about the blocks of the site: the block
 * types the editor allows in the post (reported by the browser, described by the block
 * registry), the ACF fields of an ACF block the chat can fill, and the registered block
 * patterns offered for the post type. It only reads registries, never the database
 * content of a post.
 */
final class BlockCatalog {

	public const MAX_DESCRIPTION_LENGTH = 160;
	public const MAX_PATTERN_LENGTH     = 100000;
	public const MAX_PATTERN_TEXTS      = 30;

	/**
	 * ACF field types of a block the chat can fill: the simple types of edit_fields and
	 * the WYSIWYG editor (as HTML).
	 */
	public const FIELD_TYPES = array( 'text', 'textarea', 'wysiwyg', 'number', 'email', 'url', 'select', 'radio', 'true_false', 'range' );

	/**
	 * Fields per ACF block name.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private static array $fields = array();

	/**
	 * Block types the editor allows in the post, with what the block registry knows
	 * about them.
	 *
	 * @param array<string, string> $types Block name => title, from the editor state.
	 * @return array<string, array<string, mixed>> Keyed by block name: name, title, description, keywords, fields, inner_blocks.
	 */
	public static function block_types( array $types ): array {
		$registry = \WP_Block_Type_Registry::get_instance();
		$rows     = array();

		foreach ( $types as $name => $title ) {
			$name = (string) $name;
			$type = $registry->get_registered( $name );
			$row  = array(
				'name'        => $name,
				'title'       => '' !== $title ? $title : $name,
				'description' => '',
				'keywords'    => array(),
			);

			if ( $type ) {
				$row['description'] = self::clip( wp_strip_all_tags( (string) $type->description ), self::MAX_DESCRIPTION_LENGTH );
				$row['keywords']    = array_map( 'strval', array_filter( (array) $type->keywords, 'is_scalar' ) );
			}

			$fields = self::acf_fields( $name );

			if ( $fields ) {
				$row['fields'] = array_values( $fields );
			}

			if ( self::acf_holds_blocks( $name ) ) {
				$row['inner_blocks'] = true;
			}

			$rows[ $name ] = $row;
		}

		return $rows;
	}

	/**
	 * Top-level fields of an ACF block the chat can fill.
	 *
	 * @param string $name Block name.
	 * @return array<string, array<string, mixed>> Keyed by field name: key, name, label, type, choices.
	 */
	public static function acf_fields( string $name ): array {
		if ( ! str_starts_with( $name, 'acf/' ) || ! EditorState::has_fields() || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		if ( isset( self::$fields[ $name ] ) ) {
			return self::$fields[ $name ];
		}

		$fields = array();

		foreach ( (array) acf_get_field_groups( array( 'block' => $name ) ) as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				$field_name = is_array( $field ) ? (string) ( $field['name'] ?? '' ) : '';

				if ( '' === $field_name || isset( $fields[ $field_name ] ) || ! in_array( $field['type'] ?? '', self::FIELD_TYPES, true ) ) {
					continue;
				}

				if ( 'select' === $field['type'] && ! empty( $field['multiple'] ) ) {
					continue;
				}

				if ( ! preg_match( '/^field_[A-Za-z0-9_]+$/', (string) ( $field['key'] ?? '' ) ) || count( $fields ) >= EditorState::MAX_FIELDS ) {
					continue;
				}

				$row = array(
					'key'     => (string) $field['key'],
					'name'    => $field_name,
					'label'   => wp_strip_all_tags( (string) ( $field['label'] ?? '' ) ),
					'type'    => (string) $field['type'],
					'choices' => array(),
				);

				if ( in_array( $field['type'], array( 'select', 'radio' ), true ) ) {
					foreach ( (array) ( $field['choices'] ?? array() ) as $value => $label ) {
						$row['choices'][ (string) $value ] = wp_strip_all_tags( (string) $label );
					}
				}

				$fields[ $field_name ] = $row;
			}
		}

		self::$fields[ $name ] = $fields;

		return $fields;
	}

	/**
	 * Validates field values for an ACF block.
	 *
	 * @param string $name   Block name.
	 * @param mixed  $values Field name => value.
	 * @return array<int, array{key: string, name: string, value: string}>|string The fields for the browser, or the name of the field that is unknown or has an invalid value.
	 */
	public static function field_values( string $name, $values ) {
		$fields = self::acf_fields( $name );
		$clean  = array();

		foreach ( is_array( $values ) ? $values : array() as $field_name => $value ) {
			$field = $fields[ (string) $field_name ] ?? null;
			$value = null === $field ? null : EditorState::field_value( $field, $value );

			if ( null === $value ) {
				return sanitize_text_field( (string) $field_name );
			}

			$clean[] = array(
				'key'   => $field['key'],
				'name'  => $field['name'],
				'value' => $value,
			);
		}

		return $clean;
	}

	/**
	 * Registered block patterns offered for a post type in the post editor.
	 *
	 * @param string $post_type Post type.
	 * @return array<string, array<string, mixed>> Keyed by pattern name: name, title, description, keywords, content.
	 */
	public static function patterns( string $post_type ): array {
		$rows = array();

		foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$name    = (string) ( $pattern['name'] ?? '' );
			$content = (string) ( $pattern['content'] ?? '' );

			if ( '' === $name || '' === trim( $content ) || strlen( $content ) > self::MAX_PATTERN_LENGTH ) {
				continue;
			}

			// Hidden from the inserter, made for templates, or for other post types.
			if ( false === ( $pattern['inserter'] ?? true ) || ! empty( $pattern['templateTypes'] ) ) {
				continue;
			}

			if ( ! empty( $pattern['postTypes'] ) && ! in_array( $post_type, (array) $pattern['postTypes'], true ) ) {
				continue;
			}

			$rows[ $name ] = array(
				'name'        => $name,
				'title'       => wp_strip_all_tags( (string) ( $pattern['title'] ?? $name ) ),
				'description' => self::clip( wp_strip_all_tags( (string) ( $pattern['description'] ?? '' ) ), self::MAX_DESCRIPTION_LENGTH ),
				'keywords'    => array_merge(
					array_map( 'strval', array_filter( (array) ( $pattern['keywords'] ?? array() ), 'is_scalar' ) ),
					array_map( 'strval', array_filter( (array) ( $pattern['categories'] ?? array() ), 'is_scalar' ) )
				),
				'content'     => $content,
			);
		}

		return $rows;
	}

	/**
	 * Text blocks of a pattern in document order, which insert_pattern can fill: the
	 * same blocks, in the same order, as chat.js walks after parsing the pattern.
	 *
	 * @param string $content Pattern content (block markup).
	 * @return array<int, array<string, mixed>> Per text block: block, level (headings), text or placeholder.
	 */
	public static function pattern_texts( string $content ): array {
		$texts = array();

		self::collect_texts( parse_blocks( $content ), $texts );

		return $texts;
	}

	/**
	 * Rows that match a search, best match first; every row when the search is empty.
	 *
	 * @param array<string, array<string, mixed>> $rows   Block types or patterns.
	 * @param string                              $search Search words.
	 * @return array<int, array<string, mixed>>
	 */
	public static function search( array $rows, string $search ): array {
		$words = array_filter( preg_split( '/[\s,]+/', mb_strtolower( $search ) ) );

		if ( ! $words ) {
			return array_values( $rows );
		}

		$scores = array();

		foreach ( $rows as $name => $row ) {
			$haystack = mb_strtolower( $row['name'] . ' ' . $row['title'] . ' ' . $row['description'] . ' ' . implode( ' ', $row['keywords'] ) );
			$score    = 0;

			foreach ( $words as $word ) {
				if ( str_contains( $haystack, $word ) ) {
					// A word in the name or title counts more than one in the description.
					$score += str_contains( mb_strtolower( $row['name'] . ' ' . $row['title'] ), $word ) ? 2 : 1;
				}
			}

			if ( $score > 0 ) {
				$scores[ $name ] = $score;
			}
		}

		arsort( $scores );

		$found = array();

		foreach ( array_keys( $scores ) as $name ) {
			$found[] = $rows[ $name ];
		}

		return $found;
	}

	/**
	 * Whether an ACF block holds other blocks (InnerBlocks).
	 *
	 * @param string $name Block name.
	 */
	private static function acf_holds_blocks( string $name ): bool {
		if ( ! str_starts_with( $name, 'acf/' ) || ! function_exists( 'acf_get_block_type' ) ) {
			return false;
		}

		$type = acf_get_block_type( $name );

		return is_array( $type ) && ! empty( $type['supports']['jsx'] );
	}

	/**
	 * Collects the text blocks of parsed blocks, depth first.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $texts  Text blocks found so far (by reference).
	 */
	private static function collect_texts( array $blocks, array &$texts ): void {
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );

			if ( isset( EditorState::TEXT_BLOCKS[ $name ] ) ) {
				if ( count( $texts ) >= self::MAX_PATTERN_TEXTS ) {
					return;
				}

				$row  = array( 'block' => $name );
				$text = self::clip( trim( html_entity_decode( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), self::MAX_DESCRIPTION_LENGTH );

				if ( 'core/heading' === $name ) {
					$row['level'] = min( 6, max( 1, absint( $block['attrs']['level'] ?? 2 ) ) );
				}

				if ( '' !== $text ) {
					$row['text'] = $text;
				} elseif ( is_string( $block['attrs']['placeholder'] ?? null ) && '' !== $block['attrs']['placeholder'] ) {
					$row['placeholder'] = self::clip( sanitize_text_field( $block['attrs']['placeholder'] ), self::MAX_DESCRIPTION_LENGTH );
				}

				$texts[] = $row;
			}

			self::collect_texts( (array) ( $block['innerBlocks'] ?? array() ), $texts );
		}
	}

	/**
	 * Cuts a string to a length.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum length in characters.
	 */
	private static function clip( string $text, int $length ): string {
		return mb_strlen( $text ) > $length ? mb_substr( $text, 0, $length - 1 ) . '…' : $text;
	}
}
