<?php
/**
 * Indexing screen: storage info, stats and the batch-driven indexing UI.
 *
 * @package WPCortex
 */

namespace WPCortex\Admin;

use WPCortex\Embeddings\OpenAIEmbeddings;
use WPCortex\Settings;
use WPCortex\Storage\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered shell; assets/js/indexing.js fills in the data.
 */
final class IndexingPage {

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap wp-cortex-wrap" id="wp-cortex-indexing">';
		echo '<h1>' . esc_html__( 'Cortex Indexing', 'wp-cortex' ) . '</h1>';

		$this->render_storage();
		$this->render_embeddings();
		$this->render_progress();
		$this->render_stats();
		$this->render_last_sync();

		echo '</div>';
	}

	/**
	 * Storage card with location and exposure warnings.
	 */
	private function render_storage(): void {
		echo '<div class="wp-cortex-card"><h2>' . esc_html__( 'Storage', 'wp-cortex' ) . '</h2>';
		echo '<p class="wp-cortex-data-dir">' . esc_html__( 'Data directory:', 'wp-cortex' ) . ' ';
		echo '<code class="wp-cortex-data-dir-mask" aria-hidden="true">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</code>';
		echo '<code id="wp-cortex-data-dir-path" hidden>' . esc_html( Storage::data_dir() ) . '</code> ';
		echo '<button type="button" class="button-link wp-cortex-data-dir-toggle" id="wp-cortex-data-dir-toggle" aria-controls="wp-cortex-data-dir-path" aria-expanded="false" aria-label="' . esc_attr__( 'Show data directory', 'wp-cortex' ) . '" title="' . esc_attr__( 'Show data directory', 'wp-cortex' ) . '">';
		echo '<span class="dashicons dashicons-visibility" aria-hidden="true"></span>';
		echo '</button></p>';

		if ( Storage::is_in_uploads() ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'The index is stored inside the uploads directory. For better protection, define WP_CORTEX_DATA_DIR in wp-config.php with a path outside the web root.', 'wp-cortex' );
			echo '</p></div>';
		}

		if ( true === Storage::is_exposed() ) {
			echo '<div class="notice notice-error inline"><p><strong>';
			echo esc_html__( 'The index database is publicly downloadable!', 'wp-cortex' );
			echo '</strong> ';
			echo esc_html__( 'Block access to the data directory in your web server configuration, or move it outside the web root with WP_CORTEX_DATA_DIR.', 'wp-cortex' );
			echo '</p></div>';
		}

		echo '</div>';
	}

	/**
	 * Embeddings status line.
	 */
	private function render_embeddings(): void {
		$enabled = (bool) Settings::get( 'embeddings_enabled' );
		$has_key = '' !== OpenAIEmbeddings::api_key();

		echo '<p class="wp-cortex-embeddings-status">';
		echo '<strong>' . esc_html__( 'Embeddings:', 'wp-cortex' ) . '</strong> ';

		if ( ! $enabled ) {
			echo esc_html__( 'disabled', 'wp-cortex' );
		} else {
			printf(
				/* translators: 1: embedding model, 2: number of dimensions. */
				esc_html__( 'enabled (%1$s, %2$d dimensions)', 'wp-cortex' ),
				esc_html( (string) Settings::get( 'embedding_model' ) ),
				(int) Settings::get( 'embedding_dimensions' )
			);
			echo ' &middot; ';
			if ( $has_key ) {
				echo esc_html__( 'API key configured', 'wp-cortex' );
			} else {
				echo '<span class="wp-cortex-badge wp-cortex-badge-warn">' . esc_html__( 'No API key', 'wp-cortex' ) . '</span> ';
				echo '<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . esc_html__( 'Add one under Settings > Connectors', 'wp-cortex' ) . '</a>';
			}
		}

		echo ' &middot; <a href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS ) ) . '">' . esc_html__( 'Change settings', 'wp-cortex' ) . '</a>';
		echo '</p>';
	}

	/**
	 * Progress, controls and error list.
	 */
	private function render_progress(): void {
		?>
		<div class="wp-cortex-card">
			<h2>
				<?php esc_html_e( 'Indexing', 'wp-cortex' ); ?>
				<span class="wp-cortex-badge wp-cortex-badge-idle" id="wp-cortex-status"><?php esc_html_e( 'Loading…', 'wp-cortex' ); ?></span>
			</h2>

			<div class="wp-cortex-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-labelledby="wp-cortex-progress-text" id="wp-cortex-progress">
				<div class="wp-cortex-progress-bar" id="wp-cortex-progress-bar"></div>
			</div>
			<p class="wp-cortex-progress-text" id="wp-cortex-progress-text" aria-live="polite">&nbsp;</p>

			<dl class="wp-cortex-counters">
				<?php
				$counters = array(
					'indexed'         => __( 'Indexed', 'wp-cortex' ),
					'skipped'         => __( 'Skipped', 'wp-cortex' ),
					'removed'         => __( 'Removed', 'wp-cortex' ),
					'failed'          => __( 'Failed', 'wp-cortex' ),
					'embedded_chunks' => __( 'Embedded chunks', 'wp-cortex' ),
					'tokens'          => __( 'Tokens', 'wp-cortex' ),
					'elapsed'         => __( 'Elapsed', 'wp-cortex' ),
					'eta'             => __( 'ETA', 'wp-cortex' ),
				);
				foreach ( $counters as $key => $label ) {
					printf(
						'<div class="wp-cortex-counter"><dt>%1$s</dt><dd data-counter="%2$s">&ndash;</dd></div>',
						esc_html( $label ),
						esc_attr( $key )
					);
				}
				?>
			</dl>

			<p class="wp-cortex-eligible" id="wp-cortex-eligible"></p>

			<p class="wp-cortex-actions">
				<button type="button" class="button button-primary" id="wp-cortex-sync" disabled><?php esc_html_e( 'Sync index', 'wp-cortex' ); ?></button>
				<button type="button" class="button button-secondary" id="wp-cortex-rebuild" disabled><?php esc_html_e( 'Rebuild from scratch', 'wp-cortex' ); ?></button>
				<button type="button" class="button" id="wp-cortex-resume" hidden><?php esc_html_e( 'Resume', 'wp-cortex' ); ?></button>
				<button type="button" class="button" id="wp-cortex-cancel" hidden><?php esc_html_e( 'Cancel', 'wp-cortex' ); ?></button>
			</p>
			<p class="description">
				<?php esc_html_e( 'Sync only re-processes content that has changed. Rebuild deletes both databases and indexes everything again.', 'wp-cortex' ); ?>
			</p>

			<div class="notice notice-error inline" id="wp-cortex-request-error" hidden><p></p></div>

			<div id="wp-cortex-errors-wrap" hidden>
				<h3><?php esc_html_e( 'Errors', 'wp-cortex' ); ?></h3>
				<ul class="wp-cortex-errors" id="wp-cortex-errors"></ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Stats grid for both indexes.
	 */
	private function render_stats(): void {
		$scopes = array(
			Storage::SCOPE_PUBLIC => __( 'Public index', 'wp-cortex' ),
			Storage::SCOPE_ADMIN  => __( 'Admin index', 'wp-cortex' ),
		);

		$rows = array(
			'documents' => __( 'Documents', 'wp-cortex' ),
			'chunks'    => __( 'Chunks', 'wp-cortex' ),
			'embedded'  => __( 'Embedded chunks', 'wp-cortex' ),
			'fields'    => __( 'Structured fields', 'wp-cortex' ),
			'size'      => __( 'Size', 'wp-cortex' ),
			'updated'   => __( 'Last update', 'wp-cortex' ),
		);

		echo '<div class="wp-cortex-stats-grid">';
		foreach ( $scopes as $scope => $title ) {
			echo '<div class="wp-cortex-card" data-stats-scope="' . esc_attr( $scope ) . '">';
			echo '<h2>' . esc_html( $title ) . '</h2>';
			echo '<p class="wp-cortex-stats-error" data-stat-error hidden></p>';
			echo '<dl class="wp-cortex-stats">';
			foreach ( $rows as $key => $label ) {
				printf(
					'<div class="wp-cortex-stat"><dt>%1$s</dt><dd data-stat="%2$s">&ndash;</dd></div>',
					esc_html( $label ),
					esc_attr( $key )
				);
			}
			echo '</dl>';
			echo '<h3>' . esc_html__( 'By post type', 'wp-cortex' ) . '</h3>';
			echo '<ul class="wp-cortex-by-type" data-stat="by_type"></ul>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Last auto-sync information.
	 */
	private function render_last_sync(): void {
		$last = get_option( 'wp_cortex_last_sync' );

		if ( ! is_array( $last ) || empty( $last['time'] ) ) {
			return;
		}

		$errors = isset( $last['errors'] ) ? (int) ( is_array( $last['errors'] ) ? count( $last['errors'] ) : $last['errors'] ) : 0;

		echo '<p class="wp-cortex-last-sync"><strong>' . esc_html__( 'Last automatic sync:', 'wp-cortex' ) . '</strong> ';
		printf(
			/* translators: 1: human-readable time difference, 2: number of posts, 3: number of errors. */
			esc_html__( '%1$s ago, %2$s posts, %3$s errors', 'wp-cortex' ),
			esc_html( human_time_diff( (int) $last['time'] ) ),
			esc_html( number_format_i18n( (int) ( $last['count'] ?? 0 ) ) ),
			esc_html( number_format_i18n( $errors ) )
		);
		echo '</p>';
	}
}
