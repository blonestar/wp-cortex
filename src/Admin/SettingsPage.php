<?php
/**
 * Settings screen (Settings API).
 *
 * @package WPCortex
 */

namespace WPCortex\Admin;

use WPCortex\Chat\ChatAgent;
use WPCortex\Chat\ModelCatalog;
use WPCortex\Chat\Reasoning;
use WPCortex\Embeddings\OpenAIEmbeddings;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the plugin settings.
 */
final class SettingsPage {

	private const GROUP = 'wp_cortex';
	private const PAGE  = 'wp-cortex';

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

		$page = new self();

		$sections = array(
			'content'   => array( __( 'Content', 'wp-cortex' ), array( $page, 'fields_content' ) ),
			'sources'   => array( __( 'Data sources', 'wp-cortex' ), array( $page, 'fields_sources' ) ),
			'chunking'  => array( __( 'Chunking & batching', 'wp-cortex' ), array( $page, 'fields_chunking' ) ),
			'embedding' => array( __( 'Embeddings', 'wp-cortex' ), array( $page, 'fields_embeddings' ) ),
			'chat'      => array( __( 'Chat', 'wp-cortex' ), array( $page, 'fields_chat' ) ),
		);

		foreach ( $sections as $id => $section ) {
			add_settings_section( 'wp_cortex_' . $id, $section[0], '__return_empty_string', self::PAGE );
			add_settings_field( 'wp_cortex_' . $id . '_fields', '', $section[1], self::PAGE, 'wp_cortex_' . $id, array( 'class' => 'wp-cortex-section-fields' ) );
		}
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$indexing_url = admin_url( 'admin.php?page=' . Menu::SLUG_INDEXING );

		echo '<div class="wrap wp-cortex-wrap">';
		echo '<h1>' . esc_html__( 'Cortex Settings', 'wp-cortex' ) . '</h1>';
		settings_errors();

		echo '<div class="notice notice-info inline"><p>';
		printf(
			/* translators: %s: link to the Indexing screen. */
			esc_html__( 'Changing the embedding model, dimensions or chunking settings requires re-indexing. Use %s and choose "Rebuild from scratch".', 'wp-cortex' ),
			'<a href="' . esc_url( $indexing_url ) . '">' . esc_html__( 'the Indexing screen', 'wp-cortex' ) . '</a>'
		);
		echo '</p></div>';

		echo '<form action="options.php" method="post">';
		settings_fields( self::GROUP );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form></div>';
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

		$this->row_start( __( 'Embeddings', 'wp-cortex' ) );
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

		$this->row_start( __( 'Admin chat', 'wp-cortex' ) );
		$this->checkbox( 'chat_enabled', __( 'Show the Cortex chat assistant in the admin', 'wp-cortex' ), (bool) Settings::get( 'chat_enabled' ) );
		$this->row_end();

		$this->row_start( __( 'AI provider', 'wp-cortex' ) );
		printf( '<select name="%s">', $this->name( 'chat_provider' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<option value="" %s>%s</option>', selected( $selected, '', false ), esc_html__( 'Automatic', 'wp-cortex' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
		$this->row_end( __( 'Automatic uses any configured provider that supports text generation with tool calls.', 'wp-cortex' ) );

		$saved     = (string) Settings::get( 'chat_model' );
		$cached    = '' !== $selected ? ( new ModelCatalog() )->cached( $selected ) : null;
		$has_tools = array_filter( (array) $cached, static fn( array $m ) => $m['tools'] );

		$this->row_start( __( 'Model', 'wp-cortex' ) );
		printf(
			'<div class="wp-cortex-model-picker" id="wp-cortex-model-picker" data-saved="%s">',
			esc_attr( $saved )
		);
		printf(
			'<input type="search" class="wp-cortex-model-filter" id="wp-cortex-model-filter" placeholder="%s" aria-label="%s" hidden />',
			esc_attr__( 'Filter models…', 'wp-cortex' ),
			esc_attr__( 'Filter models', 'wp-cortex' )
		);
		printf( '<select name="%s" id="wp-cortex-chat-model" class="wp-cortex-model-select">', $this->name( 'chat_model' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
		$this->reasoning_select( array_keys( $providers ), $selected, $saved );
		printf(
			'<button type="button" class="button wp-cortex-model-refresh" id="wp-cortex-model-refresh" hidden><span class="dashicons dashicons-update" aria-hidden="true"></span> %s</button>',
			esc_html__( 'Refresh models', 'wp-cortex' )
		);
		echo ' <span class="wp-cortex-model-status" id="wp-cortex-model-status" role="status" aria-live="polite"></span>';
		echo '</div>';
		$this->row_end( __( 'Optional. Models are loaded from the selected provider; those without tool calling cannot be used by the chat. Leave on "Provider default" to let the provider choose. Reasoning sets how much the model thinks before answering (more is slower and costs more); it is shown once a model is chosen, and a level the model does not support makes the request fail.', 'wp-cortex' ) );

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
	 * Reasoning level select, shown next to the model when the provider supports it.
	 *
	 * Every level is rendered with the providers that support it; settings.js shows only
	 * those of the selected provider.
	 *
	 * @param string[] $providers Registered provider IDs.
	 * @param string   $provider  Selected provider ID.
	 * @param string   $model     Saved model ID.
	 */
	private function reasoning_select( array $providers, string $provider, string $model ): void {
		$saved     = (string) Settings::get( 'chat_reasoning' );
		$labels    = Reasoning::labels();
		$available = '' !== $model && Reasoning::levels( $provider );

		printf(
			'<label class="wp-cortex-reasoning" id="wp-cortex-reasoning" %s>%s ',
			$available ? '' : 'hidden',
			esc_html__( 'Reasoning', 'wp-cortex' )
		);
		printf(
			'<select name="%s" id="wp-cortex-chat-reasoning" %s>',
			$this->name( 'chat_reasoning' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name().
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
