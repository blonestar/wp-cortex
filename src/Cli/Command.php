<?php
/**
 * WP-CLI commands.
 *
 * @package WPCortex
 */

namespace WPCortex\Cli;

use WPCortex\Indexing\IndexRun;
use WPCortex\Storage\Database;
use WPCortex\Storage\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the WP Cortex index.
 */
final class Command {

	/**
	 * Indexes site content into the WP Cortex databases.
	 *
	 * ## OPTIONS
	 *
	 * [--rebuild]
	 * : Delete both databases first and index everything from scratch.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cortex index
	 *     wp cortex index --rebuild
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function index( $args, $assoc_args ): void {
		$state = IndexRun::start( \WP_CLI\Utils\get_flag_value( $assoc_args, 'rebuild', false ) ? 'rebuild' : 'sync' );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Indexing posts', max( 1, (int) $state['total'] ) );
		$shown    = 0;

		while ( $state && 'running' === $state['status'] ) {
			$state = IndexRun::batch();

			if ( ! $state ) {
				break;
			}

			if ( ! empty( $state['locked'] ) ) {
				sleep( 2 );
				continue;
			}

			for ( ; $shown < $state['processed']; $shown++ ) {
				$progress->tick();
			}
		}

		$progress->finish();

		if ( ! $state ) {
			\WP_CLI::error( 'Index run state was lost.' );
		}

		foreach ( $state['errors'] as $error ) {
			\WP_CLI::warning( sprintf( 'Post %d: %s', $error['post_id'], $error['message'] ) );
		}

		$summary = sprintf(
			'%s: %d indexed, %d unchanged, %d removed, %d failed, %d chunks embedded, %d tokens.',
			$state['status'],
			$state['indexed'],
			$state['skipped'],
			$state['removed'],
			$state['failed'],
			$state['embedded_chunks'],
			$state['tokens']
		);

		if ( 'completed' === $state['status'] ) {
			\WP_CLI::success( $summary );
		} else {
			\WP_CLI::error( $summary );
		}
	}

	/**
	 * Shows index statistics and the last run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cortex status
	 */
	public function status(): void {
		$rows = array();

		foreach ( Storage::SCOPES as $scope ) {
			$stats  = Database::get( $scope )->stats();
			$rows[] = array(
				'scope'       => $scope,
				'documents'   => $stats['documents'],
				'chunks'      => $stats['chunks'],
				'embedded'    => $stats['embedded'],
				'fields'      => $stats['fields'],
				'size'        => size_format( $stats['size'] ),
				'last_update' => $stats['last_update'],
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'scope', 'documents', 'chunks', 'embedded', 'fields', 'size', 'last_update' ) );

		$state = IndexRun::state();
		if ( ! $state ) {
			\WP_CLI::log( 'No index run has been started yet.' );
			return;
		}

		$run = array();
		foreach ( $state as $key => $value ) {
			if ( 'errors' === $key ) {
				$value = count( $value );
			} elseif ( in_array( $key, array( 'started_at', 'finished_at' ), true ) ) {
				$value = $value ? gmdate( 'Y-m-d H:i:s', (int) $value ) . ' UTC' : '-';
			}
			$run[] = array(
				'key'   => 'errors' === $key ? 'errors (count)' : $key,
				'value' => $value,
			);
		}

		\WP_CLI::log( 'Last index run:' );
		\WP_CLI\Utils\format_items( 'table', $run, array( 'key', 'value' ) );
	}
}
