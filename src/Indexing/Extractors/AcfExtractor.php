<?php
/**
 * Advanced Custom Fields data.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing\Extractors;

use WP_Post;
use WP_Term;
use WP_User;
use WPCortex\Indexing\Document;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Flattens ACF values (groups, repeaters, flexible content) into dotted field names.
 * Long text values are also added as content sections so they are chunked and searchable.
 *
 * The admin index gets every field with every value, including links to drafts. For the
 * public index, content fields of the post types it holds (text, dates, relations to other
 * posts, repeaters...) are chosen by default, settings-like fields (switches, numbers,
 * colors, choices, files) and fields whose name marks them as internal are not; related
 * posts that are not published and terms of hidden taxonomies are never named publicly.
 */
final class AcfExtractor implements Extractor, DescribesFields {

	/**
	 * Text longer than this (in characters) becomes a content section as well.
	 */
	private const SECTION_MIN_LENGTH = 200;

	/**
	 * Public index default of each listed field (field key => bool), filled on first use so
	 * extraction uses the same defaults as the settings screen.
	 *
	 * @var array<string, bool>|null
	 */
	private ?array $defaults = null;

	private const MAX_VALUE_LENGTH = 2000;

	/**
	 * ACF location rules that target posts.
	 */
	private const POST_PARAMS = array( 'post_type', 'post_template', 'post_status', 'post_format', 'post_category', 'post_taxonomy', 'post', 'page_template', 'page_type', 'page_parent', 'page', 'attachment' );

	/**
	 * ACF location rules that target something other than posts.
	 */
	private const OTHER_OBJECT_PARAMS = array( 'block', 'options_page', 'taxonomy', 'user_form', 'user_role', 'nav_menu', 'nav_menu_item', 'widget', 'comment' );

	/**
	 * ACF field types that only structure the edit screen and have no value.
	 */
	private const LAYOUT_TYPES = array( 'tab', 'message', 'accordion' );

	/**
	 * ACF field types whose values are never indexed.
	 */
	private const SECRET_TYPES = array( 'password' );

	/**
	 * ACF field types that hold content shown to visitors, so they go into the public index
	 * by default: text, dates and links to other posts and terms.
	 */
	private const CONTENT_TYPES = array( 'text', 'textarea', 'wysiwyg', 'date_picker', 'date_time_picker', 'time_picker', 'relationship', 'post_object', 'page_link', 'taxonomy', 'repeater', 'group', 'flexible_content' );

	/**
	 * Field name parts that mark a field as internal (admin index only by default).
	 */
	private const INTERNAL_NAME_PATTERN = '/(^|_)(id|ids|code|key|token|secret|pass|password|internal|note|notes|admin|private|debug|cookie|tracking|email|phone|embed)(_|$)/i';

	public function is_available(): bool {
		return Settings::get( 'index_acf' ) && function_exists( 'get_field_objects' );
	}

	public function fields_label(): string {
		return __( 'Advanced Custom Fields', 'wp-cortex' );
	}

	/**
	 * Top-level fields of the field groups that are stored on posts (nested values follow
	 * their top-level field), plus a rule for fields added later. Groups of blocks, options
	 * pages, terms, users and menus are left out: their values are not post meta (block
	 * fields are part of the rendered content).
	 */
	public function fields(): array {
		$fields = array();

		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			foreach ( (array) acf_get_field_groups() as $group ) {
				$types = self::group_post_types( (array) ( $group['location'] ?? array() ) );
				if ( null === $types ) {
					continue;
				}

				foreach ( (array) acf_get_fields( $group ) as $field ) {
					$name = (string) ( $field['name'] ?? '' );
					$type = (string) ( $field['type'] ?? '' );
					if ( '' === $name || in_array( $type, self::LAYOUT_TYPES, true ) || in_array( $type, self::SECRET_TYPES, true ) ) {
						continue;
					}

					$key      = 'acf:' . $name;
					$instance = array(
						'label'      => '' !== (string) ( $field['label'] ?? '' ) ? (string) $field['label'] : $name,
						'type'       => $type,
						'post_types' => $types,
					);

					// Fields with the same name in several groups share one stored value and so one rule:
					// listed in every group, public by default only when every one of them would be.
					if ( isset( $fields[ $key ] ) ) {
						$fields[ $key ]['groups'][ (string) ( $group['title'] ?? '' ) ] = $instance;
						$fields[ $key ]['public']                                    = $fields[ $key ]['public'] && self::public_default( $field );
						$fields[ $key ]['post_types']                                = array_values( array_unique( array_merge( $fields[ $key ]['post_types'], $types ) ) );
						if ( ! $types ) {
							$fields[ $key ]['post_types'] = array();
							$fields[ $key ]['any_type']   = true;
						}
						continue;
					}

					$fields[ $key ] = $instance + array(
						'public' => self::public_default( $field ),
						'group'  => (string) ( $group['title'] ?? '' ),
						'groups' => array( (string) ( $group['title'] ?? '' ) => $instance ),
					);
					if ( ! $types ) {
						$fields[ $key ]['any_type'] = true;
					}
				}
			}
		}

