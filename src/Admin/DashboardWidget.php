<?php
/**
 * Dashboard widget: visitor chats, chat availability and index overview.
 *
 * @package WPCortex
 */

namespace WPCortex\Admin;

use WPCortex\Chat\VisitorChatStore;
use WPCortex\Indexing\IndexRun;
use WPCortex\Indexing\PostSync;
use WPCortex\Plugin;
use WPCortex\Settings;
use WPCortex\Storage\Database;
use WPCortex\Storage\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered "Cortex" widget on the WordPress dashboard, for administrators only.
 */
final class DashboardWidget {

	public const ID = 'wp_cortex_dashboard';

	/**
	 * Days counted as "recent" for new visitor conversations.
	 */
	private const RECENT_DAYS = 7;

	/**
	 * Number of unread conversations listed in the widget.
	 */
	private const UNREAD_LIST = 3;

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the widget for administrators.
	 */
	public function add_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget( self::ID, __( 'Cortex', 'wp-cortex' ), array( $this, 'render' ) );
	}

	/**
	 * Enqueues the widget styles on the dashboard.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'index.php' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style( 'wp-cortex-dashboard', WP_CORTEX_URL . 'assets/css/dashboard.css', array(), Plugin::asset_version( 'assets/css/dashboard.css' ) );
	}

	/**
	 * Renders the widget.
	 */
	public function render(): void {
		echo '<div class="wp-cortex-dash">';

		$this->render_visitor_chats();
		$this->render_availability();
		$this->render_index();

		printf(
			'<p class="wp-cortex-dash-footer"><a href="%1$s">%2$s</a> | <a href="%3$s">%4$s</a> | <a href="%5$s">%6$s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_VISITORS ) ),
			esc_html__( 'Visitor chats', 'wp-cortex' ),
			esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_INDEXING ) ),
			esc_html__( 'Indexing', 'wp-cortex' ),
			esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS ) ),
			esc_html__( 'Settings', 'wp-cortex' )
		);

		echo '</div>';
	}

	/**
	 * Visitor chat counters and the latest unread conversations.
	 */
	private function render_visitor_chats(): void {
		$store  = new VisitorChatStore();
		$counts = $store->counts();
		$recent = $store->count_started_since( self::RECENT_DAYS );
		$url    = admin_url( 'admin.php?page=' . Menu::SLUG_VISITORS );

		echo '<div class="wp-cortex-dash-section">';
		echo '<h3>' . esc_html__( 'Visitor chats', 'wp-cortex' ) . '</h3>';

		$tiles = array(
			array(
				'value' => $counts['unread'],
				'label' => __( 'Unread', 'wp-cortex' ),
				'url'   => add_query_arg( 'filter', VisitorChatStore::FILTER_UNREAD, $url ),
				'class' => $counts['unread'] > 0 ? 'is-unread' : '',
			),
			array(
				'value' => $counts['all'],
				'label' => __( 'Total', 'wp-cortex' ),
				'url'   => $url,
				'class' => '',
			),
			array(
				'value' => $recent,
				/* translators: %d: number of days. */
				'label' => sprintf( _n( 'Last %d day', 'Last %d days', self::RECENT_DAYS, 'wp-cortex' ), self::RECENT_DAYS ),
				'url'   => $url,
				'class' => '',
			),
			array(
				'value' => $counts['contact'],
				'label' => __( 'With contact', 'wp-cortex' ),
				'url'   => add_query_arg( 'filter', VisitorChatStore::FILTER_CONTACT, $url ),
				'class' => '',
			),
		);

		echo '<ul class="wp-cortex-dash-tiles">';
		foreach ( $tiles as $tile ) {
			printf(
				'<li class="%1$s"><a href="%2$s"><span class="wp-cortex-dash-tile-value">%3$s</span><span class="wp-cortex-dash-tile-label">%4$s</span></a></li>',
				esc_attr( $tile['class'] ),
				esc_url( $tile['url'] ),
				esc_html( number_format_i18n( $tile['value'] ) ),
				esc_html( $tile['label'] )
			);
		}
		echo '</ul>';

		if ( $counts['unread'] > 0 ) {
			$this->render_unread_list( $store, $url );
		} elseif ( $counts['all'] > 0 ) {
			echo '<p class="wp-cortex-dash-muted">' . esc_html__( 'All caught up: no unread conversations.', 'wp-cortex' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Latest unread conversations, linked to their detail view.
	 *
	 * @param VisitorChatStore $store Store.
	 * @param string           $url   Visitor chats screen URL.
	 */
	private function render_unread_list( VisitorChatStore $store, string $url ): void {
		$chats = $store->query( VisitorChatStore::FILTER_UNREAD, '', 1, self::UNREAD_LIST )['chats'];

		echo '<ul class="wp-cortex-dash-unread">';
		foreach ( $chats as $chat ) {
			$contact = (array) $chat['contact'];
			$name    = trim( ( $contact['first_name'] ?? '' ) . ' ' . ( $contact['last_name'] ?? '' ) );
			$name    = '' !== $name ? $name : (string) ( $contact['email'] ?? '' );
			$preview = '' !== $chat['preview'] ? $chat['preview'] : __( '(no message)', 'wp-cortex' );
			$time    = strtotime( $chat['updated_at'] . ' UTC' );

			printf(
				'<li><a href="%1$s"><span class="wp-cortex-dash-unread-title">%2$s</span><span class="wp-cortex-dash-unread-meta">%3$s</span></a></li>',
				esc_url( $url . '#chat=' . (int) $chat['id'] ),
				esc_html( wp_html_excerpt( $preview, 90, '…' ) ),
				esc_html(
					implode(
						' · ',
						array_filter(
							array(
								$name,
								$chat['has_contact'] ? __( 'contact details', 'wp-cortex' ) : '',
								/* translators: %s: human-readable time difference. */
								$time ? sprintf( __( '%s ago', 'wp-cortex' ), human_time_diff( $time ) ) : '',
							)
						)
					)
				)
			);
		}
		echo '</ul>';
	}

	/**
	 * Where each chat is shown.
	 */
	private function render_availability(): void {
		$admin_on   = ChatPanel::is_enabled();
		$visitor_on = (bool) Settings::get( 'public_chat_enabled' );
		$settings   = admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS );

		$rows = array(
			array(
				'label' => __( 'Admin chat in the admin', 'wp-cortex' ),
				'on'    => $admin_on,
				'note'  => '',
				'url'   => add_query_arg( 'tab', 'chat', $settings ),
			),
			array(
				'label' => __( 'Admin chat on the front end', 'wp-cortex' ),
				'on'    => (bool) Settings::get( 'chat_frontend' ),
				'note'  => __( 'Administrators only', 'wp-cortex' ),
				'url'   => add_query_arg( 'tab', 'chat', $settings ),
			),
			array(
				'label' => __( 'Visitor chat on the front end', 'wp-cortex' ),
				'on'    => $visitor_on,
				'note'  => $visitor_on && ! Settings::get( 'public_chat_log' ) ? __( 'Conversations are not saved', 'wp-cortex' ) : '',
				'url'   => add_query_arg( 'tab', 'visitors', $settings ),
			),
		);

		echo '<div class="wp-cortex-dash-section">';
		echo '<h3>' . esc_html__( 'Chat availability', 'wp-cortex' ) . '</h3>';
		echo '<ul class="wp-cortex-dash-status">';
		foreach ( $rows as $row ) {
			printf(
				'<li><a href="%1$s"><span class="wp-cortex-dash-dot %2$s" aria-hidden="true"></span><span class="wp-cortex-dash-status-label">%3$s%4$s</span><span class="wp-cortex-dash-state">%5$s</span></a></li>',
				esc_url( $row['url'] ),
				$row['on'] ? 'is-on' : 'is-off',
				esc_html( $row['label'] ),
				'' !== $row['note'] ? ' <span class="wp-cortex-dash-muted">(' . esc_html( $row['note'] ) . ')</span>' : '',
				$row['on'] ? esc_html__( 'On', 'wp-cortex' ) : esc_html__( 'Off', 'wp-cortex' )
			);
		}
		echo '</ul>';
		echo '</div>';
	}

	/**
	 * Index sizes, embedding coverage and sync state.
	 */
	private function render_index(): void {
		$scopes = array(
			Storage::SCOPE_PUBLIC => __( 'Public index', 'wp-cortex' ),
			Storage::SCOPE_ADMIN  => __( 'Admin index', 'wp-cortex' ),
		);

		echo '<div class="wp-cortex-dash-section">';
		echo '<h3>' . esc_html__( 'Index', 'wp-cortex' ) . $this->index_badge() . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in index_badge().

		if ( true === Storage::is_exposed() ) {
			printf(
				'<p class="wp-cortex-dash-alert"><a href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_INDEXING ) ),
				esc_html__( 'The index database is publicly downloadable! See how to protect it.', 'wp-cortex' )
			);
		}

		echo '<table class="wp-cortex-dash-index"><thead><tr>';
		echo '<th scope="col"></th>';
		echo '<th scope="col">' . esc_html__( 'Documents', 'wp-cortex' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Chunks', 'wp-cortex' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Embedded', 'wp-cortex' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Size', 'wp-cortex' ) . '</th>';
		echo '</tr></thead><tbody>';

		$last = '';
		foreach ( $scopes as $scope => $label ) {
			try {
				$stats = Database::get( $scope )->stats();
			} catch ( \Throwable $e ) {
				printf( '<tr><th scope="row">%1$s</th><td colspan="4" class="wp-cortex-dash-muted">%2$s</td></tr>', esc_html( $label ), esc_html__( 'Not available', 'wp-cortex' ) );
				continue;
			}

			$coverage = $stats['chunks'] > 0 ? (int) floor( 100 * $stats['embedded'] / $stats['chunks'] ) : 0;
			$last     = max( $last, (string) $stats['last_update'] );

			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( $label ),
				esc_html( number_format_i18n( $stats['documents'] ) ),
				esc_html( number_format_i18n( $stats['chunks'] ) ),
				$stats['chunks'] > 0 ? esc_html( $coverage . '%' ) : '&ndash;',
				esc_html( (string) size_format( $stats['size'] ) )
			);
		}

		echo '</tbody></table>';

		$meta  = array();
		$queue = count( (array) get_option( PostSync::QUEUE_OPTION, array() ) );

		if ( '' !== $last ) {
			/* translators: %s: human-readable time difference. */
			$meta[] = sprintf( __( 'Last update %s ago', 'wp-cortex' ), human_time_diff( (int) strtotime( $last . ' UTC' ) ) );
		}

		if ( $queue > 0 ) {
			/* translators: %s: number of posts. */
			$meta[] = sprintf( _n( '%s post waiting for automatic sync', '%s posts waiting for automatic sync', $queue, 'wp-cortex' ), number_format_i18n( $queue ) );
		}

		if ( ! Settings::get( 'auto_sync' ) ) {
			$meta[] = __( 'Automatic sync is off', 'wp-cortex' );
		}

		if ( $meta ) {
			echo '<p class="wp-cortex-dash-muted">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Badge with the state of the last manual index run.
	 *
	 * @return string Escaped HTML.
	 */
	private function index_badge(): string {
		$state  = IndexRun::state();
		$status = is_array( $state ) ? (string) ( $state['status'] ?? '' ) : '';

		if ( 'running' === $status ) {
			$total = (int) ( $state['total'] ?? 0 );
			$done  = (int) ( $state['processed'] ?? 0 );
			/* translators: %d: percentage of processed posts. */
			$label = sprintf( __( 'Indexing %d%%', 'wp-cortex' ), $total > 0 ? (int) floor( 100 * $done / $total ) : 0 );
			return '<span class="wp-cortex-dash-badge is-running">' . esc_html( $label ) . '</span>';
		}

		if ( 'failed' === $status ) {
			return '<span class="wp-cortex-dash-badge is-failed">' . esc_html__( 'Last run failed', 'wp-cortex' ) . '</span>';
		}

		if ( ! empty( $state['failed'] ) ) {
			/* translators: %s: number of posts. */
			$label = sprintf( _n( '%s post failed', '%s posts failed', (int) $state['failed'], 'wp-cortex' ), number_format_i18n( (int) $state['failed'] ) );
			return '<span class="wp-cortex-dash-badge is-warn">' . esc_html( $label ) . '</span>';
		}

		return '';
	}
}
