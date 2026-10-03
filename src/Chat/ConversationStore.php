<?php
/**
 * Chat conversation persistence (WordPress database).
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Stores chat conversations in a custom MySQL table.
 */
final class ConversationStore {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'wp_cortex_db_version';
	public const TABLE_SUFFIX      = 'wp_cortex_conversations';

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
			user_id bigint(20) unsigned NOT NULL,
			title varchar(200) NOT NULL DEFAULT '',
			messages longtext NOT NULL,
			transcript longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY user_id (user_id)
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
	 * Creates an empty conversation.
	 *
	 * @param int $user_id Owner.
	 * @return int New conversation ID, 0 on failure.
	 */
	public function create( int $user_id ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'user_id'    => $user_id,
				'title'      => '',
				'messages'   => '[]',
				'transcript' => '[]',
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Loads a conversation owned by the user.
	 *
	 * @param int $id      Conversation ID.
	 * @param int $user_id Owner.
	 * @return array{id: int, user_id: int, title: string, messages: array, transcript: array, created_at: string, updated_at: string}|null
	 */
	public function get( int $id, int $user_id ): ?array {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id = %d", $id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			return null;
		}

		$messages   = json_decode( (string) $row['messages'], true );
		$transcript = json_decode( (string) $row['transcript'], true );

		return array(
			'id'         => (int) $row['id'],
			'user_id'    => (int) $row['user_id'],
			'title'      => (string) $row['title'],
			'messages'   => is_array( $messages ) ? $messages : array(),
			'transcript' => is_array( $transcript ) ? $transcript : array(),
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
		);
	}

	/**
	 * Lists the user's conversations, newest first.
	 *
	 * @param int $user_id Owner.
	 * @param int $limit   Maximum rows.
	 * @return array<int, array{id: int, title: string, updated_at: string}>
	 */
	public function list_for_user( int $user_id, int $limit = 50 ): array {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d", $user_id, max( 1, $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static fn( array $row ) => array(
				'id'         => (int) $row['id'],
				'title'      => (string) $row['title'],
				'updated_at' => (string) $row['updated_at'],
			),
			(array) $rows
		);
	}

	/**
	 * Saves title, history and transcript of a conversation owned by the user.
	 *
	 * @param int    $id         Conversation ID.
	 * @param int    $user_id    Owner.
	 * @param string $title      Title.
	 * @param array  $messages   LLM history (Message::toArray() items).
	 * @param array  $transcript UI items.
	 * @return bool
	 */
	public function save( int $id, int $user_id, string $title, array $messages, array $transcript ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			self::table(),
			array(
				'title'      => mb_substr( $title, 0, 200 ),
				'messages'   => wp_json_encode( array_values( $messages ), JSON_PARTIAL_OUTPUT_ON_ERROR ),
				'transcript' => wp_json_encode( array_values( $transcript ), JSON_PARTIAL_OUTPUT_ON_ERROR ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Builds a stable key for proposal items written before proposals had IDs.
	 *
	 * @param array $skill Proposal fields.
	 * @return string Stable hexadecimal key.
	 */
	public static function skill_proposal_id( array $skill ): string {
		return md5(
			implode(
				"\0",
				array(
					(string) ( $skill['name'] ?? '' ),
					(string) ( $skill['description'] ?? '' ),
					(string) ( $skill['instructions'] ?? '' ),
					(string) ( (int) ( $skill['existing_id'] ?? 0 ) ),
				)
			)
		);
	}

	/**
	 * Resolves one skill proposal in a conversation without rewriting the model history.
	 *
	 * @param int    $id           Conversation ID.
	 * @param int    $user_id      Owner.
	 * @param string $proposal_id  Stable proposal ID.
	 * @param string $status       Resolution status: saved or dismissed.
	 * @param array  $saved_skill  Current saved skill fields, when status is saved.
	 * @return bool True when the proposal was found and updated.
	 */
	public function resolve_skill_proposal( int $id, int $user_id, string $proposal_id, string $status, array $saved_skill = array() ): bool {
		$conversation = $this->get( $id, $user_id );

		if ( null === $conversation ) {
			return false;
		}

		$found = false;

		foreach ( $conversation['transcript'] as &$item ) {
			if ( 'skill_proposal' !== ( $item['role'] ?? '' ) || ! is_array( $item['skill'] ?? null ) ) {
				continue;
			}

			$stored_id = (string) ( $item['skill']['proposal_id'] ?? '' );
			$matches   = $proposal_id === $stored_id || ( '' === $stored_id && self::skill_proposal_id( $item['skill'] ) === $proposal_id );

			if ( ! $matches ) {
				continue;
			}

			$item['skill']['proposal_id'] = $proposal_id;
			$item['skill']['status'] = $status;

			if ( 'saved' === $status && $saved_skill ) {
				$item['skill']['skill_id']    = (int) ( $saved_skill['id'] ?? 0 );
				$item['skill']['name']       = (string) ( $saved_skill['name'] ?? $item['skill']['name'] ?? '' );
				$item['skill']['description'] = (string) ( $saved_skill['description'] ?? $item['skill']['description'] ?? '' );
				$item['skill']['instructions'] = (string) ( $saved_skill['instructions'] ?? $item['skill']['instructions'] ?? '' );
				$item['skill']['existing_id'] = (int) ( $saved_skill['id'] ?? $item['skill']['existing_id'] ?? 0 );
			}

			$found = true;
			break;
		}
		unset( $item );

		if ( ! $found ) {
			return false;
		}

		return $this->update_transcript( $id, $user_id, $conversation['transcript'] );
	}

	/**
	 * Updates only the UI transcript of a conversation.
	 *
	 * @param int   $id         Conversation ID.
	 * @param int   $user_id    Owner.
	 * @param array $transcript Transcript items.
	 * @return bool True when the row was updated.
	 */
	private function update_transcript( int $id, int $user_id, array $transcript ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			self::table(),
			array(
				'transcript' => wp_json_encode( array_values( $transcript ), JSON_PARTIAL_OUTPUT_ON_ERROR ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Deletes a conversation owned by the user.
	 *
	 * @param int $id      Conversation ID.
	 * @param int $user_id Owner.
	 * @return bool True when a row was deleted.
	 */
	public function delete( int $id, int $user_id ): bool {
		global $wpdb;

		$deleted = $wpdb->delete( self::table(), array( 'id' => $id, 'user_id' => $user_id ), array( '%d', '%d' ) );

		return (bool) $deleted;
	}
}
