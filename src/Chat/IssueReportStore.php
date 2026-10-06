<?php
/**
 * Issue report persistence (WordPress database).
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the problems visitors report through the visitor chat (typos, broken images
 * or links, wrong information, display problems) in a custom MySQL table, with the page
 * they are about, the conversation they came from, the images the visitor attached
 * while reporting them and their handling status.
 *
 * The visitor chat adds reports and may add to the open reports of the visitor's own
 * conversation (open_for_chat(), amend()); it never reads other reports or admin fields.
 */
final class IssueReportStore {

	public const DB_VERSION        = '2';
	public const DB_VERSION_OPTION = 'wp_cortex_issue_reports_db_version';
	public const TABLE_SUFFIX      = 'wp_cortex_issue_reports';

	public const STATUS_OPEN      = 'open';
	public const STATUS_RESOLVED  = 'resolved';
	public const STATUS_DISMISSED = 'dismissed';
	public const STATUSES         = array( self::STATUS_OPEN, self::STATUS_RESOLVED, self::STATUS_DISMISSED );

	/**
	 * Issue categories the visitor chat can report.
	 */
	public const CATEGORIES = array( 'typo', 'broken_image', 'broken_link', 'wrong_info', 'display', 'not_working', 'other' );

	public const MAX_DESCRIPTION = 2000;
	public const MAX_EXCERPT     = 500;
	public const MAX_URL         = 2000;
	public const MAX_NOTE        = 4000;
	public const MAX_IMAGES      = 10;

	/**
	 * Full table name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Creates or upgrades the table.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			chat_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			page_url text NOT NULL,
			category varchar(20) NOT NULL DEFAULT 'other',
			description text NOT NULL,
			excerpt text NOT NULL,
			images text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'open',
			admin_note text NOT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			resolved_at datetime DEFAULT NULL,
			resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY chat_id (chat_id),
			KEY created_at (created_at)
		) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Installs the table when the stored version is outdated. Hooked to plugins_loaded.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Translated category labels.
	 *
	 * @return array<string, string>
	 */
	public static function category_labels(): array {
		return array(
			'typo'         => __( 'Typo or grammar', 'wp-cortex' ),
			'broken_image' => __( 'Broken or missing image', 'wp-cortex' ),
			'broken_link'  => __( 'Broken link', 'wp-cortex' ),
			'wrong_info'   => __( 'Wrong or outdated information', 'wp-cortex' ),
			'display'      => __( 'Display or layout problem', 'wp-cortex' ),
			'not_working'  => __( 'Something does not work', 'wp-cortex' ),
			'other'        => __( 'Other', 'wp-cortex' ),
		);
	}

	/**
	 * Translated status labels.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			self::STATUS_OPEN      => __( 'Open', 'wp-cortex' ),
			self::STATUS_RESOLVED  => __( 'Resolved', 'wp-cortex' ),
			self::STATUS_DISMISSED => __( 'Dismissed', 'wp-cortex' ),
		);
	}

	/**
	 * URL of a page on this site, or "" when the URL is not valid or points elsewhere.
	 *
	 * @param string $url URL sent by the browser.
	 */
	public static function sanitize_page_url( string $url ): string {
		$url = esc_url_raw( trim( mb_substr( $url, 0, self::MAX_URL ) ), array( 'http', 'https' ) );

		if ( '' === $url ) {
			return '';
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return '' !== $host && $host === $home ? $url : '';
	}

	/**
	 * Valid stored image names (VisitorImages), without duplicates.
	 *
	 * @param mixed $images List of file names.
	 * @return string[]
	 */
	private static function sanitize_images( $images ): array {
		$names = array_filter(
			array_map( 'strval', is_array( $images ) ? $images : array() ),
			static fn( string $name ) => (bool) preg_match( '/^' . VisitorImages::NAME_PATTERN . '$/', $name )
		);

		return array_slice( array_values( array_unique( $names ) ), 0, self::MAX_IMAGES );
	}

	/**
	 * Image names stored in a row.
	 *
	 * @param array $row Database row.
	 * @return string[]
	 */
	private static function row_images( array $row ): array {
		return self::sanitize_images( json_decode( (string) ( $row['images'] ?? '' ), true ) );
	}

	/**
	 * Adds a report.
	 *
	 * @param array $data chat_id, post_id, page_url, category, description, excerpt and images
	 *                    (names of images stored with the conversation).
	 * @return int Report ID, 0 on failure.
	 */
	public function add( array $data ): int {
		global $wpdb;

		$category    = (string) ( $data['category'] ?? '' );
		$description = trim( mb_substr( sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ), 0, self::MAX_DESCRIPTION ) );
		$now         = current_time( 'mysql', true );

		if ( '' === $description ) {
			return 0;
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'chat_id'     => absint( $data['chat_id'] ?? 0 ),
				'post_id'     => absint( $data['post_id'] ?? 0 ),
				'page_url'    => self::sanitize_page_url( (string) ( $data['page_url'] ?? '' ) ),
				'category'    => in_array( $category, self::CATEGORIES, true ) ? $category : 'other',
				'description' => $description,
				'excerpt'     => trim( mb_substr( sanitize_textarea_field( (string) ( $data['excerpt'] ?? '' ) ), 0, self::MAX_EXCERPT ) ),
				'images'      => (string) wp_json_encode( self::sanitize_images( $data['images'] ?? array() ) ),
				'status'      => self::STATUS_OPEN,
				'admin_note'  => '',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Open reports of a stored visitor conversation, oldest first: only what the visitor
	 * chat itself saved (no status, note or page details).
	 *
	 * @param int $chat_id Conversation ID.
	 * @return array<int, array{id: int, category: string, description: string, excerpt: string}>
	 */
	public function open_for_chat( int $chat_id ): array {
		global $wpdb;

		if ( $chat_id < 1 ) {
			return array();
		}

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, category, description, excerpt FROM {$table} WHERE chat_id = %d AND status = %s ORDER BY id ASC LIMIT 20", $chat_id, self::STATUS_OPEN ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static fn( array $row ) => array(
				'id'          => (int) $row['id'],
				'category'    => (string) $row['category'],
				'description' => (string) $row['description'],
				'excerpt'     => (string) $row['excerpt'],
			),
			(array) $rows
		);
	}

