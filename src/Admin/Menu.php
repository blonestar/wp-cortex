<?php
/**
 * Admin menu and asset loading.
 *
 * @package WPCortex
 */

namespace WPCortex\Admin;

use WPCortex\Chat\IssueReportStore;
use WPCortex\Chat\VisitorChatStore;
use WPCortex\Plugin;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Cortex admin screens.
 */
final class Menu {

	public const SLUG_SETTINGS = 'wp-cortex';
	public const SLUG_INDEXING = 'wp-cortex-indexing';
	public const SLUG_SKILLS   = 'wp-cortex-skills';
	public const SLUG_VISITORS = 'wp-cortex-visitor-chats';
	public const SLUG_REPORTS  = 'wp-cortex-issue-reports';

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
	 * Hook suffix of the Skills screen.
	 *
	 * @var string
	 */
	private string $skills_hook = '';

	/**
	 * Hook suffix of the Visitor chats screen.
	 *
	 * @var string
	 */
	private string $visitors_hook = '';

	/**
	 * Hook suffix of the Issue reports screen.
	 *
	 * @var string
	 */
	private string $reports_hook = '';

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
		// Indexing is the landing screen; Settings is listed last.
		$this->indexing_hook = (string) add_menu_page(
			__( 'Cortex', 'wp-cortex' ),
			__( 'Cortex', 'wp-cortex' ),
			'manage_options',
			self::SLUG_INDEXING,
			array( new IndexingPage(), 'render' ),
			'dashicons-database',
			81
		);

		// Same slug as the parent: only renames the first submenu item. Passing a callback
		// here would hook the render a second time and print the page twice.
		add_submenu_page(
			self::SLUG_INDEXING,
			__( 'Cortex Indexing', 'wp-cortex' ),
			__( 'Indexing', 'wp-cortex' ),
			'manage_options',
			self::SLUG_INDEXING
		);

		if ( Settings::skills_enabled() ) {
			$this->skills_hook = (string) add_submenu_page(
				self::SLUG_INDEXING,
				__( 'Cortex Skills', 'wp-cortex' ),
				__( 'Skills', 'wp-cortex' ),
				'manage_options',
				self::SLUG_SKILLS,
				array( new SkillsPage(), 'render' )
			);
		}

		$can = current_user_can( 'manage_options' );

		$this->visitors_hook = (string) add_submenu_page(
			self::SLUG_INDEXING,
			__( 'Cortex Visitor Chats', 'wp-cortex' ),
			self::count_label( __( 'Visitor chats', 'wp-cortex' ), $can ? ( new VisitorChatStore() )->unread_count() : 0 ),
			'manage_options',
			self::SLUG_VISITORS,
			array( new VisitorChatsPage(), 'render' )
		);

		$this->reports_hook = (string) add_submenu_page(
			self::SLUG_INDEXING,
			__( 'Cortex Issue Reports', 'wp-cortex' ),
			self::count_label( __( 'Issue reports', 'wp-cortex' ), $can ? ( new IssueReportStore() )->open_count() : 0 ),
			'manage_options',
			self::SLUG_REPORTS,
			array( new IssueReportsPage(), 'render' )
		);

		$this->settings_hook = (string) add_submenu_page(
			self::SLUG_INDEXING,
			__( 'Cortex Settings', 'wp-cortex' ),
			__( 'Settings', 'wp-cortex' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( new SettingsPage(), 'render' )
		);
	}

	/**
	 * Menu label with a count bubble when the count is positive.
	 *
	 * @param string $label Label.
	 * @param int    $count Count.
	 */
	private static function count_label( string $label, int $count ): string {
		if ( $count < 1 ) {
			return $label;
		}

		return $label . sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>', $count, number_format_i18n( $count ) );
	}

