<?php
/**
 * Visitor chat persistence (WordPress database).
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Stores visitor chat conversations, the contact details visitors leave, the client IP,
 * the AI summary, the visitor's presence and the administrator's read state, note and
 * forwarding in a custom MySQL table.
 *
 * A conversation is identified by a random session token generated in the browser;
 * only its SHA-256 hash is stored. The visitor chat only appends to its own
 * conversation, saves its contact details and reports whether the chat is open
 * (touch()): it never reads stored conversations.
 */
final class VisitorChatStore {

	public const DB_VERSION        = '4';
	public const DB_VERSION_OPTION = 'wp_cortex_visitor_chats_db_version';
	public const TABLE_SUFFIX      = 'wp_cortex_visitor_chats';
	public const PURGE_HOOK        = 'wp_cortex_purge_visitor_chats';

	public const MAX_TRANSCRIPT_ITEMS = 400;
	public const MAX_NOTE             = 4000;
	public const MAX_SUMMARY          = 8000;

	/**
	 * Activity of a conversation (format_summary() "activity"): the visitor is in the
	 * chat, may come back, or the conversation is over.
	 */
	public const ACTIVITY_ACTIVE = 'active';
	public const ACTIVITY_IDLE   = 'idle';
	public const ACTIVITY_ENDED  = 'ended';

	/**
	 * Seconds after the last presence ping while an open chat still counts as active.
	 * The widget pings every 60 seconds, so one missed ping is tolerated.
	 */
	public const PRESENCE_TIMEOUT = 150;

	/**
	 * Without presence pings (for example a cached older widget), a conversation is
	 * active for this many seconds after the last visitor activity.
	 */
	public const ACTIVE_WINDOW = 5 * MINUTE_IN_SECONDS;

	/**
	 * A conversation that is not active is idle (the visitor may come back) for this
	 * many seconds after the last activity, then ended.
	 */
	public const IDLE_WINDOW = 30 * MINUTE_IN_SECONDS;

	/**
	 * JSON flags for the transcript and contact columns: unescaped, readable text.
	 */
	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;

	public const FILTER_UNREAD  = 'unread';
	public const FILTER_CONTACT = 'contact';