	/**
	 * Adds details to an open report of a stored visitor conversation: a new description,
	 * category or quoted text replaces the old one, images are added.
	 *
	 * @param int   $id      Report ID.
	 * @param int   $chat_id Conversation the report must belong to.
	 * @param array $data    category, description, excerpt and images; empty values are ignored.
	 * @return bool Whether the report was found (open, in this conversation) and updated.
	 */
	public function amend( int $id, int $chat_id, array $data ): bool {
		global $wpdb;

		if ( $id < 1 || $chat_id < 1 ) {
			return false;
		}

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, images FROM {$table} WHERE id = %d AND chat_id = %d AND status = %s", $id, $chat_id, self::STATUS_OPEN ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			return false;
		}

		$category    = (string) ( $data['category'] ?? '' );
		$description = trim( mb_substr( sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ), 0, self::MAX_DESCRIPTION ) );
		$excerpt     = trim( mb_substr( sanitize_textarea_field( (string) ( $data['excerpt'] ?? '' ) ), 0, self::MAX_EXCERPT ) );
		$update      = array(
			'images'     => (string) wp_json_encode( self::sanitize_images( array_merge( self::row_images( $row ), (array) ( $data['images'] ?? array() ) ) ) ),
			'updated_at' => current_time( 'mysql', true ),
		);

		if ( in_array( $category, self::CATEGORIES, true ) ) {
			$update['category'] = $category;
		}

		if ( '' !== $description ) {
			$update['description'] = $description;
		}

		if ( '' !== $excerpt ) {
			$update['excerpt'] = $excerpt;
		}

		return false !== $wpdb->update( $table, $update, array( 'id' => $id ), '%s', '%d' );
	}

	/**
	 * Lists reports, newest first.
	 *
	 * @param string $status   "" for all, or one of STATUSES.
	 * @param string $search   Text to look for in the description, quoted text, page URL and note.
	 * @param int    $page     Page number (1-based).
	 * @param int    $per_page Rows per page.
	 * @return array{reports: array, total: int}
	 */
	public function query( string $status, string $search, int $page, int $per_page ): array {
		global $wpdb;

		$table = self::table();
		$where = $this->where( $status, $search );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, ( max( 1, $page ) - 1 ) * $per_page ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'reports' => $this->format_rows( (array) $rows ),
			'total'   => $total,
		);
	}

	/**
	 * Number of reports per status.
	 *
	 * @return array{all: int, open: int, resolved: int, dismissed: int}
	 */
	public function counts(): array {
		global $wpdb;

		$table  = self::table();
		$counts = array_fill_keys( array_merge( array( 'all' ), self::STATUSES ), 0 );

		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['total'];
			}
			$counts['all'] += (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Number of open reports, for the menu badge.
	 */
	public function open_count(): int {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_OPEN ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * One report.
	 *
	 * @param int $id Report ID.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $this->format_rows( array( $row ) )[0] : null;
	}

	/**
	 * Updates the administrator fields of reports.
	 *
	 * @param int[] $ids  Report IDs.
	 * @param array $data status (one of STATUSES) and/or admin_note (string).
	 * @return int Number of reports found.
	 */
	public function update_admin( array $ids, array $data ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		if ( ! $ids ) {
			return 0;
		}

		$table = self::table();
		$in    = implode( ',', $ids );
		$found = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$set   = array();
		$args  = array();

		if ( isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ) {
			$open   = self::STATUS_OPEN === $data['status'];
			$set[]  = 'status = %s';
			$args[] = $data['status'];
			// Keep the first resolution time when switching between resolved and dismissed.
			$set[]  = $open ? 'resolved_at = NULL' : 'resolved_at = COALESCE(resolved_at, %s)';
			$set[]  = 'resolved_by = %d';
			$args   = array_merge( $args, $open ? array( 0 ) : array( current_time( 'mysql', true ), get_current_user_id() ) );
		}

		if ( array_key_exists( 'admin_note', $data ) ) {
			$set[]  = 'admin_note = %s';
			$args[] = mb_substr( trim( sanitize_textarea_field( (string) $data['admin_note'] ) ), 0, self::MAX_NOTE );
		}

		if ( ! $set || ! $found ) {
			return $found;
		}

		$set[]  = 'updated_at = %s';
		$args[] = current_time( 'mysql', true );

		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $set ) . " WHERE id IN ({$in})", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return $found;
	}

	/**
	 * Deletes reports.
	 *
	 * @param int[] $ids Report IDs.
	 * @return int Number of deleted reports.
	 */
	public function delete( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		if ( ! $ids ) {
			return 0;
		}

		$table = self::table();

		return (int) $wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * SQL condition for a status filter and search text.
	 *
	 * @param string $status Status filter.
	 * @param string $search Search text.
	 */
	private function where( string $status, string $search ): string {
		global $wpdb;

		$where = array( '1=1' );

		if ( in_array( $status, self::STATUSES, true ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $status );
		}

		$search = trim( $search );

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = $wpdb->prepare( '(description LIKE %s OR excerpt LIKE %s OR page_url LIKE %s OR admin_note LIKE %s)', $like, $like, $like, $like );
		}

		return implode( ' AND ', $where );
	}

	/**
	 * Formats rows for the admin screen, noting which conversations still exist.
	 *
	 * @param array $rows Database rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_rows( array $rows ): array {
		global $wpdb;

		$chat_ids = array_values( array_unique( array_filter( array_map( static fn( $row ) => (int) $row['chat_id'], $rows ) ) ) );
		$chats    = array();

		if ( $chat_ids ) {
			$table = VisitorChatStore::table();
			$chats = array_flip( array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$table} WHERE id IN (" . implode( ',', $chat_ids ) . ')' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$categories = self::category_labels();

		return array_map(
			static function ( array $row ) use ( $chats, $categories ): array {
				$post_id = (int) $row['post_id'];
				$post    = $post_id > 0 ? get_post( $post_id ) : null;
				$user    = (int) $row['resolved_by'] > 0 ? get_userdata( (int) $row['resolved_by'] ) : null;

				$chat_id = isset( $chats[ (int) $row['chat_id'] ] ) ? (int) $row['chat_id'] : 0;

				return array(
					'id'             => (int) $row['id'],
					'chat_id'        => $chat_id,
					'category'       => (string) $row['category'],
					'category_label' => $categories[ $row['category'] ] ?? $categories['other'],
					'description'    => (string) $row['description'],
					'excerpt'        => (string) $row['excerpt'],
					// Images are stored with the conversation and deleted with it.
					'images'         => $chat_id ? self::row_images( $row ) : array(),
					'page_url'       => (string) $row['page_url'],
					'page'           => $post ? array(
						'id'       => $post_id,
						'title'    => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
						'url'      => (string) get_permalink( $post ),
						'edit_url' => (string) get_edit_post_link( $post, 'raw' ),
					) : null,
					'status'         => (string) $row['status'],
					'admin_note'     => (string) $row['admin_note'],
					'created_at'     => (string) $row['created_at'],
					'updated_at'     => (string) $row['updated_at'],
					'resolved_at'    => (string) ( $row['resolved_at'] ?? '' ),
					'resolved_by'    => $user ? $user->display_name : '',
				);
			},
			$rows
		);
	}
}