	/**
	 * Enqueues assets on the plugin screens only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue( string $hook_suffix ): void {
		$is_settings = '' !== $this->settings_hook && $hook_suffix === $this->settings_hook;
		$is_indexing = '' !== $this->indexing_hook && $hook_suffix === $this->indexing_hook;
		$is_skills   = '' !== $this->skills_hook && $hook_suffix === $this->skills_hook;
		$is_visitors = '' !== $this->visitors_hook && $hook_suffix === $this->visitors_hook;
		$is_reports  = '' !== $this->reports_hook && $hook_suffix === $this->reports_hook;

		if ( ! $is_settings && ! $is_indexing && ! $is_skills && ! $is_visitors && ! $is_reports ) {
			return;
		}

		wp_enqueue_style( 'wp-cortex-admin', WP_CORTEX_URL . 'assets/css/admin.css', array(), Plugin::asset_version( 'assets/css/admin.css' ) );

		if ( $is_settings ) {
			// Styles the visitor chat preview on the Appearance section.
			wp_enqueue_style( 'wp-cortex-public-chat', WP_CORTEX_URL . 'assets/css/public-chat.css', array(), Plugin::asset_version( 'assets/css/public-chat.css' ) );
			wp_enqueue_script( 'wp-cortex-settings', WP_CORTEX_URL . 'assets/js/settings.js', array( 'wp-api-fetch', 'wp-i18n' ), Plugin::asset_version( 'assets/js/settings.js' ), true );
			wp_set_script_translations( 'wp-cortex-settings', 'wp-cortex' );
			// Data directory toggle and public access check on Advanced > Storage.
			wp_enqueue_script( 'wp-cortex-storage', WP_CORTEX_URL . 'assets/js/storage.js', array( 'wp-api-fetch', 'wp-i18n' ), Plugin::asset_version( 'assets/js/storage.js' ), true );
			wp_set_script_translations( 'wp-cortex-storage', 'wp-cortex' );
		}

		if ( $is_indexing ) {
			wp_enqueue_script( 'wp-cortex-indexing', WP_CORTEX_URL . 'assets/js/indexing.js', array( 'wp-api-fetch', 'wp-i18n' ), Plugin::asset_version( 'assets/js/indexing.js' ), true );
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

		if ( $is_skills ) {
			wp_enqueue_script( 'wp-cortex-skills', WP_CORTEX_URL . 'assets/js/skills.js', array( 'wp-api-fetch', 'wp-i18n' ), Plugin::asset_version( 'assets/js/skills.js' ), true );
			wp_set_script_translations( 'wp-cortex-skills', 'wp-cortex' );
		}

		if ( $is_visitors ) {
			wp_enqueue_script( 'wp-cortex-markdown', WP_CORTEX_URL . 'assets/js/chat-markdown.js', array(), Plugin::asset_version( 'assets/js/chat-markdown.js' ), true );
			wp_enqueue_script( 'wp-cortex-visitor-chats', WP_CORTEX_URL . 'assets/js/visitor-chats.js', array( 'wp-api-fetch', 'wp-i18n', 'wp-cortex-markdown' ), Plugin::asset_version( 'assets/js/visitor-chats.js' ), true );
			wp_set_script_translations( 'wp-cortex-visitor-chats', 'wp-cortex' );
			wp_add_inline_script(
				'wp-cortex-visitor-chats',
				'window.wpCortexVisitorChats = ' . wp_json_encode(
					array(
						'forwardTo'  => (string) get_option( 'admin_email' ),
						'reportsUrl' => admin_url( 'admin.php?page=' . self::SLUG_REPORTS ),
					)
				) . ';',
				'before'
			);
		}

		if ( $is_reports ) {
			wp_enqueue_script( 'wp-cortex-issue-reports', WP_CORTEX_URL . 'assets/js/issue-reports.js', array( 'wp-api-fetch', 'wp-i18n' ), Plugin::asset_version( 'assets/js/issue-reports.js' ), true );
			wp_set_script_translations( 'wp-cortex-issue-reports', 'wp-cortex' );
			wp_add_inline_script(
				'wp-cortex-issue-reports',
				'window.wpCortexIssueReports = ' . wp_json_encode(
					array(
						'chatUrl'    => admin_url( 'admin.php?page=' . self::SLUG_VISITORS ),
						'categories' => IssueReportStore::category_labels(),
						'statuses'   => IssueReportStore::status_labels(),
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
