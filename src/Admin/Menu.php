<?php
/**
 * Admin menu and asset loading.
 *
 * @package WPCortex
 */

namespace WPCortex\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Cortex admin screens.
 */
final class Menu {

	public const SLUG_SETTINGS = 'wp-cortex';
	public const SLUG_INDEXING = 'wp-cortex-indexing';

	/**
	 * Hook suffix of the Settings screen.
	 *
	 * @var string
	 */
	private string $settings_hook = '';

	/**
	 * Hook suffix of the Indexing screen.
	 *
	 * @var string
	 */
	private string $indexing_hook = '';

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( SettingsPage::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WP_CORTEX_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds the top-level menu and its submenus.
	 */
	public function add_menu(): void {
		$this->settings_hook = (string) add_menu_page(
			__( 'Cortex', 'wp-cortex' ),
			__( 'Cortex', 'wp-cortex' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( new SettingsPage(), 'render' ),
			'dashicons-database',
			81
		);

		// Same slug as the parent: only renames the first submenu item. Passing a callback
		// here would hook the render a second time and print the page twice.
		add_submenu_page(
			self::SLUG_SETTINGS,
			__( 'Cortex Settings', 'wp-cortex' ),
			__( 'Settings', 'wp-cortex' ),
			'manage_options',
			self::SLUG_SETTINGS
		);

		$this->indexing_hook = (string) add_submenu_page(
			self::SLUG_SETTINGS,
			__( 'Cortex Indexing', 'wp-cortex' ),
			__( 'Indexing', 'wp-cortex' ),
			'manage_options',
			self::SLUG_INDEXING,
			array( new IndexingPage(), 'render' )
		);
	}

	/**
	 * Enqueues assets on the plugin screens only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue( string $hook_suffix ): void {
		$is_settings = '' !== $this->settings_hook && $hook_suffix === $this->settings_hook;
		$is_indexing = '' !== $this->indexing_hook && $hook_suffix === $this->indexing_hook;

		if ( ! $is_settings && ! $is_indexing ) {
			return;
		}

		wp_enqueue_style( 'wp-cortex-admin', WP_CORTEX_URL . 'assets/css/admin.css', array(), WP_CORTEX_VERSION );

		if ( $is_settings ) {
			wp_enqueue_script( 'wp-cortex-settings', WP_CORTEX_URL . 'assets/js/settings.js', array( 'wp-api-fetch', 'wp-i18n' ), WP_CORTEX_VERSION, true );
			wp_set_script_translations( 'wp-cortex-settings', 'wp-cortex' );
		}

		if ( $is_indexing ) {
			wp_enqueue_script( 'wp-cortex-indexing', WP_CORTEX_URL . 'assets/js/indexing.js', array( 'wp-api-fetch', 'wp-i18n' ), WP_CORTEX_VERSION, true );
			wp_set_script_translations( 'wp-cortex-indexing', 'wp-cortex' );
			wp_add_inline_script(
				'wp-cortex-indexing',
				'window.wpCortexIndexing = ' . wp_json_encode(
					array(
						'restPath'    => '/wp-cortex/v1/index',
						'editPostUrl' => admin_url( 'post.php?action=edit&post=' ),
					)
				) . ';',
				'before'
			);
		}
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( array $links ): array {
		$url = admin_url( 'admin.php?page=' . self::SLUG_SETTINGS );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wp-cortex' ) . '</a>' );

		return $links;
	}
}
