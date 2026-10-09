<?php
/**
 * Settings screen (Settings API).
 *
 * @package WPCortex
 */

namespace WPCortex\Admin;

use WPCortex\Chat\ChatAgent;
use WPCortex\Chat\ClientIp;
use WPCortex\Chat\IssueReportMailer;
use WPCortex\Chat\ModelCatalog;
use WPCortex\Chat\PublicChatAgent;
use WPCortex\Chat\Reasoning;
use WPCortex\Chat\VisitorChatStore;
use WPCortex\Chat\VisitorChatSummarizer;
use WPCortex\Chat\VisitorImages;
use WPCortex\Embeddings\OpenAIEmbeddings;
use WPCortex\Frontend\ChatAppearance;
use WPCortex\Indexing\FieldPolicy;
use WPCortex\Integrations\Dispatcher;
use WPCortex\Integrations\Integration;
use WPCortex\Integrations\IntegrationRegistry;
use WPCortex\Leads\Attribution;
use WPCortex\Settings;
use WPCortex\Storage\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the plugin settings.
 */
final class SettingsPage {

	private const GROUP = 'wp_cortex';

	/**
	 * Post types that never make sense to index.
	 */
	private const EXCLUDED_POST_TYPES = array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'wp_font_family', 'wp_font_face' );

	/**
	 * Registers the setting. Hooked to admin_init.
	 */
	public static function register_settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Tabs of the settings screen: ID => label, dashicon and the sections it holds.
	 *
	 * Each section (keyed by ID) is a heading, an intro, the method rendering its fields and
	 * optionally `true` when it is read-only (hides the save bar). A tab with more than one
	 * section shows them as vertical sub-tabs. A read-only tab has a `render` callback
	 * instead of sections and hides the save bar.
	 *
	 * @return array<string, array{label: string, icon: string, sections?: array<string, array{0: string, 1: string, 2: callable, 3?: bool}>, render?: callable}>
	 */
	private function tabs(): array {
		return array(
			'indexing'   => array(
				'label'    => __( 'Indexing', 'wp-cortex' ),
				'icon'     => 'dashicons-database',
				'sections' => array(
					'status'     => array( __( 'Status & stats', 'wp-cortex' ), __( 'Sync or rebuild the index and see what the public and admin indexes contain.', 'wp-cortex' ), array( new IndexStatus(), 'render' ), true ),
					'admin'      => array( __( 'Admin index', 'wp-cortex' ), __( 'Content and fields the admin chat, the abilities and WP-CLI search. Visitors never see this index.', 'wp-cortex' ), array( $this, 'fields_admin_index' ) ),
					'public'     => array( __( 'Public index', 'wp-cortex' ), __( 'Content and fields the visitor chat answers from. Everything in this index can be shown to any site visitor.', 'wp-cortex' ), array( $this, 'fields_public_index' ) ),
					'sources'    => array( __( 'Data sources & sync', 'wp-cortex' ), __( 'Extra fields from plugins and post meta, and whether the index stays in sync automatically. Which index each field goes into is chosen under Admin index and Public index.', 'wp-cortex' ), array( $this, 'fields_sources' ) ),
					'chunking'   => array( __( 'Chunking & batching', 'wp-cortex' ), __( 'How content is split into chunks for search and how many posts are processed per request.', 'wp-cortex' ), array( $this, 'fields_chunking' ) ),
					'embeddings' => array( __( 'Embeddings', 'wp-cortex' ), __( 'Vector embeddings power semantic search. They are created with the OpenAI API.', 'wp-cortex' ), array( $this, 'fields_embeddings' ) ),
				),
			),
			'chat'       => array(
				'label'    => __( 'Admin chat', 'wp-cortex' ),
				'icon'     => 'dashicons-format-chat',
				'sections' => array(
					'assistant' => array( __( 'Admin chat assistant', 'wp-cortex' ), __( 'The assistant administrators use to search and navigate the site.', 'wp-cortex' ), array( $this, 'fields_chat' ) ),
				),
			),
			'visitors'   => array(
				'label'    => __( 'Visitor chat', 'wp-cortex' ),
				'icon'     => 'dashicons-groups',
				'sections' => array(
					'general'    => array( __( 'General', 'wp-cortex' ), __( 'A chat assistant for site visitors that answers only from the public index.', 'wp-cortex' ), array( $this, 'fields_public_chat' ) ),
					'assistant'  => array( __( 'AI assistant', 'wp-cortex' ), __( 'The AI provider, model and instructions the visitor chat uses, separate from the admin chat.', 'wp-cortex' ), array( $this, 'fields_public_chat_assistant' ) ),
					'appearance' => array( __( 'Appearance', 'wp-cortex' ), __( 'How the visitor chat looks on the site. The preview updates as you change the settings; the site after saving.', 'wp-cortex' ), array( $this, 'fields_public_chat_appearance' ) ),
					'privacy'    => array( __( 'Conversations & privacy', 'wp-cortex' ), __( 'What is stored about visitor conversations and for how long.', 'wp-cortex' ), array( $this, 'fields_public_chat_privacy' ) ),
					'actions'    => array( __( 'Assistant actions', 'wp-cortex' ), __( 'What the visitor chat may do beyond answering questions.', 'wp-cortex' ), array( $this, 'fields_public_chat_actions' ) ),
					'summary'    => array( __( 'Conversation summaries', 'wp-cortex' ), __( 'How the AI summary of a visitor conversation is written (Summarize under Cortex > Visitor chats and forwarded emails).', 'wp-cortex' ), array( $this, 'fields_public_chat_summary' ) ),
				),
			),
			'leads'      => array(
				'label'    => __( 'Leads', 'wp-cortex' ),
				'icon'     => 'dashicons-businessperson',
				'sections' => array(
					'general'      => array( __( 'Leads', 'wp-cortex' ), __( 'Visitors who leave an email address, phone number, postal address or website in the visitor chat become leads you can rate with AI, track and export.', 'wp-cortex' ), array( $this, 'fields_leads' ) ),
					'attribution'  => array( __( 'Attribution', 'wp-cortex' ), __( 'How visitors who chat found the site, so Cortex > Leads can show which channels, campaigns and pages bring inquiries.', 'wp-cortex' ), array( $this, 'fields_leads_attribution' ) ),
					'integrations' => array( __( 'Integrations', 'wp-cortex' ), __( 'Send new leads and their changes to a CRM or another tool, with the contact details, AI rating and every campaign parameter and ad click ID. Leads are sent in the background, a failed delivery is retried twice.', 'wp-cortex' ), array( $this, 'fields_integrations' ) ),
				),
			),
			'tools'      => array(
				'label'    => __( 'Chat tools', 'wp-cortex' ),
				'icon'     => 'dashicons-hammer',
				'sections' => array(
					'admin'     => array( __( 'Admin chat tools', 'wp-cortex' ), __( 'Tools the admin chat can use: those of Cortex and those added by the theme or plugins. Abilities are also available outside the chat (for example to MCP clients); chat tools only work inside the chat panel. A switched-off tool is never offered. The limit caps how often the assistant may call a tool for one message (0 = no limit).', 'wp-cortex' ), array( $this, 'fields_tools_admin' ) ),
					'abilities' => array( __( 'WordPress abilities', 'wp-cortex' ), __( 'Abilities registered by WordPress and other plugins that the admin chat may use. Each ability still checks the permissions of the user. Abilities not marked as read-only may change the site: the assistant only proposes them, and they run after you confirm them in the chat.', 'wp-cortex' ), array( $this, 'fields_abilities' ) ),
					'public'    => array( __( 'Visitor chat tools', 'wp-cortex' ), __( 'Tools the visitor chat can use. They only read the public index; abilities and admin tools are never offered to visitors.', 'wp-cortex' ), array( $this, 'fields_tools_public' ) ),
				),
			),
			'skills'     => array(
				'label'    => __( 'Skills', 'wp-cortex' ),
				'icon'     => 'dashicons-welcome-learn-more',
				'sections' => $this->skills_sections(),
			),
			'advanced'   => array(
				'label'    => __( 'Advanced', 'wp-cortex' ),
				'icon'     => 'dashicons-admin-tools',
				'sections' => array(
					'storage'   => array( __( 'Storage', 'wp-cortex' ), __( 'Where the index databases and visitor images are stored and whether they can be downloaded from the site.', 'wp-cortex' ), array( $this, 'fields_storage' ) ),
					'uninstall' => array( __( 'Uninstall', 'wp-cortex' ), __( 'What happens to the index when the plugin is deleted.', 'wp-cortex' ), array( $this, 'fields_uninstall' ) ),
				),
			),
			'changelog'  => array(
				'label'  => __( 'Changelog', 'wp-cortex' ),
				'icon'   => 'dashicons-backup',
				'render' => array( new Changelog(), 'render' ),
			),
		);
	}

	/**
	 * Sections of the Skills tab. The saved skills list is shown only while skills are on;
	 * it saves through the REST API, so it is read-only for the settings form.
	 *
	 * @return array<string, array>
	 */
	private function skills_sections(): array {
		$sections = array();

		if ( Settings::skills_enabled() ) {
			$sections['saved'] = array( __( 'Saved skills', 'wp-cortex' ), __( 'Procedures the chat assistant follows when a request matches them, for example how to reach a settings tab. The assistant can propose a skill after a task; it is only saved when you confirm it in the chat. Inactive skills are not offered to the assistant.', 'wp-cortex' ), array( new SkillsPage(), 'render' ), true );
		}

		$sections['settings'] = array( __( 'Settings', 'wp-cortex' ), __( 'Whether the admin chat uses skills and how it writes the skills it proposes.', 'wp-cortex' ), array( $this, 'fields_skills' ) );

		return $sections;
	}

	/**
	 * Whether the save bar is hidden for a tab and section: read-only tabs and sections have nothing to save.
	 *
	 * @param array  $tab     Tab definition.
	 * @param string $section Requested section ID; the first section when unknown.
	 */
	private function is_read_only( array $tab, string $section ): bool {
		if ( isset( $tab['render'] ) ) {
			return true;
		}

		if ( ! isset( $tab['sections'][ $section ] ) ) {
			$section = (string) array_key_first( $tab['sections'] );
		}

		return ! empty( $tab['sections'][ $section ][3] );
	}

	/**
	 * URL of a tab section of the settings screen.
	 *
	 * @param string $tab     Tab ID.
	 * @param string $section Section ID.
	 */
	public static function section_url( string $tab, string $section ): string {
		return add_query_arg(
			array(
				'tab'     => $tab,
				'section' => $section,
			),
			admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS )
		);
	}

	/**
	 * URL of the Storage section (data directory and public access check).
	 */
	public static function storage_url(): string {
		return self::section_url( 'advanced', 'storage' );
	}

	/**
	 * URL of the Status & stats section (index runs and stats).
	 */
	public static function status_url(): string {
		return self::section_url( 'indexing', 'status' );
	}

	/**
	 * Every tab and section of the settings screen as an admin screen the chat assistant
	 * can open directly (open_admin_page), for example "Cortex › Settings › Visitor chat › Appearance".
	 *
	 * @return array<int, array{path: string, label: string}>
	 */
	public function chat_screens(): array {
		$base    = 'admin.php?page=' . Menu::SLUG_SETTINGS;
		$prefix  = __( 'Cortex', 'wp-cortex' ) . ' › ' . __( 'Settings', 'wp-cortex' ) . ' › ';
		$screens = array();

		foreach ( $this->tabs() as $id => $tab ) {
			$screens[] = array(
				'path'  => $base . '&tab=' . $id,
				'label' => $prefix . $tab['label'],
			);

			if ( empty( $tab['sections'] ) || count( $tab['sections'] ) < 2 ) {
				continue;
			}

			foreach ( $tab['sections'] as $section_id => $section ) {
				$screens[] = array(
					'path'  => $base . '&tab=' . $id . '&section=' . $section_id,
					'label' => $prefix . $tab['label'] . ' › ' . $section[0],
				);
			}
		}

		return $screens;
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs   = $this->tabs();
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects the visible tab.
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = (string) array_key_first( $tabs );
		}
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects the visible section.

		echo '<div class="wrap wp-cortex-wrap wp-cortex-settings">';
		echo '<h1>' . esc_html__( 'Cortex Settings', 'wp-cortex' ) . '</h1>';
		echo '<p class="wp-cortex-intro">' . esc_html__( 'Configure what Cortex indexes, how it builds embeddings and how the admin and visitor chat assistants behave.', 'wp-cortex' ) . '</p>';
		settings_errors();

		echo '<nav class="nav-tab-wrapper wp-cortex-tabs" role="tablist" aria-label="' . esc_attr__( 'Settings sections', 'wp-cortex' ) . '">';
		foreach ( $tabs as $id => $tab ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s" id="wp-cortex-tab-%3$s" role="tab" aria-controls="wp-cortex-panel-%3$s" aria-selected="%4$s" data-tab="%3$s"%7$s><span class="dashicons %5$s" aria-hidden="true"></span>%6$s</a>',
				esc_url( add_query_arg( 'tab', $id, admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS ) ) ),
				$active === $id ? ' nav-tab-active' : '',
				esc_attr( $id ),
				$active === $id ? 'true' : 'false',
				esc_attr( $tab['icon'] ),
				esc_html( $tab['label'] ),
				isset( $tab['render'] ) ? ' data-read-only="1"' : ''
			);
		}
		echo '</nav>';

		// All tabs live in one form so saving keeps the values of the hidden tabs.
		echo '<form action="options.php" method="post" class="wp-cortex-settings-form">';
		settings_fields( self::GROUP );

		foreach ( $tabs as $id => $tab ) {
			printf(
				'<div class="wp-cortex-tab-panel" id="wp-cortex-panel-%1$s" role="tabpanel" aria-labelledby="wp-cortex-tab-%1$s" %2$s>',
				esc_attr( $id ),
				$active === $id ? '' : 'hidden'
			);

			if ( 'indexing' === $id ) {
				$this->reindex_notice();
			}

			if ( isset( $tab['render'] ) ) {
				call_user_func( $tab['render'] );
			} else {
				$this->render_sections( $id, $tab['sections'], $active === $id ? $section : '' );
			}

			echo '</div>';
		}

		printf( '<div class="wp-cortex-settings-submit" %s>', $this->is_read_only( $tabs[ $active ], $section ) ? 'hidden' : '' );
		submit_button( __( 'Save settings', 'wp-cortex' ), 'primary', 'submit', false );
		echo '</div>';
		echo '</form>';

		if ( Settings::skills_enabled() ) {
			( new SkillsPage() )->render_form();
		}

		echo '</div>';
	}

	/**
	 * Renders the sections of a tab: one card, or vertical sub-tabs when there are several.
	 *
	 * @param string $tab      Tab ID.
	 * @param array  $sections Sections keyed by ID (heading, intro, fields callback).
	 * @param string $active   Requested section ID; the first section when unknown.
	 */
	private function render_sections( string $tab, array $sections, string $active ): void {
		if ( ! isset( $sections[ $active ] ) ) {
			$active = (string) array_key_first( $sections );
		}

		$nested = count( $sections ) > 1;

		if ( $nested ) {
			echo '<div class="wp-cortex-subtabs-layout">';
			echo '<nav class="wp-cortex-subtabs" role="tablist" aria-orientation="vertical">';
			foreach ( $sections as $id => $section ) {
				printf(
					'<a href="%1$s" class="wp-cortex-subtab%2$s" id="wp-cortex-subtab-%3$s-%4$s" role="tab" aria-controls="wp-cortex-section-%3$s-%4$s" aria-selected="%5$s" data-section="%4$s"%7$s>%6$s</a>',
					esc_url( add_query_arg( array( 'tab' => $tab, 'section' => $id ), admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS ) ) ),
					$active === $id ? ' is-active' : '',
					esc_attr( $tab ),
					esc_attr( $id ),
					$active === $id ? 'true' : 'false',
					esc_html( $section[0] ),
					empty( $section[3] ) ? '' : ' data-read-only="1"'
				);
			}
			echo '</nav><div class="wp-cortex-subtab-panels">';
		}

		foreach ( $sections as $id => $section ) {
			if ( $nested ) {
				printf(
					'<div class="wp-cortex-card wp-cortex-settings-card wp-cortex-subtab-panel" id="wp-cortex-section-%1$s-%2$s" role="tabpanel" aria-labelledby="wp-cortex-subtab-%1$s-%2$s" %3$s>',
					esc_attr( $tab ),
					esc_attr( $id ),
					$active === $id ? '' : 'hidden'
				);
			} else {
				echo '<div class="wp-cortex-card wp-cortex-settings-card">';
			}
			echo '<h2>' . esc_html( $section[0] ) . '</h2>';
			echo '<p class="wp-cortex-section-intro">' . esc_html( $section[1] ) . '</p>';
			call_user_func( $section[2] );
			echo '</div>';
		}

		if ( $nested ) {
			echo '</div></div>';
		}
	}

	/**
	 * Notice that index-shaping settings need a rebuild.
	 */
	private function reindex_notice(): void {
		echo '<div class="notice notice-info inline wp-cortex-settings-notice"><p>';
		printf(
			/* translators: %s: link to the Status & stats section. */
			esc_html__( 'Changing the embedding model, dimensions or chunking settings requires re-indexing. Save, then use %s and choose "Rebuild from scratch".', 'wp-cortex' ),
			'<a href="' . esc_url( self::status_url() ) . '">' . esc_html__( 'Status & stats', 'wp-cortex' ) . '</a>'
		);
		echo '</p></div>';
	}

	/**
	 * Field name for a setting key.
	 *
	 * @param string $key    Setting key.
	 * @param bool   $is_array Whether the field submits an array.
	 */
	private function name( string $key, bool $is_array = false ): string {
		return esc_attr( Settings::OPTION . '[' . $key . ']' . ( $is_array ? '[]' : '' ) );
	}

	/**
	 * Opens a row wrapper.
	 *
	 * @param string $label Row label.
	 */
	private function row_start( string $label ): void {
		echo '<div class="wp-cortex-field"><div class="wp-cortex-field-label">' . esc_html( $label ) . '</div><div class="wp-cortex-field-control">';
	}

	/**
	 * Closes a row wrapper.
	 *
	 * @param string $description Optional description (plain text).
	 */
	private function row_end( string $description = '' ): void {
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</div></div>';
	}

	/**
	 * Renders a single checkbox.
	 *
	 * @param string $key     Setting key.
	 * @param string $label   Checkbox label.
	 * @param bool   $checked Checked state.
	 */
	private function checkbox( string $key, string $label, bool $checked ): void {
		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			$this->name( $key ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name().
			checked( $checked, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $label )
		);
	}

	/**
	 * Renders a number input.
	 *
	 * @param string $key   Setting key.
	 * @param int    $min   Minimum.
	 * @param int    $max   Maximum (0 for none).
	 * @param int    $step  Step.
	 */
	private function number( string $key, int $min, int $max, int $step = 1 ): void {
		printf(
			'<input type="number" class="small-text" name="%1$s" value="%2$d" min="%3$d" %4$s step="%5$d" />',
			$this->name( $key ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			(int) Settings::get( $key ),
			$min,
			$max > 0 ? 'max="' . (int) $max . '"' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$step
		);
	}

	/**
	 * Admin index section: post types, statuses and fields.
	 */
	public function fields_admin_index(): void {
		$this->scope_banner( Storage::SCOPE_ADMIN );

		$this->row_start( __( 'Post types', 'wp-cortex' ) );
		$this->post_type_checkboxes( Storage::SCOPE_ADMIN );
		$this->row_end( __( 'Media inherit the status of the post they are attached to; unattached media count as published. File contents (for example PDF text) are not extracted.', 'wp-cortex' ) );

		$selected_statuses = Settings::admin_statuses();
		$this->row_start( __( 'Statuses', 'wp-cortex' ) );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Statuses', 'wp-cortex' ) . '</legend>';
		foreach ( Settings::ADMIN_STATUSES as $status ) {
			$object   = get_post_status_object( $status );
			$label    = $object ? $object->label : $status;
			$is_fixed = 'publish' === $status;
			printf(
				'<label class="wp-cortex-block"><input type="checkbox" name="%1$s" value="%2$s" %3$s %4$s /> %5$s</label>',
				$this->name( 'admin_statuses', true ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_attr( $status ),
				checked( in_array( $status, $selected_statuses, true ), true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				disabled( $is_fixed, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		$this->row_end( __( 'Statuses included in the admin index. "Published" is always on.', 'wp-cortex' ) );

		$this->field_checkboxes( Storage::SCOPE_ADMIN );
	}

	/**
	 * Public index section: post types and fields.
	 */
	public function fields_public_index(): void {
		$this->scope_banner( Storage::SCOPE_PUBLIC );

		$this->row_start( __( 'Post types', 'wp-cortex' ) );
		$this->post_type_checkboxes( Storage::SCOPE_PUBLIC );
		$this->row_end( __( 'Only published content of post types that have pages on the site. Password-protected posts, and media attached to them, are never included.', 'wp-cortex' ) );

		$this->field_checkboxes( Storage::SCOPE_PUBLIC );
	}

	/**
	 * Colored banner that tells the admin and public index sections apart.
	 *
	 * @param string $scope Storage::SCOPE_ADMIN or Storage::SCOPE_PUBLIC.
	 */
	private function scope_banner( string $scope ): void {
		$is_public = Storage::SCOPE_PUBLIC === $scope;

		printf(
			'<div class="wp-cortex-scope-banner wp-cortex-scope-%1$s"><span class="dashicons %2$s" aria-hidden="true"></span><div><strong>%3$s</strong> %4$s</div></div>',
			esc_attr( $scope ),
			$is_public ? 'dashicons-admin-site-alt3' : 'dashicons-lock',
			$is_public ? esc_html__( 'Public index:', 'wp-cortex' ) : esc_html__( 'Admin index:', 'wp-cortex' ),
			$is_public
				? esc_html__( 'visible to anyone through the visitor chat. Only check what you would publish on the site.', 'wp-cortex' )
				: esc_html__( 'only used by administrators (admin chat, abilities, WP-CLI).', 'wp-cortex' )
		);
	}

	/**
	 * Post type checkboxes of an index. Media is offered when the post type is registered;
	 * the public index only offers post types that have pages on the site.
	 *
	 * @param string $scope Storage::SCOPE_ADMIN or Storage::SCOPE_PUBLIC.
	 */
	private function post_type_checkboxes( string $scope ): void {
		$selected = (array) Settings::get( $scope . '_post_types' );
		$types    = get_post_types( array( 'show_ui' => true ), 'objects' );

		echo '<fieldset class="wp-cortex-columns"><legend class="screen-reader-text">' . esc_html__( 'Post types', 'wp-cortex' ) . '</legend>';
		foreach ( $types as $slug => $type ) {
			if ( in_array( $slug, self::EXCLUDED_POST_TYPES, true ) ) {
				continue;
			}
			if ( Storage::SCOPE_PUBLIC === $scope && ! is_post_type_viewable( $slug ) ) {
				continue;
			}
			$this->post_type_checkbox( $scope, $slug, (string) ( $type->labels->name ?? $slug ), in_array( $slug, $selected, true ) );
		}
		$this->post_type_checkbox( $scope, 'attachment', __( 'Media', 'wp-cortex' ), in_array( 'attachment', $selected, true ) );
		echo '</fieldset>';
	}

	/**
	 * One post type checkbox.
	 *
	 * @param string $scope   Index.
	 * @param string $slug    Post type.
	 * @param string $label   Label.
	 * @param bool   $checked Checked state.
	 */
	private function post_type_checkbox( string $scope, string $slug, string $label, bool $checked ): void {
		printf(
			'<label class="wp-cortex-block"><input type="checkbox" name="%1$s" value="%2$s" %3$s /> %4$s <code>%2$s</code></label>',
			$this->name( $scope . '_post_types', true ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_attr( $slug ),
			checked( $checked, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $label )
		);
	}

	/**
	 * Field checkboxes of an index, one row per data source, with a filter box. Only fields
	 * that exist on the index's post types are listed; fields with a subgroup (ACF field
	 * groups) are listed in collapsible blocks, open when one of their fields is checked.
	 * Each section also sends the keys it shows, so unchecked fields are saved as excluded
	 * from that index only.
	 *
	 * @param string $scope Storage::SCOPE_ADMIN or Storage::SCOPE_PUBLIC.
	 */
	private function field_checkboxes( string $scope ): void {
		$policy = FieldPolicy::from_settings();
		$types  = Settings::post_types( $scope );

		$this->row_start( __( 'Find a field', 'wp-cortex' ) );
		printf(
			'<input type="search" class="regular-text wp-cortex-field-filter" placeholder="%1$s" aria-label="%1$s" />',
			esc_attr__( 'Filter by name, key or group', 'wp-cortex' )
		);
		echo '<p class="wp-cortex-field-filter-empty description" hidden>' . esc_html__( 'No fields match.', 'wp-cortex' ) . '</p>';
		$this->row_end();

		foreach ( FieldPolicy::catalog() as $label => $fields ) {
			$groups = array();
			foreach ( $fields as $key => $field ) {
				$instances = $field['groups'] ?? array( (string) ( $field['group'] ?? '' ) => $field );

				foreach ( $instances as $group => $instance ) {
					if ( ! empty( $instance['post_types'] ) && ! array_intersect( $instance['post_types'], $types ) ) {
						continue;
					}
					$groups[ (string) $group ][ $key ] = array_merge(
						$field,
						array_intersect_key( $instance, array_flip( array( 'label', 'type', 'post_types' ) ) ),
						array( 'shared' => array_values( array_diff( array_keys( $instances ), array( $group ) ) ) )
					);
				}
			}
			if ( ! $groups ) {
				continue;
			}
			ksort( $groups );

			echo '<div class="wp-cortex-field-source">';
			$this->row_start( $label );
			foreach ( $groups as $group => $group_fields ) {
				if ( '' === $group ) {
					$this->field_group( $scope, $policy, $label, $group_fields );
					continue;
				}

				$selected = count( array_filter( $group_fields, static fn( $field, $key ) => $policy->allows( $scope, $key, $field['public'] ), ARRAY_FILTER_USE_BOTH ) );
				$on_types = array_values( array_intersect( array_unique( array_merge( ...array_map( static fn( $field ) => (array) ( $field['post_types'] ?? array() ), array_values( $group_fields ) ) ) ), $types ) );

				printf( '<details class="wp-cortex-scope-group" %s><summary>', $selected ? 'open' : '' );
				printf(
					/* translators: 1: field group name, 2: number of checked fields, 3: number of fields. */
					esc_html__( '%1$s (%2$d of %3$d)', 'wp-cortex' ),
					esc_html( $group ),
					(int) $selected,
					count( $group_fields )
				);
				if ( $on_types ) {
					echo ' <span class="wp-cortex-scope-group-types">' . esc_html( implode( ', ', $on_types ) ) . '</span>';
				}
				echo '</summary>';
				$this->field_group( $scope, $policy, $group, $group_fields );
				echo '</details>';
			}
			$this->row_end();
			echo '</div>';
		}

		echo '<p class="description wp-cortex-scope-note">' . esc_html__( 'Title, URL, dates, author, excerpt and content are part of every indexed post (ACF block fields are part of the content). Only fields of the post types chosen above are listed, and only while their data source is on (Data sources & sync); save the settings after changing them to update the list. Run Sync on Status & stats after changing post types or fields.', 'wp-cortex' ) . '</p>';
	}

	/**
	 * Checkboxes of one group of fields, with "Select all" and "Select none" links.
	 *
	 * @param string                                                                              $scope  Storage::SCOPE_ADMIN or Storage::SCOPE_PUBLIC.
	 * @param FieldPolicy                                                                         $policy Saved field rules.
	 * @param string                                                                              $legend Group name for screen readers.
	 * @param array<string, array{label: string, public: bool, type?: string, shared?: string[]}> $fields Field key => field.
	 */
	private function field_group( string $scope, FieldPolicy $policy, string $legend, array $fields ): void {
		echo '<div class="wp-cortex-scope-fieldset">';
		printf( '<fieldset class="wp-cortex-columns wp-cortex-scope-fields"><legend class="screen-reader-text">%s</legend>', esc_html( $legend ) );
		foreach ( $fields as $key => $field ) {
			printf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( Settings::OPTION . '[field_scopes][known][' . $scope . '][]' ), esc_attr( $key ) );
			printf(
				'<label class="wp-cortex-block"><input type="checkbox" name="%1$s" value="%2$s" %3$s /> %4$s%5$s <code>%6$s</code>%7$s</label>',
				esc_attr( Settings::OPTION . '[field_scopes][' . $scope . '][]' ),
				esc_attr( $key ),
				checked( $policy->allows( $scope, $key, $field['public'] ), true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $field['label'] ),
				empty( $field['type'] ) ? '' : ' <span class="wp-cortex-field-type">' . esc_html( $field['type'] ) . '</span>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $key ),
				empty( $field['shared'] ) ? '' : '<span class="wp-cortex-field-shared">' . esc_html(
					sprintf(
						/* translators: %s: names of the other field groups. */
						__( 'Same field name in: %s. One choice applies to all of them.', 'wp-cortex' ),
						implode( ', ', $field['shared'] )
					)
				) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
		echo '</fieldset>';
		echo '<p class="wp-cortex-scope-toggle"><button type="button" class="button-link" data-wp-cortex-check="1">' . esc_html__( 'Select all', 'wp-cortex' ) . '</button> | <button type="button" class="button-link" data-wp-cortex-check="0">' . esc_html__( 'Select none', 'wp-cortex' ) . '</button></p>';
		echo '</div>';
	}

	/**
	 * Data sources section.
	 */
	public function fields_sources(): void {
		$this->row_start( __( 'Auto sync', 'wp-cortex' ) );
		$this->checkbox( 'auto_sync', __( 'Keep the index updated automatically when content changes', 'wp-cortex' ), (bool) Settings::get( 'auto_sync' ) );
		$this->row_end();

		$this->row_start( __( 'Yoast SEO', 'wp-cortex' ) );
		$this->checkbox( 'index_yoast', __( 'Index Yoast SEO fields (title, description, focus keyword)', 'wp-cortex' ), (bool) Settings::get( 'index_yoast' ) );
		$this->row_end( defined( 'WPSEO_VERSION' ) ? '' : __( 'Yoast SEO not detected.', 'wp-cortex' ) );

		$this->row_start( __( 'Advanced Custom Fields', 'wp-cortex' ) );
		$this->checkbox( 'index_acf', __( 'Index ACF fields', 'wp-cortex' ), (bool) Settings::get( 'index_acf' ) );
		$this->row_end( function_exists( 'get_field_objects' ) ? '' : __( 'Advanced Custom Fields not detected.', 'wp-cortex' ) );

		$meta_keys = implode( "\n", array_map( 'strval', (array) Settings::get( 'meta_keys' ) ) );
		$this->row_start( __( 'Custom meta keys', 'wp-cortex' ) );
		printf(
			'<textarea name="%1$s" rows="5" class="large-text code">%2$s</textarea>',
			$this->name( 'meta_keys' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_textarea( $meta_keys )
		);
		$this->row_end( __( 'One meta key per line. Indexed in the admin index unless you also choose them under Public index.', 'wp-cortex' ) );
	}

	/**
	 * Chunking section.
	 */
	public function fields_chunking(): void {
		$this->row_start( __( 'Chunk size', 'wp-cortex' ) );
		$this->number( 'chunk_size', 300, 6000, 50 );
		$this->row_end( __( 'Approximate characters per chunk (300-6000).', 'wp-cortex' ) );

		$this->row_start( __( 'Chunk overlap', 'wp-cortex' ) );
		$this->number( 'chunk_overlap', 0, 3000, 10 );
		$this->row_end( __( 'Characters shared between consecutive chunks. Capped at half of the chunk size.', 'wp-cortex' ) );

		$this->row_start( __( 'Batch size', 'wp-cortex' ) );
		$this->number( 'batch_size', 1, 100 );
		$this->row_end( __( 'Posts per indexing request. Lower it if requests time out.', 'wp-cortex' ) );
	}

	/**
	 * Storage section: data directory, its location and the public access check (filled in
	 * by storage.js).
	 */
	public function fields_storage(): void {
		echo '<p class="wp-cortex-data-dir">' . esc_html__( 'Data directory:', 'wp-cortex' ) . ' ';
		echo '<code class="wp-cortex-data-dir-mask" aria-hidden="true">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</code>';
		echo '<code id="wp-cortex-data-dir-path" hidden>' . esc_html( Storage::data_dir() ) . '</code> ';
		echo '<button type="button" class="button-link wp-cortex-data-dir-toggle" id="wp-cortex-data-dir-toggle" aria-controls="wp-cortex-data-dir-path" aria-expanded="false" aria-label="' . esc_attr__( 'Show data directory', 'wp-cortex' ) . '" title="' . esc_attr__( 'Show data directory', 'wp-cortex' ) . '">';
		echo '<span class="dashicons dashicons-visibility" aria-hidden="true"></span>';
		echo '</button> <span class="wp-cortex-data-dir-location">(' . esc_html( Storage::location_label() ) . ')</span></p>';

		if ( Storage::is_in_uploads() ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'The index is stored inside the uploads directory because no private location outside the web root is writable. For better protection, define WP_CORTEX_DATA_DIR in wp-config.php with a writable path outside the web root.', 'wp-cortex' );
			echo '</p></div>';
		}

		echo '<div class="wp-cortex-storage-check is-checking" id="wp-cortex-storage-check">';
		echo '<p class="wp-cortex-storage-check-summary" aria-live="polite"><span class="dashicons dashicons-update" aria-hidden="true"></span> <strong data-check-summary>' . esc_html__( 'Checking public access…', 'wp-cortex' ) . '</strong></p>';
		echo '<ul class="wp-cortex-storage-check-list" data-check-list></ul>';
		echo '<div class="wp-cortex-storage-check-help" data-check-help hidden><p>';
		echo esc_html__( 'Block access to the data directory in your web server configuration, or move it outside the web root with WP_CORTEX_DATA_DIR.', 'wp-cortex' );
		echo '</p><p>';
		echo esc_html__( 'On Nginx, refusing hidden paths (names starting with a dot) protects it:', 'wp-cortex' );
		echo ' <code>location ~ /\\. { deny all; }</code>';
		echo '</p></div>';
		echo '<p class="wp-cortex-storage-check-meta"><span data-check-time></span> ';
		echo '<button type="button" class="button button-small" id="wp-cortex-storage-recheck" disabled>' . esc_html__( 'Test again', 'wp-cortex' ) . '</button></p>';
		echo '</div>';
	}

	/**
	 * Uninstall section.
	 */
	public function fields_uninstall(): void {
		$this->row_start( __( 'Index data', 'wp-cortex' ) );
		$this->checkbox( 'uninstall_delete_index', __( 'Delete the index databases when the plugin is deleted', 'wp-cortex' ), (bool) Settings::get( 'uninstall_delete_index' ) );
		$this->row_end( __( 'When off, the data directory (shown under Settings > Advanced > Storage) is kept, and installing the plugin again on this site reuses the index without re-indexing or new embeddings. Settings, conversations and visitor images are always deleted.', 'wp-cortex' ) );
	}

	/**
	 * Embeddings section.
	 */
	public function fields_embeddings(): void {
		$current_model = (string) Settings::get( 'embedding_model' );
		$current_dims  = (int) Settings::get( 'embedding_dimensions' );

		$this->row_start( __( 'Enabled', 'wp-cortex' ) );
		$this->checkbox( 'embeddings_enabled', __( 'Generate vector embeddings for semantic search', 'wp-cortex' ), (bool) Settings::get( 'embeddings_enabled' ) );
		$this->row_end();

		$this->row_start( __( 'Model', 'wp-cortex' ) );
		printf( '<select name="%s" id="wp-cortex-embedding-model">', $this->name( 'embedding_model' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( array_keys( Settings::EMBEDDING_MODELS ) as $model ) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $model ), selected( $current_model, $model, false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</select>';
		$this->row_end();

		$all_dims = array();
		foreach ( Settings::EMBEDDING_MODELS as $dims ) {
			$all_dims = array_merge( $all_dims, $dims );
		}
		$all_dims = array_unique( $all_dims );
		sort( $all_dims );

		$this->row_start( __( 'Dimensions', 'wp-cortex' ) );
		printf( '<select name="%s" id="wp-cortex-embedding-dimensions">', $this->name( 'embedding_dimensions' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( $all_dims as $dim ) {
			$models = array();
			foreach ( Settings::EMBEDDING_MODELS as $model => $dims ) {
				if ( in_array( $dim, $dims, true ) ) {
					$models[] = $model;
				}
			}
			printf(
				'<option value="%1$d" data-models="%2$s" %3$s>%1$d</option>',
				(int) $dim,
				esc_attr( implode( ' ', $models ) ),
				selected( $current_dims, $dim, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
		echo '</select>';
		$this->row_end( __( 'Higher dimensions are more precise but use more storage.', 'wp-cortex' ) );

		$this->row_start( __( 'OpenAI API key', 'wp-cortex' ) );
		$key = OpenAIEmbeddings::api_key();
		if ( '' !== $key ) {
			printf(
				'<span class="wp-cortex-badge wp-cortex-badge-ok">%1$s</span> <code>%2$s</code>',
				esc_html__( 'Configured', 'wp-cortex' ),
				esc_html( str_repeat( '•', 8 ) . substr( $key, -4 ) )
			);
		} else {
			echo '<span class="wp-cortex-badge wp-cortex-badge-warn">' . esc_html__( 'Not configured', 'wp-cortex' ) . '</span> ';
			printf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-connectors.php' ) ),
				esc_html__( 'Add an OpenAI API key under Settings > Connectors', 'wp-cortex' )
			);
		}
		$this->row_end();
	}

	/**
	 * Chat section.
	 */
	public function fields_chat(): void {
		$providers = ChatAgent::providers();
		$selected  = (string) Settings::get( 'chat_provider' );

		$this->row_start( __( 'Enabled', 'wp-cortex' ) );
		$this->checkbox( 'chat_enabled', __( 'Show the Cortex chat assistant in the admin', 'wp-cortex' ), (bool) Settings::get( 'chat_enabled' ) );
		echo '<br />';
		$this->checkbox( 'chat_frontend', __( 'Also show it on the front end of the site to administrators', 'wp-cortex' ), (bool) Settings::get( 'chat_frontend' ) );
		$this->row_end( __( 'The admin chat is only available to administrators and searches the admin index (including drafts, private content and SEO or custom fields). On the front end it knows which post you are viewing; admin screens and tabs can only be opened from the admin.', 'wp-cortex' ) );

		$this->model_fields( 'chat_', $providers, $selected );

		$this->row_start( __( 'Custom instructions', 'wp-cortex' ) );
		printf(
			'<textarea name="%1$s" rows="6" class="large-text" maxlength="%2$d" placeholder="%3$s">%4$s</textarea>',
			$this->name( 'chat_instructions' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			(int) Settings::CHAT_INSTRUCTIONS_MAX,
			esc_attr__( 'For example: Always answer in Serbian (Latin script). Address me informally.', 'wp-cortex' ),
			esc_textarea( (string) Settings::get( 'chat_instructions' ) )
		);
		$this->row_end(
			sprintf(
				/* translators: %d: maximum number of characters. */
				__( 'Optional. Added to the system prompt of every chat message, for example a preferred language, tone or answer format. Takes precedence over the default instructions. Up to %d characters.', 'wp-cortex' ),
				Settings::CHAT_INSTRUCTIONS_MAX
			)
		);
	}

	/**
	 * Skills settings section: on/off switch, skill language and custom skill instructions.
	 */
	public function fields_skills(): void {
		$this->row_start( __( 'Enabled', 'wp-cortex' ) );
		$this->checkbox( 'skills_enabled', __( 'Use chat skills', 'wp-cortex' ), Settings::skills_enabled() );
		$this->row_end( __( 'Lets the admin chat use saved skills and propose new ones, and lists them on this tab. When off, the saved skills list is hidden and no skill is used or proposed; saved skills are kept.', 'wp-cortex' ) );

		$this->row_start( __( 'Language', 'wp-cortex' ) );
		printf(
			'<input type="text" class="regular-text" name="%1$s" value="%2$s" maxlength="%3$d" placeholder="%4$s" />',
			$this->name( 'skills_language' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name().
			esc_attr( Settings::skills_language() ),
			(int) Settings::SKILLS_LANGUAGE_MAX,
			esc_attr( Settings::SKILLS_LANGUAGE_DEFAULT )
		);
		$this->row_end( __( 'The language the assistant writes proposed skills in (name, description and instructions), whatever language you chat in. Empty means English.', 'wp-cortex' ) );

		$this->row_start( __( 'Custom instructions', 'wp-cortex' ) );
		printf(
			'<textarea name="%1$s" rows="6" class="large-text" maxlength="%2$d" placeholder="%3$s">%4$s</textarea>',
			$this->name( 'skills_instructions' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			(int) Settings::CHAT_INSTRUCTIONS_MAX,
			esc_attr__( 'For example: Keep skills to at most five steps. Offer to save a skill only after tasks that took three or more steps.', 'wp-cortex' ),
			esc_textarea( (string) Settings::get( 'skills_instructions' ) )
		);
		$this->row_end(
			sprintf(
				/* translators: %d: maximum number of characters. */
				__( 'Optional. Added to the admin chat system prompt while skills are on, for example how skills should be written or when the assistant should offer one. Up to %d characters.', 'wp-cortex' ),
				Settings::CHAT_INSTRUCTIONS_MAX
			)
		);
	}

	/**
	 * Visitor chat section.
	 */
	public function fields_public_chat(): void {
		$this->row_start( __( 'Enabled', 'wp-cortex' ) );
		$this->checkbox( 'public_chat_enabled', __( 'Show a chat assistant to visitors on the front end of the site', 'wp-cortex' ), (bool) Settings::get( 'public_chat_enabled' ) );
		$this->row_end( __( 'Visitors can ask questions about the site. Answers use only the public index (published, publicly viewable content and fields marked as public) and link to the pages they are based on. It uses the AI provider and model chosen under AI assistant and is billed to that account. Administrators see the admin chat instead while it is shown on the front end.', 'wp-cortex' ) );

		$this->row_start( __( 'Title', 'wp-cortex' ) );
		printf(
			'<input type="text" class="regular-text" name="%1$s" value="%2$s" maxlength="%3$d" placeholder="%4$s" />',
			$this->name( 'public_chat_title' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name().
			esc_attr( (string) Settings::get( 'public_chat_title' ) ),
			(int) Settings::PUBLIC_CHAT_TITLE_MAX,
			esc_attr__( 'Ask a question', 'wp-cortex' )
		);
		$this->row_end( __( 'Shown in the header of the chat window.', 'wp-cortex' ) );

		$this->row_start( __( 'Welcome message', 'wp-cortex' ) );
		printf(
			'<textarea name="%1$s" rows="2" class="large-text" maxlength="%2$d" placeholder="%3$s">%4$s</textarea>',
			$this->name( 'public_chat_welcome' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			(int) Settings::PUBLIC_CHAT_WELCOME_MAX,
			esc_attr__( 'Hi! Ask me anything about this website.', 'wp-cortex' ),
			esc_textarea( (string) Settings::get( 'public_chat_welcome' ) )
		);
		$this->row_end( __( 'The first message visitors see when they open the chat.', 'wp-cortex' ) );

		$this->row_start( __( 'Message box placeholder', 'wp-cortex' ) );
		printf(
			'<input type="text" class="regular-text" name="%1$s" value="%2$s" maxlength="%3$d" placeholder="%4$s" />',
			$this->name( 'public_chat_placeholder' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name().
			esc_attr( (string) Settings::get( 'public_chat_placeholder' ) ),
			(int) Settings::PUBLIC_CHAT_PLACEHOLDER_MAX,
			esc_attr__( 'Type your question…', 'wp-cortex' )
		);
		$this->row_end( __( 'The hint shown in the empty message box.', 'wp-cortex' ) );

		$this->row_start( __( 'Message limit', 'wp-cortex' ) );
		$this->number( 'public_chat_rate_limit', 1, 1000 );
		echo ' ' . esc_html__( 'messages per visitor per hour', 'wp-cortex' );
		$this->row_end( __( 'Protects your AI provider account from abuse. Visitors are counted by IP address.', 'wp-cortex' ) );

		$this->row_start( __( 'Images', 'wp-cortex' ) );
		$this->checkbox( 'public_chat_images', __( 'Let visitors attach images, for example a screenshot of a problem', 'wp-cortex' ), (bool) Settings::get( 'public_chat_images' ) );
		echo '<br /><label>' . esc_html__( 'At most', 'wp-cortex' ) . ' ';
		$this->number( 'public_chat_image_limit', 1, 1000 );
		echo ' ' . esc_html__( 'images per visitor per hour', 'wp-cortex' ) . '</label>';
		echo '<br />';
		$this->checkbox( 'public_chat_attach_icon', __( 'Show the attach button next to the message box', 'wp-cortex' ), (bool) Settings::get( 'public_chat_attach_icon' ) );
		if ( ! VisitorImages::is_supported() ) {
			echo '<p class="description"><strong>' . esc_html__( 'Images are not available: the PHP GD extension with PNG and JPEG support is required.', 'wp-cortex' ) . '</strong></p>';
		}
		$this->row_end( __( 'Visitors can paste a screenshot or drop an image on the chat, and pick one with the attach button when it is shown. Images are scaled down to 1600 pixels, stripped of metadata and sent to the AI model, which must support image input (for example current OpenAI, Anthropic and Google models). While the conversation log is on, they are stored with the conversation in the protected data directory, shown under Cortex > Visitor chats, attached to forwarded emails and deleted with the conversation.', 'wp-cortex' ) );

	}

	/**
	 * Renders a select of fixed choices.
	 *
	 * @param string                $key     Setting key.
	 * @param array<string, string> $options Value => label.
	 */
	private function select( string $key, array $options ): void {
		$saved = (string) Settings::get( $key );

		printf( '<select name="%s">', $this->name( $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name().
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $saved, $value, false ), esc_html( $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</select>';
	}

	/**
	 * Renders a pixel size input with its range from Settings::PUBLIC_CHAT_SIZES.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label shown before the input.
	 */
	private function pixels( string $key, string $label ): void {
		printf( '<label class="wp-cortex-pixels">%s ', esc_html( $label ) );
		$this->number( $key, Settings::PUBLIC_CHAT_SIZES[ $key ][0], Settings::PUBLIC_CHAT_SIZES[ $key ][1] );
		echo ' px</label>';
	}

	/**
	 * Visitor chat AI assistant section: provider, model, reasoning and custom instructions.
	 */
	public function fields_public_chat_assistant(): void {
		$this->model_fields( 'public_chat_', ChatAgent::providers(), (string) Settings::get( 'public_chat_provider' ) );

		$this->row_start( __( 'Custom instructions', 'wp-cortex' ) );
		printf(
			'<textarea name="%1$s" rows="6" class="large-text" maxlength="%2$d" placeholder="%3$s">%4$s</textarea>',
			$this->name( 'public_chat_instructions' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			(int) Settings::CHAT_INSTRUCTIONS_MAX,
			esc_attr__( 'For example: Be friendly and concise. When visitors ask about prices, suggest the contact page.', 'wp-cortex' ),
			esc_textarea( (string) Settings::get( 'public_chat_instructions' ) )
		);
		$this->row_end(
			sprintf(
				/* translators: %d: maximum number of characters. */
				__( 'Optional. Added to the system prompt of every visitor message, for example tone, language or what to recommend. Separate from the admin chat instructions. Up to %d characters.', 'wp-cortex' ),
				Settings::CHAT_INSTRUCTIONS_MAX
			)
		);
	}

	/**
	 * Visitor chat summary section: provider, model, reasoning, language and custom instructions.
	 */
	public function fields_public_chat_summary(): void {
		$this->model_fields( 'summary_', ChatAgent::providers(), (string) Settings::get( 'summary_provider' ) );

		$this->row_start( __( 'Language', 'wp-cortex' ) );
		$this->select(
			'summary_language',
			array(
				/* translators: %s: site language, for example "English (United States), locale en_US". */
				'site'    => sprintf( __( 'Site language: %s', 'wp-cortex' ), VisitorChatSummarizer::site_language() ),
				'visitor' => __( 'The visitor\'s language', 'wp-cortex' ),
			)
		);
		$this->row_end( __( 'The site language is set under Settings > General > Site Language, so the team gets every summary in the same language whatever language the visitor wrote in.', 'wp-cortex' ) );

		$this->row_start( __( 'Custom instructions', 'wp-cortex' ) );
		printf(
			'<textarea name="%1$s" rows="6" class="large-text" maxlength="%2$d" placeholder="%3$s">%4$s</textarea>',
			$this->name( 'summary_instructions' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			(int) Settings::CHAT_INSTRUCTIONS_MAX,
			esc_attr__( 'For example: Start with a one-line lead rating (hot, warm or cold). Always mention the budget and deadline if the visitor gave them.', 'wp-cortex' ),
			esc_textarea( (string) Settings::get( 'summary_instructions' ) )
		);
		$this->row_end(
			sprintf(
				/* translators: %d: maximum number of characters. */
				__( 'Optional. Added to the prompt of every summary, for example what to highlight, extra sections or the tone. Takes precedence over the default instructions. Up to %d characters.', 'wp-cortex' ),
				Settings::CHAT_INSTRUCTIONS_MAX
			)
		);
	}

	/**
	 * Provider and model rows (with the reasoning level) of a chat.
	 *
	 * @param string $prefix    Setting key prefix: "chat_", "public_chat_" or "summary_".
	 * @param array  $providers Registered providers from ChatAgent::providers().
	 * @param string $selected  Saved provider ID.
	 */
	private function model_fields( string $prefix, array $providers, string $selected ): void {
		// The visitor chat and the summary can follow the admin chat; the summary needs no tool calling.
		$inherits    = 'chat_' !== $prefix;
		$needs_tools = 'summary_' !== $prefix;
		$admin       = (string) Settings::get( 'chat_provider' );

		$this->row_start( __( 'AI provider', 'wp-cortex' ) );
		printf( '<select name="%1$s" id="wp-cortex-%2$sprovider">', $this->name( $prefix . 'provider' ), esc_attr( str_replace( '_', '-', $prefix ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name().
		if ( $inherits ) {
			printf(
				'<option value="" data-no-model %1$s>%2$s</option>',
				selected( $selected, '', false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html(
					sprintf(
						/* translators: %s: provider of the admin chat. */
						__( 'Same as the admin chat (%s)', 'wp-cortex' ),
						isset( $providers[ $admin ] ) ? $providers[ $admin ]['name'] : __( 'Automatic', 'wp-cortex' )
					)
				)
			);
		}
		$auto = $inherits ? Settings::PUBLIC_CHAT_PROVIDER_AUTO : '';
		printf( '<option value="%1$s" data-no-model %2$s>%3$s</option>', esc_attr( $auto ), selected( $selected, $auto, false ), esc_html__( 'Automatic', 'wp-cortex' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( $providers as $id => $provider ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $id ),
				selected( $selected, $id, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $provider['name'] . ' (' . ( $provider['configured'] ? __( 'configured', 'wp-cortex' ) : __( 'no API key', 'wp-cortex' ) ) . ')' )
			);
		}
		echo '</select> ';
		printf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-connectors.php' ) ),
			esc_html__( 'Manage API keys under Settings > Connectors', 'wp-cortex' )
		);
		$help = array(
			'chat_'        => __( 'Automatic uses any configured provider that supports text generation with tool calls.', 'wp-cortex' ),
			'public_chat_' => __( 'The visitor chat can use a different provider than the admin chat, for example a cheaper or faster one. "Same as the admin chat" follows the provider, model and reasoning of the Admin chat tab. Automatic uses any configured provider that supports text generation with tool calls.', 'wp-cortex' ),
			'summary_'     => __( 'Summaries can use a different provider than the admin chat, for example a cheaper one or one that writes better in your language. "Same as the admin chat" follows the provider, model and reasoning of the Admin chat tab. Automatic uses any configured provider that supports text generation.', 'wp-cortex' ),
		);
		$this->row_end( $help[ $prefix ] );

		$provider_id = isset( $providers[ $selected ] ) ? $selected : '';
		$saved       = (string) Settings::get( $prefix . 'model' );
		$cached      = '' !== $provider_id ? ( new ModelCatalog() )->cached( $provider_id ) : null;
		$has_tools   = $needs_tools ? array_filter( (array) $cached, static fn( array $m ) => $m['tools'] ) : (array) $cached;

		$this->row_start( __( 'Model', 'wp-cortex' ) );
		printf(
			'<div class="wp-cortex-model-picker" data-provider="wp-cortex-%1$sprovider" data-saved="%2$s"%3$s>',
			esc_attr( str_replace( '_', '-', $prefix ) ),
			esc_attr( $saved ),
			$needs_tools ? '' : ' data-any-model'
		);
		printf(
			'<input type="search" class="wp-cortex-model-filter" placeholder="%s" aria-label="%s" hidden />',
			esc_attr__( 'Filter models…', 'wp-cortex' ),
			esc_attr__( 'Filter models', 'wp-cortex' )
		);
		printf( '<select name="%s" class="wp-cortex-model-select" aria-label="%s">', $this->name( $prefix . 'model' ), esc_attr__( 'Model', 'wp-cortex' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<option value="" %s>%s</option>', selected( $saved, '', false ), esc_html__( 'Provider default', 'wp-cortex' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$listed = false;
		foreach ( $has_tools as $model ) {
			$listed = $listed || $model['id'] === $saved;
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $model['id'] ),
				selected( $saved, $model['id'], false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $model['name'] . ' (' . $model['id'] . ')' )
			);
		}
		if ( '' !== $saved && ! $listed ) {
			printf(
				'<option value="%1$s" selected>%2$s</option>',
				esc_attr( $saved ),
				/* translators: %s: model ID. */
				esc_html( sprintf( __( '%s (saved)', 'wp-cortex' ), $saved ) )
			);
		}
		echo '</select> ';
		$this->reasoning_select( $prefix . 'reasoning', array_keys( $providers ), $provider_id, $saved );
		printf(
			'<button type="button" class="button wp-cortex-model-refresh" hidden><span class="dashicons dashicons-update" aria-hidden="true"></span> %s</button>',
			esc_html__( 'Refresh models', 'wp-cortex' )
		);
		echo ' <span class="wp-cortex-model-status" role="status" aria-live="polite"></span>';
		echo '</div>';
		$this->row_end(
			( $needs_tools
				? __( 'Optional. Models are loaded from the selected provider; those without tool calling cannot be used by the chat. Leave on "Provider default" to let the provider choose.', 'wp-cortex' )
				: __( 'Optional. Models are loaded from the selected provider. Leave on "Provider default" to let the provider choose.', 'wp-cortex' )
			) . ' ' . __( 'Reasoning sets how much the model thinks before answering (more is slower and costs more); it is shown once a model is chosen, and a level the model does not support makes the request fail.', 'wp-cortex' )
		);
	}

	/**
	 * Visitor chat appearance section: colors, position, sizes, font and a live preview.
	 */
	public function fields_public_chat_appearance(): void {
		$this->row_start( __( 'Accent color', 'wp-cortex' ) );
		printf(
			'<input type="color" name="%1$s" value="%2$s" aria-label="%3$s" />',
			$this->name( 'public_chat_accent' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name().
			esc_attr( (string) Settings::get( 'public_chat_accent' ) ),
			esc_attr__( 'Accent color', 'wp-cortex' )
		);
		$this->row_end( __( 'Used for the chat button, the header, visitor messages and links. Text on it turns white or dark automatically, whichever is easier to read.', 'wp-cortex' ) );

		$this->row_start( __( 'Color scheme', 'wp-cortex' ) );
		$this->select(
			'public_chat_scheme',
			array(
				'light' => __( 'Light', 'wp-cortex' ),
				'dark'  => __( 'Dark', 'wp-cortex' ),
				'auto'  => __( 'Match the visitor\'s device', 'wp-cortex' ),
			)
		);
		$this->row_end( __( 'Background and text colors of the chat window.', 'wp-cortex' ) );

		$this->row_start( __( 'Position', 'wp-cortex' ) );
		$this->select(
			'public_chat_position',
			array(
				'right' => __( 'Bottom right', 'wp-cortex' ),
				'left'  => __( 'Bottom left', 'wp-cortex' ),
			)
		);
		$this->pixels( 'public_chat_offset', __( 'Distance from the edge', 'wp-cortex' ) );
		$this->row_end( __( 'Move the chat to the left when it covers another widget, for example a cookie notice or a "back to top" button.', 'wp-cortex' ) );

		$this->row_start( __( 'Chat button', 'wp-cortex' ) );
		$this->pixels( 'public_chat_launcher_size', __( 'Size', 'wp-cortex' ) );
		printf(
			'<input type="text" class="regular-text" name="%1$s" value="%2$s" maxlength="%3$d" placeholder="%4$s" aria-label="%5$s" />',
			$this->name( 'public_chat_launcher_label' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name().
			esc_attr( (string) Settings::get( 'public_chat_launcher_label' ) ),
			(int) Settings::PUBLIC_CHAT_LABEL_MAX,
			esc_attr__( 'Label (optional), for example: Chat with us', 'wp-cortex' ),
			esc_attr__( 'Chat button label', 'wp-cortex' )
		);
		$this->row_end( __( 'Size of the round button that opens the chat. With a label it becomes a pill with the icon and the text.', 'wp-cortex' ) );

		$this->row_start( __( 'Chat window', 'wp-cortex' ) );
		$this->pixels( 'public_chat_width', __( 'Width', 'wp-cortex' ) );
		$this->pixels( 'public_chat_height', __( 'Height', 'wp-cortex' ) );
		$this->row_end( __( 'Width and height. The window never grows beyond the screen and fills the bottom of the screen on phones.', 'wp-cortex' ) );

		$this->row_start( __( 'Rounded corners', 'wp-cortex' ) );
		$this->pixels( 'public_chat_radius', __( 'Radius', 'wp-cortex' ) );
		$this->row_end( __( 'Corner radius of the window and messages; the input and buttons use a slightly smaller one. 0 gives square corners.', 'wp-cortex' ) );

		$this->row_start( __( 'Font', 'wp-cortex' ) );
		$this->select(
			'public_chat_font',
			array(
				'system' => __( 'System font', 'wp-cortex' ),
				'theme'  => __( 'Theme font', 'wp-cortex' ),
			)
		);
		$this->pixels( 'public_chat_font_size', __( 'Size', 'wp-cortex' ) );
		$this->row_end( __( 'The system font looks the same on every theme; the theme font matches the rest of the site.', 'wp-cortex' ) );

		$this->row_start( __( 'Preview', 'wp-cortex' ) );
		$this->appearance_preview();
		$this->row_end();
	}

	/**
	 * Static mock-up of the visitor chat, styled by public-chat.css and updated by settings.js.
	 */
	private function appearance_preview(): void {
		$title = (string) Settings::get( 'public_chat_title' );
		$label = (string) Settings::get( 'public_chat_launcher_label' );
		$icon  = static function ( string $path ): string {
			return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . esc_attr( $path ) . '"></path></svg>';
		};

		printf(
			'<div class="wp-cortex-pchat-preview %1$s" id="wp-cortex-pchat-preview" style="%2$s" aria-hidden="true" inert>',
			esc_attr( implode( ' ', ChatAppearance::classes() ) ),
			esc_attr( ChatAppearance::declarations() )
		);
		echo '<div class="wp-cortex-pchat-panel is-open">';
		echo '<div class="wp-cortex-pchat-header">';
		printf( '<p class="wp-cortex-pchat-title" data-placeholder="%1$s">%2$s</p>', esc_attr__( 'Ask a question', 'wp-cortex' ), esc_html( '' !== $title ? $title : __( 'Ask a question', 'wp-cortex' ) ) );
		echo '<span class="wp-cortex-pchat-iconbtn">' . $icon( 'M12 5v14M5 12h14' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
		echo '<span class="wp-cortex-pchat-iconbtn">' . $icon( 'M6 6l12 12M18 6L6 18' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
		echo '</div><div class="wp-cortex-pchat-messages">';
		echo '<div class="wp-cortex-pchat-msg wp-cortex-pchat-msg-assistant">' . esc_html__( 'Hi! Ask me anything about this website.', 'wp-cortex' ) . '</div>';
		echo '<div class="wp-cortex-pchat-msg wp-cortex-pchat-msg-user">' . esc_html__( 'What services do you offer?', 'wp-cortex' ) . '</div>';
		echo '<div class="wp-cortex-pchat-msg wp-cortex-pchat-msg-assistant"><p>' . esc_html__( 'We offer design, development and support. You can find the details on our services page.', 'wp-cortex' ) . '</p></div>';
		echo '<div class="wp-cortex-pchat-sources"><p class="wp-cortex-pchat-sources-title">' . esc_html__( 'Sources', 'wp-cortex' ) . '</p><ul><li><span class="wp-cortex-pchat-source-link">' . esc_html__( 'Services', 'wp-cortex' ) . '</span></li></ul></div>';
		echo '</div><div class="wp-cortex-pchat-form">';
		echo '<span class="wp-cortex-pchat-input">' . esc_html__( 'Type your question…', 'wp-cortex' ) . '</span>';
		echo '<span class="wp-cortex-pchat-send">' . esc_html__( 'Send', 'wp-cortex' ) . '</span>';
		echo '</div></div>';
		printf(
			'<span class="wp-cortex-pchat-toggle%1$s">%2$s<span class="wp-cortex-pchat-toggle-label">%3$s</span></span>',
			'' !== $label ? ' has-label' : '',
			$icon( 'M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
			esc_html( $label )
		);
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Theme styles on the site may change the result slightly, for example with the theme font.', 'wp-cortex' ) . '</p>';
	}

	/**
	 * Visitor chat privacy section: conversation log, IP address and retention.
	 */
	public function fields_public_chat_privacy(): void {
		$this->row_start( __( 'Conversation log', 'wp-cortex' ) );
		$this->checkbox( 'public_chat_log', __( 'Save visitor conversations', 'wp-cortex' ), (bool) Settings::get( 'public_chat_log' ) );
		$this->row_end(
			sprintf(
				/* translators: %s: name of the Visitor chats screen. */
				__( 'Conversations and the contact details visitors leave are stored on the server and listed under %s. No cookie is stored. Mention it in your privacy policy. When off, conversations are not stored and contact details cannot be collected; issue reports are still saved.', 'wp-cortex' ),
				__( 'Cortex > Visitor chats', 'wp-cortex' )
			)
		);

		$this->row_start( __( 'IP address', 'wp-cortex' ) );
		$this->checkbox( 'public_chat_store_ip', __( 'Store the visitor\'s IP address with the conversation', 'wp-cortex' ), (bool) Settings::get( 'public_chat_store_ip' ) );
		echo '<br /><label>' . esc_html__( 'Behind a proxy or CDN, read the visitor IP from', 'wp-cortex' ) . ' ';
		printf( '<select name="%s">', $this->name( 'public_chat_ip_header' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name().
		printf( '<option value="" %s>%s</option>', selected( (string) Settings::get( 'public_chat_ip_header' ), '', false ), esc_html__( 'the connection (REMOTE_ADDR, no proxy)', 'wp-cortex' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( array_keys( ClientIp::HEADERS ) as $header ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $header ), selected( (string) Settings::get( 'public_chat_ip_header' ), $header, false ), esc_html( ucwords( $header, '-' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</select></label>';
		$detected = array( 'REMOTE_ADDR: ' . ( '' !== ClientIp::remote_addr() ? ClientIp::remote_addr() : '–' ) );
		foreach ( ClientIp::present_headers() as $header => $value ) {
			$detected[] = ucwords( $header, '-' ) . ': ' . $value;
		}
		echo '<p class="description">' . esc_html__( 'Detected on this request:', 'wp-cortex' ) . ' <code>' . esc_html( implode( ' · ', $detected ) ) . '</code></p>';
		$this->row_end( __( 'Choose a header only if your site is behind a proxy or CDN that sets it (for example CF-Connecting-IP for Cloudflare); otherwise visitors could fake their IP. The chosen address is also used for the message limit. Addresses reported by other proxy headers are stored separately as unverified.', 'wp-cortex' ) );

		$this->row_start( __( 'Keep conversations', 'wp-cortex' ) );
		$this->number( 'public_chat_retention', 0, 3650 );
		echo ' ' . esc_html__( 'days', 'wp-cortex' );
		$this->row_end( __( 'Conversations without visitor activity for this many days are deleted automatically (daily). 0 keeps them until you delete them.', 'wp-cortex' ) );

	}

	/**
	 * Visitor chat actions section.
	 */
	public function fields_public_chat_actions(): void {
		$this->row_start( __( 'Assistant actions', 'wp-cortex' ) );
		$this->checkbox( 'public_chat_navigation', __( 'Take visitors to a page when they ask or confirm', 'wp-cortex' ), (bool) Settings::get( 'public_chat_navigation' ) );
		echo '<br />';
		$this->checkbox( 'public_chat_contact', __( 'Collect contact details from visitors who want to be contacted', 'wp-cortex' ), (bool) Settings::get( 'public_chat_contact' ) );
		$this->row_end( __( 'The assistant may offer to open a published page (for example the contact page) and opens it only after the visitor agrees. Contact details (name, email, phone, website URL(s), address, company and the request) are saved as soon as the visitor gives them, and the assistant repeats an email or phone number so the visitor can correct it; they require the conversation log.', 'wp-cortex' ) );

		$this->row_start( __( 'Issue reports', 'wp-cortex' ) );
		$this->checkbox( 'public_chat_reports', __( 'Let visitors report problems on the site', 'wp-cortex' ), (bool) Settings::get( 'public_chat_reports' ) );
		$this->row_end(
			sprintf(
				/* translators: %s: name of the Issue reports screen. */
				__( 'When a visitor points out a problem (for example a typo, a broken image or link, or outdated information), the assistant reports it with the page it is about. Reports are listed under %s, also when the conversation log is off.', 'wp-cortex' ),
				__( 'Cortex > Issue reports', 'wp-cortex' )
			)
		);

		$this->row_start( __( 'Email new reports to', 'wp-cortex' ) );
		printf(
			'<input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" />',
			$this->name( 'public_chat_report_email' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name().
			esc_attr( (string) Settings::get( 'public_chat_report_email' ) ),
			esc_attr__( 'name@example.com, other@example.com', 'wp-cortex' )
		);
		$this->row_end(
			sprintf(
				/* translators: %d: maximum number of addresses. */
				__( 'Optional. Up to %d addresses, separated by commas, notified of each new report through the site\'s mail setup. Leave empty to only list reports in the admin.', 'wp-cortex' ),
				IssueReportMailer::MAX_RECIPIENTS
			)
		);
	}

	/**
	 * Leads section: whether conversations with contact details are tracked as leads.
	 */
	public function fields_leads(): void {
		$this->row_start( __( 'Leads', 'wp-cortex' ) );
		$this->checkbox( 'leads_enabled', __( 'Track visitors who leave contact details as leads', 'wp-cortex' ), Settings::leads_enabled() );
		$this->row_end( __( 'Adds the Cortex > Leads screen (key figures, leads over time, lead quality and the leads list with status tracking, AI rating and CSV export) and the Lead card to visitor conversations. When off, the Leads screen, the lead status, AI rating and attribution are gone and nothing is recorded about how visitors found the site; contact details are still stored with the conversation. Requires the conversation log.', 'wp-cortex' ) );
	}

	/**
	 * Attribution section: what the widget records and how long the first visit is remembered.
	 */
	public function fields_leads_attribution(): void {
		$this->row_start( __( 'Attribution', 'wp-cortex' ) );
		$this->checkbox( 'leads_attribution', __( 'Record how visitors who chat arrived on the site', 'wp-cortex' ), (bool) Settings::get( 'leads_attribution' ) );
		$this->row_end( __( 'Stored with the conversation when the visitor writes in the chat: campaign parameters (utm_source, utm_medium, utm_campaign, utm_term, utm_content, utm_id), ad click IDs (gclid, gbraid, wbraid, msclkid, fbclid, li_fat_id, ttclid, twclid), the referring site, the landing page, the first and the latest visit, the pages viewed in the visit, the device type, browser language and, when the host or CDN reports it, the country. Query strings of URLs are never stored. Adds channels, sources, campaigns and landing pages to Cortex > Leads. Off by default: turn it on only if the site\'s privacy policy covers it. Requires leads and the conversation log.', 'wp-cortex' ) );

		$this->row_start( __( 'Remember the first visit', 'wp-cortex' ) );
		$this->number( 'leads_attribution_days', 0, 730 );
		echo ' ' . esc_html__( 'days', 'wp-cortex' );
		$this->row_end( __( 'The first visit (first touch) is kept in the browser\'s local storage for this many days, so a visitor who comes back later is still credited to the campaign that brought them. 0 keeps it for the browser session only (no local storage).', 'wp-cortex' ) );

		$this->row_start( __( 'Consent', 'wp-cortex' ) );
		$this->select(
			'leads_consent',
			array(
				'auto'    => __( 'Respect the consent manager; record when there is none', 'wp-cortex' ),
				'require' => __( 'Record only with marketing consent from a consent manager', 'wp-cortex' ),
				'ignore'  => __( 'Always record (ignore consent)', 'wp-cortex' ),
			)
		);
		$labels   = Attribution::consent_labels();
		$detected = ( new VisitorChatStore() )->latest_consent_source();
		echo '<p class="description">';
		if ( isset( $labels[ $detected ] ) ) {
			/* translators: %s: consent manager name, for example Osano. */
			echo esc_html( sprintf( __( 'Consent manager found by the chat on the site in the latest conversation: %s.', 'wp-cortex' ), $labels[ $detected ] ) );
		} else {
			esc_html_e( 'No conversation with attribution yet, so the consent manager on the site is not known.', 'wp-cortex' );
		}
		echo '</p>';
		$this->row_end( __( 'Osano, OneTrust, Cookiebot and banners that report through the WP Consent API plugin (for example Complianz or CookieYes) are recognized in the visitor\'s browser. With one of them, nothing is recorded, stored in the browser or sent until the visitor accepts marketing, recording starts when they accept on the page and everything stored in the browser is removed when they withdraw it. The chat works either way; conversations without consent appear under Unknown in Cortex > Leads. If the consent manager blocks scripts by category, classify the Cortex chat script as essential or functional, or the chat is blocked too.', 'wp-cortex' ) );

		$this->row_start( __( 'OneTrust marketing category', 'wp-cortex' ) );
		printf(
			'<input type="text" class="small-text" name="%1$s" value="%2$s" maxlength="32" />',
			$this->name( 'leads_onetrust_group' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name().
			esc_attr( (string) Settings::get( 'leads_onetrust_group' ) )
		);
		$this->row_end(
			sprintf(
				/* translators: %s: default OneTrust category ID. */
				__( 'Only with OneTrust: the ID of the cookie category that stands for marketing consent. %s ("Targeting cookies") by default; check the categories of the site\'s OneTrust script if they were changed.', 'wp-cortex' ),
				Attribution::ONETRUST_GROUP
			)
		);

		$this->row_start( __( 'Extra parameters', 'wp-cortex' ) );
		printf(
			'<input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" />',
			$this->name( 'leads_extra_params' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name().
			esc_attr( implode( ', ', (array) Settings::get( 'leads_extra_params' ) ) ),
			esc_attr__( 'ref, aff_id, promo', 'wp-cortex' )
		);
		$this->row_end(
			sprintf(
				/* translators: %d: maximum number of parameters. */
				__( 'Optional. Up to %d more URL parameters to record, separated by commas, for example partner or affiliate codes.', 'wp-cortex' ),
				Attribution::MAX_EXTRA_PARAMS
			)
		);
	}

	/**
	 * Integrations section: one card per integration and the latest deliveries.
	 */
	public function fields_integrations(): void {
		if ( ! Settings::leads_enabled() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Leads are off, so no lead is sent. Turn them on under Leads.', 'wp-cortex' ) . '</p></div>';
		}

		if ( Dispatcher::cron_disabled() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'WP-Cron is disabled on this site (DISABLE_WP_CRON), so leads are sent when the server\'s cron job runs.', 'wp-cortex' ) . '</p></div>';
		}

		$log = Dispatcher::entries();

		echo '<div class="wp-cortex-integrations">';
		foreach ( IntegrationRegistry::all() as $integration ) {
			$this->integration_card( $integration, $log );
		}
		echo '</div>';

		$this->integration_log( $log );

		echo '<p class="description">' . esc_html__( 'Developers can add their own integration with a PHP file in the theme\'s wp-cortex/integrations/ folder or the wp_cortex_lead_integrations filter (see docs/integrations.md in the plugin).', 'wp-cortex' ) . '</p>';
	}

	/**
	 * One integration: status, switch, events, its fields and the test button.
	 *
	 * @param Integration $integration Integration.
	 * @param array       $log         Latest deliveries.
	 */
	private function integration_card( Integration $integration, array $log ): void {
		$id       = $integration->id();
		$settings = IntegrationRegistry::settings( $integration );
		$ready    = $integration->is_configured( $settings );
		$name     = static fn( string $key ): string => esc_attr( Settings::OPTION . '[integrations][' . $id . '][' . $key . ']' );
		$last     = null;

		foreach ( $log as $entry ) {
			if ( $id === ( $entry['integration'] ?? '' ) && 'test' !== ( $entry['event'] ?? '' ) ) {
				$last = $entry;
				break;
			}
		}

		if ( ! $settings['enabled'] ) {
			$badge = array( '', __( 'Off', 'wp-cortex' ) );
		} elseif ( ! $ready ) {
			$badge = array( 'wp-cortex-badge-warn', __( 'Incomplete', 'wp-cortex' ) );
		} elseif ( $last && empty( $last['ok'] ) ) {
			$badge = array( 'wp-cortex-badge-failed', __( 'Failing', 'wp-cortex' ) );
		} else {
			$badge = array( 'wp-cortex-badge-ok', __( 'Active', 'wp-cortex' ) );
		}

		printf( '<details class="wp-cortex-integration" data-integration="%s"%s>', esc_attr( $id ), $settings['enabled'] ? ' open' : '' );
		printf(
			'<summary><span class="wp-cortex-integration-name">%1$s</span> <span class="wp-cortex-badge %2$s">%3$s</span><span class="wp-cortex-integration-description">%4$s</span></summary>',
			esc_html( $integration->label() ),
			esc_attr( $badge[0] ),
			esc_html( $badge[1] ),
			esc_html( $integration->description() )
		);
		echo '<div class="wp-cortex-integration-body">';
		printf( '<input type="hidden" name="%s" value="1" />', $name( 'shown' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $name().

		$this->row_start( __( 'Status', 'wp-cortex' ) );
		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			$name( 'enabled' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $name().
			checked( (bool) $settings['enabled'], true, false ),
			/* translators: %s: integration name. */
			esc_html( sprintf( __( 'Send leads to %s', 'wp-cortex' ), $integration->label() ) )
		);
		$this->row_end();

		$this->row_start( __( 'Send', 'wp-cortex' ) );
		$events = array(
			'created' => __( 'New leads', 'wp-cortex' ),
			'updated' => __( 'Changes to leads (contact details, status, AI rating)', 'wp-cortex' ),
		);
		foreach ( $events as $event => $label ) {
			printf(
				'<label class="wp-cortex-block"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
				$name( 'events' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $name().
				esc_attr( $event ),
				checked( in_array( $event, (array) $settings['events'], true ), true, false ),
				esc_html( $label )
			);
		}
		$this->row_end( __( 'A change sends the whole lead again with the same ID, so the receiver can update its record instead of creating a new one.', 'wp-cortex' ) );

		foreach ( $integration->fields() as $key => $field ) {
			$this->row_start( (string) ( $field['label'] ?? $key ) );
			$this->integration_field( $name( $key ), $field, $settings[ $key ] ?? '' );
			$this->row_end( (string) ( $field['description'] ?? '' ) );
		}

		$this->row_start( __( 'Test', 'wp-cortex' ) );
		echo '<button type="button" class="button wp-cortex-integration-test">' . esc_html__( 'Send test lead', 'wp-cortex' ) . '</button> <span class="wp-cortex-integration-result" aria-live="polite"></span>';
		$this->row_end( __( 'Sends a made-up lead (marked "test": true) with the values above, saved or not.', 'wp-cortex' ) );

		echo '</div></details>';
	}

	/**
	 * Input of an integration field.
	 *
	 * @param string $name  Escaped field name.
	 * @param array  $field Field definition.
	 * @param mixed  $value Current value.
	 */
	private function integration_field( string $name, array $field, $value ): void {
		$type        = (string) ( $field['type'] ?? 'text' );
		$placeholder = (string) ( $field['placeholder'] ?? '' );

		switch ( $type ) {
			case 'checkbox':
				printf( '<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>', $name, checked( (bool) $value, true, false ), esc_html( (string) ( $field['label'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
				break;
			case 'textarea':
				printf( '<textarea class="large-text" rows="3" name="%1$s" placeholder="%2$s">%3$s</textarea>', $name, esc_attr( $placeholder ), esc_textarea( (string) $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
				break;
			case 'select':
				printf( '<select name="%s">', $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
				foreach ( (array) ( $field['options'] ?? array() ) as $option => $label ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( (string) $option ), selected( (string) $value, (string) $option, false ), esc_html( (string) $label ) );
				}
				echo '</select>';
				break;
			case 'secret':
				printf(
					'<input type="password" class="regular-text" name="%1$s" value="" autocomplete="new-password" placeholder="%2$s" />',
					$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
					esc_attr( '' !== (string) $value ? __( 'Saved; leave empty to keep it', 'wp-cortex' ) : $placeholder )
				);
				break;
			default:
				printf( '<input type="%1$s" class="regular-text" name="%2$s" value="%3$s" placeholder="%4$s" />', 'url' === $type ? 'url' : 'text', $name, esc_attr( (string) $value ), esc_attr( $placeholder ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
		}
	}

	/**
	 * The latest deliveries of all integrations.
	 *
	 * @param array $log Latest deliveries.
	 */
	private function integration_log( array $log ): void {
		echo '<h3>' . esc_html__( 'Latest deliveries', 'wp-cortex' ) . '</h3>';

		if ( ! $log ) {
			echo '<p class="wp-cortex-muted">' . esc_html__( 'Nothing sent yet.', 'wp-cortex' ) . '</p>';
			return;
		}

		$events = array(
			'created' => __( 'New lead', 'wp-cortex' ),
			'updated' => __( 'Lead changed', 'wp-cortex' ),
			'test'    => __( 'Test', 'wp-cortex' ),
		);

		echo '<table class="wp-list-table widefat fixed striped wp-cortex-integration-log"><thead><tr>';
		foreach ( array( __( 'Time (UTC)', 'wp-cortex' ), __( 'Integration', 'wp-cortex' ), __( 'Lead', 'wp-cortex' ), __( 'Event', 'wp-cortex' ), __( 'Result', 'wp-cortex' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( array_slice( $log, 0, 20 ) as $entry ) {
			$integration = IntegrationRegistry::get( (string) ( $entry['integration'] ?? '' ) );
			$lead        = (int) ( $entry['lead'] ?? 0 );
			$attempt     = (int) ( $entry['attempt'] ?? 1 );

			if ( ! empty( $entry['ok'] ) ) {
				$result = '<span class="wp-cortex-badge wp-cortex-badge-ok">' . esc_html__( 'Sent', 'wp-cortex' ) . '</span>';
			} else {
				$result = '<span class="wp-cortex-badge wp-cortex-badge-failed">' . esc_html__( 'Failed', 'wp-cortex' ) . '</span> ' . esc_html( (string) ( $entry['message'] ?? '' ) );

				if ( empty( $entry['final'] ) ) {
					$result .= ' <span class="wp-cortex-muted">' . esc_html__( '(will retry)', 'wp-cortex' ) . '</span>';
				}
			}

			if ( $attempt > 1 ) {
				/* translators: %d: attempt number. */
				$result .= ' <span class="wp-cortex-muted">' . esc_html( sprintf( __( 'attempt %d', 'wp-cortex' ), $attempt ) ) . '</span>';
			}

			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( (string) ( $entry['at'] ?? '' ) ),
				esc_html( $integration ? $integration->label() : (string) ( $entry['integration'] ?? '' ) ),
				$lead ? '<a href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_VISITORS . '#chat=' . $lead ) ) . '">#' . esc_html( (string) $lead ) . '</a>' : '–',
				esc_html( $events[ $entry['event'] ?? '' ] ?? (string) ( $entry['event'] ?? '' ) ),
				$result // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Admin chat tools section.
	 */
	public function fields_tools_admin(): void {
		$this->tools_table( 'admin', ChatAgent::tool_catalog() );
	}

	/**
	 * Visitor chat tools section.
	 */
	public function fields_tools_public(): void {
		$this->tools_table( 'public', PublicChatAgent::tool_catalog() );
	}

	/**
	 * Table of a chat's tools with a switch and a per-message limit for each.
	 *
	 * @param string $scope Chat: "admin" or "public".
	 * @param array  $rows  Tools from ToolRegistry::catalog().
	 */
	private function tools_table( string $scope, array $rows ): void {
		$field   = Settings::OPTION . '[chat_tools][' . $scope . ']';
		$sources = array(
			'builtin' => __( 'Cortex', 'wp-cortex' ),
			'theme'   => __( 'Theme', 'wp-cortex' ),
			'plugin'  => __( 'Plugin', 'wp-cortex' ),
		);

		echo '<table class="wp-list-table widefat fixed striped wp-cortex-tools-table"><thead><tr>';
		echo '<th scope="col" class="wp-cortex-col-on">' . esc_html__( 'On', 'wp-cortex' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Tool', 'wp-cortex' ) . '</th>';
		echo '<th scope="col" class="wp-cortex-col-source">' . esc_html__( 'Source', 'wp-cortex' ) . '</th>';
		echo '<th scope="col" class="wp-cortex-col-source">' . esc_html__( 'Kind', 'wp-cortex' ) . '</th>';
		echo '<th scope="col" class="wp-cortex-col-limit">' . esc_html__( 'Limit per message', 'wp-cortex' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $name => $row ) {
			$source = $sources[ $row['source'] ] ?? $row['source'];
			$kind   = $row['ability']
				? array( __( 'Ability', 'wp-cortex' ), __( 'A WordPress ability: also available outside the chat, for example to MCP clients.', 'wp-cortex' ) )
				: array( __( 'Chat tool', 'wp-cortex' ), __( 'Only works inside the chat panel.', 'wp-cortex' ) );

			echo '<tr>';
			printf(
				'<td class="wp-cortex-col-on"><input type="hidden" name="%1$s" value="%2$s" /><input type="checkbox" id="%3$s" name="%4$s" value="%2$s" %5$s /></td>',
				esc_attr( $field . '[known][]' ),
				esc_attr( $name ),
				esc_attr( 'wp-cortex-tool-' . $scope . '-' . $name ),
				esc_attr( $field . '[on][]' ),
				checked( Settings::tool_enabled( $scope, $name ), true, false )
			);
			printf(
				'<td><label for="%1$s"><strong>%2$s</strong></label> <code>%3$s</code>%4$s%5$s</td>',
				esc_attr( 'wp-cortex-tool-' . $scope . '-' . $name ),
				esc_html( $row['label'] ),
				esc_html( $name ),
				'' !== $row['description'] ? '<p class="description">' . esc_html( wp_trim_words( $row['description'], 30 ) ) . '</p>' : '',
				'' !== $row['note'] ? '<p class="description wp-cortex-tool-note">' . esc_html( $row['note'] ) . '</p>' : ''
			);
			printf(
				'<td class="wp-cortex-col-source"><span class="wp-cortex-badge">%1$s</span>%2$s</td>',
				esc_html( $source ),
				'' !== $row['changed'] ? '<br /><small>' . esc_html( sprintf( /* translators: %s: Theme or Plugin. */ __( 'changed by: %s', 'wp-cortex' ), strtolower( $sources[ $row['changed'] ] ?? $row['changed'] ) ) ) . '</small>' : ''
			);
			printf(
				'<td class="wp-cortex-col-source"><span class="wp-cortex-badge" title="%2$s">%1$s</span></td>',
				esc_html( $kind[0] ),
				esc_attr( $kind[1] )
			);
			echo '<td class="wp-cortex-col-limit">';
			$this->tool_limit_input( $scope, $name );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">';
		printf(
			/* translators: %s: theme folder. */
			esc_html__( 'A theme adds or changes tools with PHP files in %s; plugins use a filter. See docs/chat-tools.md in the plugin.', 'wp-cortex' ),
			'<code>' . esc_html( 'wp-cortex/tools/' . $scope . '/' ) . '</code>'
		);
		echo '</p>';
	}

	/**
	 * Per-message limit input of a tool.
	 *
	 * @param string $scope Chat: "admin" or "public".
	 * @param string $name  Function name.
	 */
	private function tool_limit_input( string $scope, string $name ): void {
		printf(
			'<input type="number" class="small-text" name="%1$s" value="%2$d" min="0" max="%3$d" step="1" aria-label="%4$s" />',
			esc_attr( Settings::OPTION . '[chat_tools][' . $scope . '][limit][' . $name . ']' ),
			(int) Settings::tool_limit( $scope, $name ),
			(int) Settings::TOOL_LIMIT_MAX,
			/* translators: %s: tool function name. */
			esc_attr( sprintf( __( 'Limit per message for %s', 'wp-cortex' ), $name ) )
		);
	}

	/**
	 * WordPress abilities section: every registered ability of other plugins with a switch,
	 * its kind and a per-message limit.
	 */
	public function fields_abilities(): void {
		$rows = ChatAgent::ability_catalog();

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			echo '<p>' . esc_html__( 'The WordPress Abilities API is not available on this site.', 'wp-cortex' ) . '</p>';
			return;
		}

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No other plugin has registered abilities yet. The abilities of Cortex itself are listed under Admin chat tools.', 'wp-cortex' ) . '</p>';
			return;
		}

		$allowed = Settings::chat_abilities();
		$field   = Settings::OPTION . '[chat_abilities]';

		echo '<table class="wp-list-table widefat fixed striped wp-cortex-tools-table"><thead><tr>';
		echo '<th scope="col" class="wp-cortex-col-on">' . esc_html__( 'On', 'wp-cortex' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Ability', 'wp-cortex' ) . '</th>';
		echo '<th scope="col" class="wp-cortex-col-source">' . esc_html__( 'Category', 'wp-cortex' ) . '</th>';
		echo '<th scope="col" class="wp-cortex-col-source">' . esc_html__( 'Effect', 'wp-cortex' ) . '</th>';
		echo '<th scope="col" class="wp-cortex-col-limit">' . esc_html__( 'Limit per message', 'wp-cortex' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $name => $row ) {
			$id = 'wp-cortex-ability-' . sanitize_html_class( str_replace( '/', '-', $name ) );

			if ( $row['read_only'] ) {
				$kind = '<span class="wp-cortex-badge wp-cortex-badge-ok">' . esc_html__( 'Read-only', 'wp-cortex' ) . '</span>';
			} elseif ( $row['destructive'] ) {
				$kind = '<span class="wp-cortex-badge wp-cortex-badge-failed">' . esc_html__( 'Destructive', 'wp-cortex' ) . '</span>';
			} else {
				$kind = '<span class="wp-cortex-badge wp-cortex-badge-warn">' . esc_html__( 'Changes the site', 'wp-cortex' ) . '</span>';
			}

			echo '<tr>';
			printf(
				'<td class="wp-cortex-col-on"><input type="hidden" name="%1$s" value="%2$s" /><input type="checkbox" id="%3$s" name="%4$s" value="%2$s" %5$s %6$s /></td>',
				esc_attr( $field . '[known][]' ),
				esc_attr( $name ),
				esc_attr( $id ),
				esc_attr( $field . '[on][]' ),
				checked( in_array( $name, $allowed, true ), true, false ),
				disabled( $row['usable'], false, false )
			);
			printf(
				'<td><label for="%1$s"><strong>%2$s</strong></label> <code>%3$s</code>%4$s%5$s</td>',
				esc_attr( $id ),
				esc_html( $row['label'] ),
				esc_html( $name ),
				'' !== $row['description'] ? '<p class="description">' . esc_html( wp_trim_words( $row['description'], 30 ) ) . '</p>' : '',
				$row['usable'] ? '' : '<p class="description wp-cortex-tool-note">' . esc_html__( 'The name is too long to be offered to AI models.', 'wp-cortex' ) . '</p>'
			);
			echo '<td class="wp-cortex-col-source">' . esc_html( $row['category'] ) . '</td>';
			echo '<td class="wp-cortex-col-source">' . $kind . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from escaped strings.
			echo '<td class="wp-cortex-col-limit">';
			printf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( Settings::OPTION . '[chat_tools][admin][limit_known][]' ), esc_attr( $row['function'] ) );
			$this->tool_limit_input( 'admin', $row['function'] );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Reasoning level select, shown next to the model when the provider supports it.
	 *
	 * Every level is rendered with the providers that support it; settings.js shows only
	 * those of the selected provider.
	 *
	 * @param string   $key       Setting key.
	 * @param string[] $providers Registered provider IDs.
	 * @param string   $provider  Selected provider ID.
	 * @param string   $model     Saved model ID.
	 */
	private function reasoning_select( string $key, array $providers, string $provider, string $model ): void {
		$saved     = (string) Settings::get( $key );
		$labels    = Reasoning::labels();
		$available = '' !== $model && Reasoning::levels( $provider );

		printf(
			'<label class="wp-cortex-reasoning" %s>%s ',
			$available ? '' : 'hidden',
			esc_html__( 'Reasoning', 'wp-cortex' )
		);
		printf(
			'<select name="%s" class="wp-cortex-reasoning-select" %s>',
			$this->name( $key ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name().
			$available ? '' : 'disabled'
		);
		printf( '<option value="" %s>%s</option>', selected( $saved, '', false ), esc_html__( 'Model default', 'wp-cortex' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( Reasoning::level_providers( $providers ) as $level => $level_providers ) {
			$supported = in_array( $provider, $level_providers, true );
			printf(
				'<option value="%1$s" data-providers="%2$s" %3$s %4$s>%5$s</option>',
				esc_attr( $level ),
				esc_attr( implode( ' ', $level_providers ) ),
				selected( $saved, $level, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$supported ? '' : 'hidden disabled',
				esc_html( $labels[ $level ] ?? $level )
			);
		}
		echo '</select></label> ';
	}
}