	/**
	 * Contact fields and their maximum lengths.
	 */
	public const CONTACT_FIELDS = array(
		'first_name' => 100,
		'last_name'  => 100,
		'email'      => 200,
		'phone'      => 50,
		'address'    => 300,
		'company'    => 200,
		'website'    => 2000,
		'request'    => 1000,
	);

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
			session_hash char(64) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			transcript longtext NOT NULL,
			search_text longtext NOT NULL,
			message_count int(10) unsigned NOT NULL DEFAULT 0,
			contact text NOT NULL,
			has_contact tinyint(1) NOT NULL DEFAULT 0,
			is_read tinyint(1) NOT NULL DEFAULT 0,
			admin_note text NOT NULL,
			ip varchar(45) NOT NULL DEFAULT '',
			ip_forwarded varchar(255) NOT NULL DEFAULT '',
			summary text NOT NULL,
			summary_at datetime DEFAULT NULL,
			forwarded_to varchar(255) NOT NULL DEFAULT '',
			forwarded_at datetime DEFAULT NULL,
			seen_at datetime DEFAULT NULL,
			chat_open tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY session_hash (session_hash),
			KEY updated_at (updated_at)
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
	 * Whether a string is a valid session token (32 lowercase hex characters).
	 *
	 * @param string $token Token sent by the browser.
	 */
	public static function is_valid_token( string $token ): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $token );
	}

	/**
	 * Conversation ID for a session token, created when missing.
	 *
	 * @param string $token   Session token.
	 * @param int    $post_id Page the visitor started on.
	 * @return int Conversation ID, 0 on failure.
	 */
	public function open( string $token, int $post_id ): int {
		global $wpdb;

		if ( ! self::is_valid_token( $token ) ) {
			return 0;
		}

		$table = self::table();
		$hash  = hash( 'sha256', $token );
		$now   = current_time( 'mysql', true );

		// INSERT IGNORE: two concurrent first messages of one session share a row.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (session_hash, post_id, transcript, search_text, contact, admin_note, summary, created_at, updated_at) VALUES (%s, %d, '[]', '', '{}', '', '', %s, %s)", $hash, $post_id, $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE session_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Appends transcript items. A new visitor message marks the conversation as unread.
	 *
	 * @param int   $id     Conversation ID.
	 * @param array $items  Transcript items.
	 * @param array $client Latest client addresses: ip and ip_forwarded (ClientIp::details()).
	 */
	public function append( int $id, array $items, array $client = array() ): void {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT transcript, search_text, message_count FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			return;
		}

		$transcript = json_decode( (string) $row['transcript'], true );
		$transcript = array_merge( is_array( $transcript ) ? $transcript : array(), array_values( $items ) );
		$new_user   = count( array_filter( $items, static fn( $item ) => 'user' === ( $item['role'] ?? '' ) ) );
		$texts      = array_filter( array_map( static fn( $item ) => in_array( $item['role'] ?? '', array( 'user', 'assistant' ), true ) ? (string) ( $item['text'] ?? '' ) : '', $items ) );
		$data       = array(
			'transcript'    => wp_json_encode( array_slice( $transcript, -self::MAX_TRANSCRIPT_ITEMS ), self::JSON_FLAGS ),
			'search_text'   => self::search_text( (string) $row['search_text'], $texts ),
			'message_count' => (int) $row['message_count'] + $new_user,
			'updated_at'    => current_time( 'mysql', true ),
		);

		if ( $new_user ) {
			$data['is_read'] = 0;
		}

		foreach ( array( 'ip' => 45, 'ip_forwarded' => 255 ) as $key => $max ) {
			if ( isset( $client[ $key ] ) ) {
				$data[ $key ] = substr( (string) $client[ $key ], 0, $max );
			}
		}

		$wpdb->update( $table, $data, array( 'id' => $id ) );
	}

	/**
	 * Records the presence of the visitor of a session: the chat window is open (pinged
	 * periodically while it is visible) or was closed. Only an existing conversation is
	 * updated; updated_at and the read state are left alone, they track messages.
	 *
	 * @param string $token Session token.
	 * @param bool   $open  Whether the chat window is open.
	 */
	public function touch( string $token, bool $open ): void {
		global $wpdb;

		if ( ! self::is_valid_token( $token ) ) {
			return;
		}

		$table = self::table();

		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET seen_at = %s, chat_open = %d WHERE session_hash = %s", current_time( 'mysql', true ), $open ? 1 : 0, hash( 'sha256', $token ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Cleans contact fields: known keys only, plain text, length limits, valid email and URLs.
	 *
	 * @param array $data Raw fields.
	 * @return array<string, string> Non-empty fields.
	 */
	public static function sanitize_contact( array $data ): array {
		$contact = array();

		foreach ( self::CONTACT_FIELDS as $key => $max ) {
			$value = (string) ( $data[ $key ] ?? '' );
			$value = in_array( $key, array( 'request', 'address', 'website' ), true ) ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			$value = trim( mb_substr( $value, 0, $max ) );

			if ( 'email' === $key ) {
				$value = is_email( $value ) ? sanitize_email( $value ) : '';
			} elseif ( 'phone' === $key ) {
				$value = trim( (string) preg_replace( '/[^0-9+()\/.\s-]/', '', $value ) );
			} elseif ( 'website' === $key ) {
				$urls = array();

				foreach ( preg_split( '/\r\n|\r|\n/', $value, -1, PREG_SPLIT_NO_EMPTY ) as $url ) {
					$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );

					if ( '' !== $url && false !== wp_http_validate_url( $url ) ) {
						$urls[ $url ] = $url;
					}
				}

				$value = implode( "\n", array_values( $urls ) );
			}

			if ( '' !== $value ) {
				$contact[ $key ] = $value;
			}
		}

		return $contact;
	}

	/**
	 * Merges contact details into a conversation: non-empty values replace stored ones.
	 *
	 * @param int   $id      Conversation ID.
	 * @param array $contact Sanitized contact fields.
	 * @return array<string, string>|null Stored contact details, null when the conversation is missing.
	 */
	public function save_contact( int $id, array $contact ): ?array {
		global $wpdb;

		$table  = self::table();
		$stored = $wpdb->get_row( $wpdb->prepare( "SELECT contact, search_text FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $stored ) {
			return null;
		}

		$current = json_decode( (string) $stored['contact'], true );
		$merged  = array_merge( is_array( $current ) ? $current : array(), $contact );

		$wpdb->update(
			$table,
			array(
				'contact'     => wp_json_encode( $merged, self::JSON_FLAGS ),
				'search_text' => self::search_text( (string) $stored['search_text'], $contact ),
				'has_contact' => $merged ? 1 : 0,
				'is_read'     => 0,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);

		return $merged;
	}

	/**
	 * Stores the AI summary of a conversation.
	 *
	 * @param int    $id      Conversation ID.
	 * @param string $summary Summary (Markdown).
	 */
	public function save_summary( int $id, string $summary ): void {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'summary'    => mb_substr( trim( $summary ), 0, self::MAX_SUMMARY ),
				'summary_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Records that a conversation was forwarded by email.
	 *
	 * @param int      $id Conversation ID.
	 * @param string[] $to Recipients.
	 */
	public function mark_forwarded( int $id, array $to ): void {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'forwarded_to' => substr( implode( ', ', $to ), 0, 255 ),
				'forwarded_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Lists conversations, newest activity first.
	 *
	 * @param string $filter   "" for all, "unread" or "contact".
	 * @param string $search   Text to look for in the messages, contact details, note and IP addresses.
	 * @param int    $page     Page number (1-based).
	 * @param int    $per_page Rows per page.
	 * @return array{chats: array, total: int}
	 */
	public function query( string $filter, string $search, int $page, int $per_page ): array {
		global $wpdb;

		$table = self::table();
		$where = $this->where( $filter, $search );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, ( max( 1, $page ) - 1 ) * $per_page ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'chats' => array_map( array( self::class, 'format_summary' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Number of conversations per filter.
	 *
	 * @return array{all: int, unread: int, contact: int}
	 */
	public function counts(): array {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( "SELECT COUNT(*) AS total, COALESCE(SUM(is_read = 0), 0) AS unread, COALESCE(SUM(has_contact = 1), 0) AS contact FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'all'     => (int) ( $row['total'] ?? 0 ),
			'unread'  => (int) ( $row['unread'] ?? 0 ),
			'contact' => (int) ( $row['contact'] ?? 0 ),
		);
	}

	/**
	 * Number of unread conversations, for the menu badge.
	 */
	public function unread_count(): int {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_read = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Number of conversations started in the last given number of days.
	 *
	 * @param int $days Number of days.
	 */
	public function count_started_since( int $days ): int {
		global $wpdb;

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * One conversation with its transcript.
	 *
	 * @param int $id Conversation ID.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			return null;
		}

		$transcript = json_decode( (string) $row['transcript'], true );
		$chat       = self::format_summary( $row );

		$chat['transcript'] = array_map( array( self::class, 'format_item' ), is_array( $transcript ) ? $transcript : array() );

		return $chat;
	}

	/**
	 * Updates the administrator fields of conversations.
	 *
	 * @param int[] $ids  Conversation IDs.
	 * @param array $data is_read (bool) and/or admin_note (string).
	 * @return int Number of conversations found.
	 */
	public function update_admin( array $ids, array $data ): int {
		global $wpdb;

		$ids    = array_values( array_filter( array_map( 'absint', $ids ) ) );
		$fields = array();

		if ( ! $ids ) {
			return 0;
		}

		if ( array_key_exists( 'is_read', $data ) ) {
			$fields['is_read'] = $data['is_read'] ? 1 : 0;
		}

		if ( array_key_exists( 'admin_note', $data ) ) {
			$fields['admin_note'] = mb_substr( trim( sanitize_textarea_field( (string) $data['admin_note'] ) ), 0, self::MAX_NOTE );
		}

		$table = self::table();
		$in    = implode( ',', $ids );
		$found = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $fields || ! $found ) {
			return $found;
		}

		// Administrator changes do not touch updated_at, which tracks visitor activity.
		$set    = array();
		$values = array();

		foreach ( $fields as $column => $value ) {
			$set[]    = $column . ' = ' . ( is_int( $value ) ? '%d' : '%s' );
			$values[] = $value;
		}

		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $set ) . " WHERE id IN ({$in})", $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return $found;
	}

	/**
	 * Deletes conversations.
	 *
	 * @param int[] $ids Conversation IDs.
	 * @return int Number of deleted conversations.
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
	 * Deletes conversations without visitor activity for the given number of days.
	 *
	 * @param int $days Retention in days; 0 keeps everything.
	 * @return int Number of deleted conversations.
	 */
	public function purge( int $days ): int {
		global $wpdb;

		if ( $days < 1 ) {
			return 0;
		}

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE updated_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * SQL condition for a list filter and search text.
	 *
	 * @param string $filter Filter.
	 * @param string $search Search text.
	 */
	private function where( string $filter, string $search ): string {
		global $wpdb;

		$where = array( '1=1' );

		if ( self::FILTER_UNREAD === $filter ) {
			$where[] = 'is_read = 0';
		} elseif ( self::FILTER_CONTACT === $filter ) {
			$where[] = 'has_contact = 1';
		}

		$search = trim( $search );

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = $wpdb->prepare( '(search_text LIKE %s OR admin_note LIKE %s OR ip LIKE %s OR ip_forwarded LIKE %s)', $like, $like, $like, $like );
		}

		return implode( ' AND ', $where );
	}

	/**
	 * Plain text used by the list search: message texts and contact values, appended.
	 *
	 * @param string   $current Stored search text.
	 * @param string[] $texts   Texts to add.
	 */
	private static function search_text( string $current, array $texts ): string {
		$texts = array_filter( array_map( 'trim', $texts ) );

		return $texts ? ltrim( $current . "\n" . implode( "\n", $texts ) ) : $current;
	}

	/**
	 * List row: everything but the transcript, plus the first visitor message.
	 *
	 * @param array $row Database row.
	 * @return array<string, mixed>
	 */
	private static function format_summary( array $row ): array {
		$contact    = json_decode( (string) $row['contact'], true );
		$transcript = json_decode( (string) $row['transcript'], true );
		$preview    = '';

		foreach ( is_array( $transcript ) ? $transcript : array() as $item ) {
			if ( 'user' === ( $item['role'] ?? '' ) ) {
				$preview = mb_substr( (string) ( $item['text'] ?? '' ), 0, 160 );
				break;
			}
		}

		$post_id      = (int) $row['post_id'];
		$summary_at   = (string) ( $row['summary_at'] ?? '' );
		$forwarded_at = (string) ( $row['forwarded_at'] ?? '' );
		$seen_at      = (string) ( $row['seen_at'] ?? '' );
		$new_messages = 0;

		if ( '' !== $forwarded_at ) {
			foreach ( is_array( $transcript ) ? $transcript : array() as $item ) {
				if ( 'user' === ( $item['role'] ?? '' ) && strcmp( (string) ( $item['at'] ?? '' ), $forwarded_at ) > 0 ) {
					++$new_messages;
				}
			}
		}

		return array(
			'id'            => (int) $row['id'],
			'preview'       => $preview,
			'message_count' => (int) $row['message_count'],
			'contact'       => is_array( $contact ) && $contact ? $contact : (object) array(),
			'has_contact'   => (bool) $row['has_contact'],
			'is_read'       => (bool) $row['is_read'],
			'admin_note'    => (string) $row['admin_note'],
			'ip'            => (string) $row['ip'],
			'ip_forwarded'  => (string) $row['ip_forwarded'],
			'summary'       => (string) $row['summary'],
			'summary_at'    => $summary_at,
			// The conversation continued (or contact details changed) after the summary.
			'summary_stale' => '' !== $summary_at && strcmp( (string) $row['updated_at'], $summary_at ) > 0,
			'forwarded_to'  => (string) $row['forwarded_to'],
			'forwarded_at'  => $forwarded_at,
			// The conversation continued (or contact details changed) after it was forwarded.
			'forward_stale' => '' !== $forwarded_at && strcmp( (string) $row['updated_at'], $forwarded_at ) > 0,
			'new_messages'  => $new_messages,
			'seen_at'       => $seen_at,
			'chat_open'     => (bool) ( $row['chat_open'] ?? false ),
			'activity'      => self::activity( (string) $row['updated_at'], $seen_at, (bool) ( $row['chat_open'] ?? false ) ),
			'page'          => self::page( $post_id ),
			'created_at'    => (string) $row['created_at'],
			'updated_at'    => (string) $row['updated_at'],
		);
	}

	/**
	 * Whether the visitor is still in the conversation.
	 *
	 * Active: the chat window is open and pinged recently or, when the widget never sent
	 * a presence ping, the last visitor activity is recent. Idle: not active, but the
	 * visitor was active within IDLE_WINDOW and may come back. Ended: older than that.
	 *
	 * @param string $updated_at Last visitor message or contact change (UTC).
	 * @param string $seen_at    Last presence ping (UTC), empty when none.
	 * @param bool   $open       Whether the chat window was open at the last ping.
	 */
	private static function activity( string $updated_at, string $seen_at, bool $open ): string {
		$now     = time();
		$updated = (int) strtotime( $updated_at . ' UTC' );
		$seen    = '' !== $seen_at ? (int) strtotime( $seen_at . ' UTC' ) : 0;

		if ( ( $open && $seen && $now - $seen <= self::PRESENCE_TIMEOUT ) || ( ! $seen && $now - $updated <= self::ACTIVE_WINDOW ) ) {
			return self::ACTIVITY_ACTIVE;
		}

		return $now - max( $updated, $seen ) <= self::IDLE_WINDOW ? self::ACTIVITY_IDLE : self::ACTIVITY_ENDED;
	}

	/**
	 * Transcript item for the admin screen, with the page of each visitor message.
	 *
	 * @param mixed $item Stored item.
	 * @return array<string, mixed>
	 */
	private static function format_item( $item ): array {
		$item = is_array( $item ) ? $item : array();

		if ( 'user' === ( $item['role'] ?? '' ) && ! empty( $item['post_id'] ) ) {
			$item['page'] = self::page( (int) $item['post_id'] );
		}

		return $item;
	}

	/**
	 * Title and URL of a page, null when it does not exist.
	 *
	 * @param int $post_id Post ID.
	 * @return array{id: int, title: string, url: string}|null
	 */
	private static function page( int $post_id ): ?array {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return null;
		}

		return array(
			'id'    => $post_id,
			'title' => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
			'url'   => (string) get_permalink( $post ),
		);
	}
}
