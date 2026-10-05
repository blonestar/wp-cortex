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
use WPCortex\Chat\Reasoning;
use WPCortex\Chat\VisitorChatSummarizer;
use WPCortex\Chat\VisitorImages;
use WPCortex\Embeddings\OpenAIEmbeddings;
use WPCortex\Frontend\ChatAppearance;
use WPCortex\Settings;

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
	 * Each section (keyed by ID) is a heading, an intro and the method rendering its fields.
	 * A tab with more than one section shows them as vertical sub-tabs. A read-only tab
	 * has a `render` callback instead of sections and hides the save bar.
	 *
	 * @return array<string, array{label: string, icon: string, sections?: array<string, array{0: string, 1: string, 2: callable}>, render?: callable}>
	 */
	private function tabs(): array {
		return array(
			'content'    => array(
				'label'    => __( 'Content', 'wp-cortex' ),
				'icon'     => 'dashicons-admin-page',
				'sections' => array(
					'indexed' => array( __( 'What gets indexed', 'wp-cortex' ), __( 'Choose the content Cortex keeps in its index and whether it stays in sync automatically.', 'wp-cortex' ), array( $this, 'fields_content' ) ),
					'sources' => array( __( 'Data sources', 'wp-cortex' ), __( 'Extra fields from plugins and post meta added to the indexed content.', 'wp-cortex' ), array( $this, 'fields_sources' ) ),
				),
			),
			'indexing'   => array(
				'label'    => __( 'Indexing', 'wp-cortex' ),
				'icon'     => 'dashicons-database',
				'sections' => array(
					'chunking' => array( __( 'Chunking & batching', 'wp-cortex' ), __( 'How content is split into chunks for search and how many posts are processed per request.', 'wp-cortex' ), array( $this, 'fields_chunking' ) ),
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
			'changelog'  => array(
				'label'  => __( 'Changelog', 'wp-cortex' ),
				'icon'   => 'dashicons-backup',
				'render' => array( new Changelog(), 'render' ),
			),
		);
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

		printf( '<div class="wp-cortex-settings-submit" %s>', isset( $tabs[ $active ]['render'] ) ? 'hidden' : '' );
		submit_button( __( 'Save settings', 'wp-cortex' ), 'primary', 'submit', false );
		echo '</div>';
		echo '</form></div>';
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
					'<a href="%1$s" class="wp-cortex-subtab%2$s" id="wp-cortex-subtab-%3$s-%4$s" role="tab" aria-controls="wp-cortex-section-%3$s-%4$s" aria-selected="%5$s" data-section="%4$s">%6$s</a>',
					esc_url( add_query_arg( array( 'tab' => $tab, 'section' => $id ), admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS ) ) ),
					$active === $id ? ' is-active' : '',
					esc_attr( $tab ),
					esc_attr( $id ),
					$active === $id ? 'true' : 'false',
					esc_html( $section[0] )
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
		$indexing_url = admin_url( 'admin.php?page=' . Menu::SLUG_INDEXING );

		echo '<div class="notice notice-info inline wp-cortex-settings-notice"><p>';
		printf(
			/* translators: %s: link to the Indexing screen. */
			esc_html__( 'Changing the embedding model, dimensions or chunking settings requires re-indexing. Use %s and choose "Rebuild from scratch".', 'wp-cortex' ),
			'<a href="' . esc_url( $indexing_url ) . '">' . esc_html__( 'the Indexing screen', 'wp-cortex' ) . '</a>'
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
	 * Content section.
	 */
	public function fields_content(): void {
		$selected_types = (array) Settings::get( 'post_types' );
		$types          = get_post_types( array( 'show_ui' => true ), 'objects' );

		$this->row_start( __( 'Post types', 'wp-cortex' ) );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Post types', 'wp-cortex' ) . '</legend>';
		foreach ( $types as $slug => $type ) {
			if ( in_array( $slug, self::EXCLUDED_POST_TYPES, true ) ) {
				continue;
			}
			printf(
				'<label class="wp-cortex-block"><input type="checkbox" name="%1$s" value="%2$s" %3$s /> %4$s <code>%2$s</code></label>',
				$this->name( 'post_types', true ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_attr( $slug ),
				checked( in_array( $slug, $selected_types, true ), true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $type->labels->name ?? $slug )
			);
		}
		echo '</fieldset>';
		$this->row_end( __( 'Content of these post types is indexed.', 'wp-cortex' ) );

		$this->row_start( __( 'Media', 'wp-cortex' ) );
		$this->checkbox( 'index_media', __( 'Index media library files (title, caption, alt text, description and file details)', 'wp-cortex' ), (bool) Settings::get( 'index_media' ) );
		$this->row_end( __( 'Media inherit the status of the post they are attached to; unattached media count as published. File contents (for example PDF text) are not extracted.', 'wp-cortex' ) );

		$selected_statuses = Settings::admin_statuses();
		$this->row_start( __( 'Admin index statuses', 'wp-cortex' ) );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Admin index statuses', 'wp-cortex' ) . '</legend>';
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
		$this->row_end( __( 'Statuses included in the admin index. The public index only contains published content, so "Published" is always on.', 'wp-cortex' ) );

		$this->row_start( __( 'Auto sync', 'wp-cortex' ) );
		$this->checkbox( 'auto_sync', __( 'Keep the index updated automatically when content changes', 'wp-cortex' ), (bool) Settings::get( 'auto_sync' ) );
		$this->row_end();
	}

	/**
	 * Data sources section.
	 */
	public function fields_sources(): void {
		$this->row_start( __( 'Yoast SEO', 'wp-cortex' ) );
		$this->checkbox( 'index_yoast', __( 'Index Yoast SEO fields (title, description, focus keyword)', 'wp-cortex' ), (bool) Settings::get( 'index_yoast' ) );
		$this->row_end( defined( 'WPSEO_VERSION' ) ? '' : __( 'Yoast SEO not detected.', 'wp-cortex' ) );

		$this->row_start( __( 'Advanced Custom Fields', 'wp-cortex' ) );
		$this->checkbox( 'index_acf', __( 'Index ACF fields', 'wp-cortex' ), (bool) Settings::get( 'index_acf' ) );
		echo '<br />';
		$this->checkbox( 'acf_public', __( 'Include ACF text in the public index', 'wp-cortex' ), (bool) Settings::get( 'acf_public' ) );
		$this->row_end( function_exists( 'get_field_objects' ) ? '' : __( 'Advanced Custom Fields not detected.', 'wp-cortex' ) );

		$meta_keys = implode( "\n", array_map( 'strval', (array) Settings::get( 'meta_keys' ) ) );
		$this->row_start( __( 'Custom meta keys', 'wp-cortex' ) );
		printf(
			'<textarea name="%1$s" rows="5" class="large-text code">%2$s</textarea>',
			$this->name( 'meta_keys' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_textarea( $meta_keys )
		);
		$this->row_end( __( 'One meta key per line. Indexed in the admin index only.', 'wp-cortex' ) );
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

		$this->row_start( __( 'Message limit', 'wp-cortex' ) );
		$this->number( 'public_chat_rate_limit', 1, 1000 );
		echo ' ' . esc_html__( 'messages per visitor per hour', 'wp-cortex' );
		$this->row_end( __( 'Protects your AI provider account from abuse. Visitors are counted by IP address.', 'wp-cortex' ) );

		$this->row_start( __( 'Images', 'wp-cortex' ) );
		$this->checkbox( 'public_chat_images', __( 'Let visitors attach images, for example a screenshot of a problem', 'wp-cortex' ), (bool) Settings::get( 'public_chat_images' ) );
		echo '<br /><label>' . esc_html__( 'At most', 'wp-cortex' ) . ' ';
		$this->number( 'public_chat_image_limit', 1, 1000 );
		echo ' ' . esc_html__( 'images per visitor per hour', 'wp-cortex' ) . '</label>';
		if ( ! VisitorImages::is_supported() ) {
			echo '<p class="description"><strong>' . esc_html__( 'Images are not available: the PHP GD extension with PNG and JPEG support is required.', 'wp-cortex' ) . '</strong></p>';
		}
		$this->row_end( __( 'Visitors can paste a screenshot, drop an image on the chat or pick one with the attach button. Images are scaled down to 1600 pixels, stripped of metadata and sent to the AI model, which must support image input (for example current OpenAI, Anthropic and Google models). While the conversation log is on, they are stored with the conversation in the protected data directory, shown under Cortex > Visitor chats, attached to forwarded emails and deleted with the conversation.', 'wp-cortex' ) );

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