		// A field on posts of any type matches every post type.
		foreach ( $fields as $key => $field ) {
			if ( ! empty( $field['any_type'] ) ) {
				$fields[ $key ]['post_types'] = array();
			}
			unset( $fields[ $key ]['any_type'] );
		}

		$fields['acf:*'] = array(
			'label'  => __( 'Other ACF fields (not listed here or added later)', 'wp-cortex' ),
			'public' => false,
		);

		return $fields;
	}

	/**
	 * Post types an ACF location applies to: the post types named in its rules, an empty
	 * array when it applies to posts of any type, or null when it does not apply to posts.
	 * Each OR group of rules counts when it has a post rule and no rule for another object.
	 *
	 * @param array $location ACF location rules (OR groups of AND rules).
	 * @return string[]|null
	 */
	private static function group_post_types( array $location ): ?array {
		$types   = array();
		$matches = false;

		foreach ( $location as $rules ) {
			$params = array_map( static fn( $rule ) => (string) ( $rule['param'] ?? '' ), (array) $rules );

			if ( array_intersect( $params, self::OTHER_OBJECT_PARAMS ) || ! array_intersect( $params, self::POST_PARAMS ) ) {
				continue;
			}

			$matches = true;
			$named   = false;

			foreach ( (array) $rules as $rule ) {
				$param = (string) ( $rule['param'] ?? '' );
				if ( '==' !== ( $rule['operator'] ?? '' ) ) {
					continue;
				}

				if ( 'post_type' === $param ) {
					$types[] = (string) ( $rule['value'] ?? '' );
					$named   = true;
				} elseif ( str_starts_with( $param, 'page' ) ) {
					$types[] = 'page';
					$named   = true;
				} elseif ( 'attachment' === $param ) {
					$types[] = 'attachment';
					$named   = true;
				}
			}

			if ( ! $named ) {
				return array();
			}
		}

		return $matches ? array_values( array_unique( array_filter( $types ) ) ) : null;
	}

	/**
	 * Whether a top-level field goes into the public index by default: content types,
	 * unless the name marks the field as internal.
	 *
	 * @param array $field ACF field definition.
	 */
	private static function public_default( array $field ): bool {
		return in_array( $field['type'] ?? '', self::CONTENT_TYPES, true )
			&& ! preg_match( self::INTERNAL_NAME_PATTERN, (string) ( $field['name'] ?? '' ) );
	}

	public function extract( WP_Post $post, Document $doc ): void {
		$objects = get_field_objects( $post->ID );
		if ( ! is_array( $objects ) ) {
			return;
		}

		$this->defaults ??= array_map( static fn( $field ) => $field['public'], $this->fields() );

		foreach ( $objects as $object ) {
			$label   = (string) ( $object['label'] ?? $object['name'] );
			$default = $this->defaults[ 'acf:' . $object['name'] ] ?? self::public_default( $object );

			foreach ( $this->flatten_field( (string) $object['name'], $object['value'] ?? null, $object ) as $name => $values ) {
				$text = trim( wp_strip_all_tags( $values[0] ) );
				if ( '' === $text ) {
					continue;
				}

				$public = null === $values[1] ? '' : trim( wp_strip_all_tags( $values[1] ) );
				$doc->add_scoped_field(
					'acf',
					$name,
					mb_substr( $text, 0, self::MAX_VALUE_LENGTH ),
					'' === $public ? null : mb_substr( $public, 0, self::MAX_VALUE_LENGTH ),
					$default
				);

				// Long text is chunked too; only text that is the same in both indexes (not lists of related posts).
				if ( mb_strlen( $text ) >= self::SECTION_MIN_LENGTH && $values[0] === $values[1] ) {
					$doc->add_section( $label, $values[0], $default, 'acf:' . $name );
				}
			}
		}
	}

	/**
	 * Converts an ACF value into scalar leaves using its field definition, whatever the
	 * field's return format: related posts, terms and users become their names, images
	 * and files their descriptive text and dates ISO dates instead of IDs, URLs or site
	 * formats. Repeaters, groups and flexible content use the definitions of their sub
	 * fields; password fields are left out.
	 *
	 * @param string $name  Dotted field path.
	 * @param mixed  $value Field value.
	 * @param array  $field ACF field definition.
	 * @return array<string, array{0: string, 1: string|null}> Path => admin value and public value (null: none).
	 */
	private function flatten_field( string $name, $value, array $field ): array {
		if ( null === $value || false === $value || '' === $value || array() === $value ) {
			return array();
		}

		switch ( $field['type'] ?? '' ) {
			case 'password':
				return array();

			case 'date_picker':
			case 'date_time_picker':
				return self::same( $name, self::iso_date( $value, $field ) );

			case 'post_object':
			case 'relationship':
			case 'page_link':
				return self::leaf( $name, array_map( array( self::class, 'post_label' ), self::items( $value ) ) );

			case 'taxonomy':
				return self::leaf( $name, array_map( array( self::class, 'term_label' ), self::items( $value ) ) );

			case 'user':
				return self::leaf( $name, array_map( array( self::class, 'user_label' ), self::items( $value ) ) );

			case 'image':
			case 'file':
			case 'gallery':
				return self::leaf( $name, array_map( array( self::class, 'attachment_label' ), self::items( $value ) ) );

			case 'repeater':
				$leaves = array();
				foreach ( (array) $value as $index => $row ) {
					$leaves += $this->flatten_row( $name . '.' . $index, $row, (array) ( $field['sub_fields'] ?? array() ) );
				}
				return $leaves;

			case 'flexible_content':
				$layouts = array();
				foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
					$layouts[ (string) ( $layout['name'] ?? '' ) ] = (array) ( $layout['sub_fields'] ?? array() );
				}

				$leaves = array();
				foreach ( (array) $value as $index => $row ) {
					$layout  = is_array( $row ) ? (string) ( $row['acf_fc_layout'] ?? '' ) : '';
					$leaves += $this->flatten_row( $name . '.' . $index, $row, $layouts[ $layout ] ?? array() );
				}
				return $leaves;

			case 'group':
			case 'clone':
				return $this->flatten_row( $name, $value, (array) ( $field['sub_fields'] ?? array() ) );
		}

		return $this->flatten( $name, $value );
	}

	/**
	 * Leaves of a repeater row, flexible content layout or group: sub fields with a
	 * definition use it, anything else is flattened generically.
	 *
	 * @param string $name       Dotted path of the row.
	 * @param mixed  $row        Row values keyed by sub field name.
	 * @param array  $sub_fields Sub field definitions.
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	private function flatten_row( string $name, $row, array $sub_fields ): array {
		if ( ! is_array( $row ) ) {
			return $this->flatten( $name, $row );
		}

		$definitions = array();
		foreach ( $sub_fields as $sub_field ) {
			$definitions[ (string) ( $sub_field['name'] ?? '' ) ] = (array) $sub_field;
		}

		$leaves = array();
		foreach ( $row as $key => $item ) {
			if ( 'acf_fc_layout' === $key ) {
				continue;
			}
			$leaves += isset( $definitions[ $key ] )
				? $this->flatten_field( $name . '.' . $key, $item, $definitions[ $key ] )
				: $this->flatten( $name . '.' . $key, $item );
		}

		return $leaves;
	}

	/**
	 * Converts a value without a known field definition into scalar leaves.
	 *
	 * @param string $name  Dotted field path.
	 * @param mixed  $value Field value.
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	private function flatten( string $name, $value ): array {
		if ( null === $value || false === $value || '' === $value ) {
			return array();
		}

		if ( $value instanceof WP_Post ) {
			return self::leaf( $name, array( self::post_label( $value ) ) );
		}

		if ( $value instanceof WP_Term ) {
			return self::leaf( $name, array( self::term_label( $value ) ) );
		}

		if ( $value instanceof WP_User ) {
			return self::leaf( $name, array( self::user_label( $value ) ) );
		}

		if ( is_scalar( $value ) ) {
			return self::same( $name, (string) $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		// Image/file arrays: the descriptive text is what matters, not the URLs and sizes.
		if ( isset( $value['mime_type'], $value['url'] ) ) {
			return self::leaf( $name, array( self::attachment_label( $value ) ) );
		}

		// User arrays: only the public name, never the email or other account data.
		if ( isset( $value['user_email'] ) || isset( $value['user_nicename'] ) ) {
			return self::leaf( $name, array( self::user_label( $value ) ) );
		}

		// Link fields.
		if ( isset( $value['url'], $value['title'] ) && count( $value ) <= 3 ) {
			return self::same( $name, trim( $value['title'] . ' ' . $value['url'] ) );
		}

		$leaves = array();
		foreach ( $value as $key => $item ) {
			if ( 'acf_fc_layout' === $key ) {
				continue;
			}
			$leaves += $this->flatten( $name . '.' . $key, $item );
		}

		return $leaves;
	}

	/**
	 * A single value or a list of values as a list.
	 *
	 * @param mixed $value Field value.
	 * @return array<int, mixed>
	 */
	private static function items( $value ): array {
		return is_array( $value ) && array_is_list( $value ) ? $value : array( $value );
	}

	/**
	 * One leaf with the same value in both indexes.
	 *
	 * @param string $name  Dotted field path.
	 * @param string $value Value.
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	private static function same( string $name, string $value ): array {
		return '' === trim( $value ) ? array() : array( $name => array( $value, $value ) );
	}

	/**
	 * One leaf from labels: every admin label joined for the admin index, the labels that
	 * may be public joined for the public index (null when there are none).
	 *
	 * @param string                                       $name   Dotted field path.
	 * @param array<int, array{0: string, 1: string|null}> $labels Admin and public label of each item.
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	private static function leaf( string $name, array $labels ): array {
		$join = static function ( array $values ): string {
			$values = array_filter( array_map( 'strval', $values ), static fn( $value ) => '' !== trim( $value ) );

			return implode( '; ', array_values( array_unique( $values ) ) );
		};

		$admin  = $join( array_column( $labels, 0 ) );
		$public = $join( array_column( $labels, 1 ) );

		return '' === $admin ? array() : array( $name => array( $admin, '' === $public ? null : $public ) );
	}

	/**
	 * Labels of a related post: its title in the admin index (with the status when it is not
	 * published), and in the public index only when it is published and not password
	 * protected. URLs (page_link) are kept as they are.
	 *
	 * @param mixed $item WP_Post, post ID or URL.
	 * @return array{0: string, 1: string|null}
	 */
	private static function post_label( $item ): array {
		if ( is_string( $item ) && ! is_numeric( $item ) ) {
			return array( $item, $item );
		}

		$post = $item instanceof WP_Post ? $item : get_post( (int) $item );
		if ( ! $post instanceof WP_Post || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return array( '', null );
		}

		$title     = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$is_public = 'publish' === $post->post_status && '' === (string) $post->post_password;

		return array( $is_public ? $title : $title . ' (' . $post->post_status . ')', $is_public ? $title : null );
	}

	/**
	 * Labels of a term: its name, in the public index only for viewable taxonomies.
	 *
	 * @param mixed $item WP_Term or term ID.
	 * @return array{0: string, 1: string|null}
	 */
	private static function term_label( $item ): array {
		$term = $item instanceof WP_Term ? $item : get_term( (int) $item );
		if ( ! $term instanceof WP_Term ) {
			return array( '', null );
		}

		$name = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return array( $name, is_taxonomy_viewable( $term->taxonomy ) ? $name : null );
	}

	/**
	 * Labels of a user: the display name; never the login, email or other account data.
	 *
	 * @param mixed $item WP_User, user array or user ID.
	 * @return array{0: string, 1: string|null}
	 */
	private static function user_label( $item ): array {
		if ( is_array( $item ) ) {
			$item = (int) ( $item['ID'] ?? 0 );
		}

		$user = $item instanceof WP_User ? $item : get_userdata( (int) $item );
		$name = $user instanceof WP_User ? (string) $user->display_name : '';

		return array( $name, $name );
	}

	/**
	 * Labels of an image or file: title, alt text and caption.
	 *
	 * @param mixed $item Attachment array, attachment ID or URL (URLs have no text).
	 * @return array{0: string, 1: string|null}
	 */
	private static function attachment_label( $item ): array {
		if ( is_array( $item ) ) {
			$parts = array( $item['title'] ?? '', $item['alt'] ?? '', $item['caption'] ?? '' );
		} elseif ( is_numeric( $item ) && get_post( (int) $item ) instanceof WP_Post ) {
			$attachment = get_post( (int) $item );
			$parts      = array( $attachment->post_title, (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ), $attachment->post_excerpt );
		} else {
			return array( '', null );
		}

		$text = trim( implode( ' ', array_unique( array_filter( array_map( 'trim', array_map( 'strval', $parts ) ) ) ) ) );

		return array( $text, $text );
	}

	/**
	 * A date or date and time field as an ISO date ("2024-04-29", "2024-04-29 18:00"),
	 * whatever display format the field returns. Falls back to the value as it is.
	 *
	 * @param mixed $value Formatted value.
	 * @param array $field ACF field definition.
	 */
	private static function iso_date( $value, array $field ): string {
		$is_time = 'date_time_picker' === ( $field['type'] ?? '' );
		$formats = array_filter( array( (string) ( $field['return_format'] ?? '' ), $is_time ? 'Y-m-d H:i:s' : 'Ymd', 'Y-m-d' ) );

		foreach ( $formats as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, (string) $value );
			if ( $date ) {
				return $date->format( $is_time ? 'Y-m-d H:i' : 'Y-m-d' );
			}
		}

		return (string) $value;
	}
}
