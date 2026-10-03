<?php
/**
 * Admin screens the chat assistant may open, taken from the admin menu.
 *
 * @package WPCortex
 */

namespace WPCortex\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and validates the list of admin screens for the open_admin_page chat tool.
 *
 * The list is built on admin screens (where the menu exists) and sent with each chat
 * message, because the admin menu is not available in REST requests.
 */
final class AdminPages {

	public const MAX_PAGES = 250;

	public const MAX_TABS = 50;

	public const MAX_TAB_LENGTH = 100;

	/**
	 * Query parameters that can trigger an action on a GET request (compared case-insensitively).
	 */
	private const BLOCKED_ARGS = array( 'action', 'action2', 'doaction', '_wpnonce', 'download', 'activate', 'deactivate', 'delete', 'trash', 'untrash', 'key' );

	/**
	 * Admin screens from the current user's admin menu.
	 *
	 * @return array<int, array{path: string, label: string}>
	 */
	public static function from_menu(): array {
		global $menu, $submenu;

		if ( ! is_array( $menu ) ) {
			return array();
		}

		$pages = array();

		foreach ( $menu as $item ) {
			$parent_slug  = (string) ( $item[2] ?? '' );
			$parent_label = self::clean_label( (string) ( $item[0] ?? '' ) );

			if ( '' === $parent_slug || '' === $parent_label || ! current_user_can( (string) ( $item[1] ?? 'manage_options' ) ) ) {
				continue;
			}

			$children = isset( $submenu[ $parent_slug ] ) && is_array( $submenu[ $parent_slug ] ) ? $submenu[ $parent_slug ] : array();

			if ( ! $children ) {
				$pages[] = array(
					'path'  => self::top_level_path( $parent_slug ),
					'label' => $parent_label,
				);
				continue;
			}

			foreach ( $children as $child ) {
				$slug  = (string) ( $child[2] ?? '' );
				$label = self::clean_label( (string) ( $child[0] ?? '' ) );

				if ( '' === $slug || '' === $label || ! current_user_can( (string) ( $child[1] ?? 'manage_options' ) ) ) {
					continue;
				}

				$pages[] = array(
					'path'  => self::submenu_path( $slug, $parent_slug ),
					'label' => $parent_label === $label ? $label : $parent_label . ' › ' . $label,
				);
			}
		}

		return self::sanitize( $pages );
	}

	/**
	 * Validates a list of admin screens (for example from a request).
	 *
	 * Only relative wp-admin paths are kept, so a screen can never point off the admin.
	 *
	 * @param mixed $pages Raw list of {path, label} items.
	 * @return array<int, array{path: string, label: string}>
	 */
	public static function sanitize( $pages ): array {
		if ( ! is_array( $pages ) ) {
			return array();
		}

		$clean = array();

		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}

			$path  = trim( (string) ( $page['path'] ?? '' ) );
			$label = sanitize_text_field( (string) ( $page['label'] ?? '' ) );

			if ( '' === $label || isset( $clean[ $path ] ) || ! self::is_valid_path( $path ) ) {
				continue;
			}

			$clean[ $path ] = array(
				'path'  => $path,
				'label' => $label,
			);

			if ( count( $clean ) >= self::MAX_PAGES ) {
				break;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Validates a list of tab labels collected from the current admin screen.
	 *
	 * Empty labels and labels longer than MAX_TAB_LENGTH are dropped; duplicates are removed.
	 *
	 * @param mixed $tabs Raw list of labels.
	 * @return string[]
	 */
	public static function sanitize_tabs( $tabs ): array {
		if ( ! is_array( $tabs ) ) {
			return array();
		}

		$clean = array();

		foreach ( $tabs as $tab ) {
			if ( ! is_string( $tab ) ) {
				continue;
			}

			$label = sanitize_text_field( $tab );

			if ( '' === $label || mb_strlen( $label ) > self::MAX_TAB_LENGTH || isset( $clean[ $label ] ) ) {
				continue;
			}

			$clean[ $label ] = $label;

			if ( count( $clean ) >= self::MAX_TABS ) {
				break;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Whether a path is a relative wp-admin screen such as "options-permalink.php" or
	 * "admin.php?page=foo". Nonces and parameters that trigger actions are rejected.
	 *
	 * @param string $path Path relative to wp-admin.
	 */
	public static function is_valid_path( string $path ): bool {
		if ( ! preg_match( '/^[A-Za-z0-9_-]+\.php(\?[A-Za-z0-9_.~%+=&\[\]\/-]*)?$/D', $path ) ) {
			return false;
		}

		$query = (string) wp_parse_url( $path, PHP_URL_QUERY );
		$args  = array();

		wp_parse_str( $query, $args );

		return ! array_intersect( array_map( 'strtolower', array_keys( $args ) ), self::BLOCKED_ARGS );
	}

	/**
	 * Menu label without counters and markup ("Plugins 3" becomes "Plugins").
	 *
	 * @param string $label Raw menu label.
	 */
	private static function clean_label( string $label ): string {
		$label = (string) preg_replace( '#<span[^>]*>.*?</span>#s', '', $label );

		return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $label ) ) );
	}

	/**
	 * Path of a top-level menu item without submenu.
	 *
	 * @param string $slug Menu slug.
	 */
	private static function top_level_path( string $slug ): string {
		if ( get_plugin_page_hook( $slug, 'admin.php' ) ) {
			return 'admin.php?page=' . rawurlencode( $slug );
		}

		return $slug;
	}

	/**
	 * Path of a submenu item, following the URL rules of wp-admin/menu-header.php.
	 *
	 * @param string $slug        Submenu slug.
	 * @param string $parent_slug Parent menu slug.
	 */
	private static function submenu_path( string $slug, string $parent_slug ): string {
		if ( ! get_plugin_page_hook( $slug, $parent_slug ) ) {
			return $slug;
		}

		$parent_file = (string) strtok( $parent_slug, '?' );

		if ( 'admin.php' !== $parent_file && file_exists( ABSPATH . 'wp-admin/' . $parent_file ) ) {
			return add_query_arg( 'page', rawurlencode( $slug ), $parent_slug );
		}

		return 'admin.php?page=' . rawurlencode( $slug );
	}
}
