<?php
/**
 * Status & stats section of Settings > Indexing: the batch-driven indexing UI and index stats.
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
final class IndexStatus {

	/**
	 * Renders the section.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wp-cortex-index-status" id="wp-cortex-indexing">';

		$this->render_storage_alert();
		$this->render_embeddings();
		$this->render_progress();
		$this->render_stats();
		$this->render_last_sync();

		echo '</div>';
	}

	/**
	 * Alert when the last public access check found the data directory reachable; the
	 * details and the check live in Settings > Advanced > Storage.
	 */
	private function render_storage_alert(): void {
		if ( true !== Storage::is_exposed() ) {
			return;
		}

		echo '<div class="notice notice-error inline"><p><strong>';
		echo esc_html__( 'The index databases are publicly downloadable!', 'wp-cortex' );
		echo '</strong> ';
		printf(
			'<a href="%1$s">%2$s</a>',
			esc_url( SettingsPage::storage_url() ),
			esc_html__( 'See the storage check and how to protect them.', 'wp-cortex' )
		);
		echo '</p></div>';
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

		echo ' &middot; <a href="' . esc_url( SettingsPage::section_url( 'indexing', 'embeddings' ) ) . '">' . esc_html__( 'Change settings', 'wp-cortex' ) . '</a>';
		echo '</p>';
	}

	/**
	 * Progress, controls and error list.
	 */
	private function render_progress(): void {
		?>
		<div class="wp-cortex-index-run">
			<h3>
				<?php esc_html_e( 'Index', 'wp-cortex' ); ?>
				<span class="wp-cortex-badge wp-cortex-badge-idle" id="wp-cortex-status"><?php esc_html_e( 'Loading…', 'wp-cortex' ); ?></span>
			</h3>

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
				<button type="button" class="button" id="wp-cortex-pause" hidden><?php esc_html_e( 'Pause', 'wp-cortex' ); ?></button>
				<button type="button" class="button" id="wp-cortex-resume" hidden><?php esc_html_e( 'Resume', 'wp-cortex' ); ?></button>
				<button type="button" class="button" id="wp-cortex-cancel" hidden><?php esc_html_e( 'Cancel', 'wp-cortex' ); ?></button>
			</p>
			<p class="description">
				<?php esc_html_e( 'Sync only re-processes content that has changed. Rebuild deletes both databases and indexes everything again. Indexing runs in the background on the server: you can leave this page and come back later. A paused run continues where it stopped.', 'wp-cortex' ); ?>
			</p>

			<div class="notice notice-error inline" id="wp-cortex-request-error" hidden><p></p></div>

			<div id="wp-cortex-errors-wrap" hidden>
				<h4><?php esc_html_e( 'Errors', 'wp-cortex' ); ?></h4>
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
			echo '<div class="wp-cortex-stats-box" data-stats-scope="' . esc_attr( $scope ) . '">';
			echo '<h3>' . esc_html( $title ) . '</h3>';
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
			echo '<h4>' . esc_html__( 'By post type', 'wp-cortex' ) . '</h4>';
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
