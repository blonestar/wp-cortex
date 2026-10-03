<?php
/**
 * Chat skill persistence (WordPress database).
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stores chat skills (saved procedures the assistant can follow) in a custom MySQL table.
 */
final class SkillStore {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'wp_cortex_skills_db_version';
	public const TABLE_SUFFIX      = 'wp_cortex_skills';

	public const MAX_NAME         = 64;
	public const MAX_DESCRIPTION  = 300;
	public const MAX_INSTRUCTIONS = 4000;

	public const SOURCE_USER  = 'user';
	public const SOURCE_AGENT = 'agent';

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
			name varchar(64) NOT NULL DEFAULT '',
			description varchar(300) NOT NULL DEFAULT '',
			instructions text NOT NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			source varchar(20) NOT NULL DEFAULT 'user',
			use_count int(10) unsigned NOT NULL DEFAULT 0,
			last_used_at datetime DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY name (name)
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
	 * Normalizes a skill name to a lowercase slug: letters, digits and hyphens.
	 *
	 * @param string $name Raw name.
	 */
	public static function normalize_name( string $name ): string {
		$name = strtolower( remove_accents( $name ) );
		$name = (string) preg_replace( '/[^a-z0-9]+/', '-', $name );

		return trim( substr( trim( $name, '-' ), 0, self::MAX_NAME ), '-' );
	}

	/**
	 * Validates and cleans skill fields.
	 *
	 * @param array $data Raw fields: name, description, instructions, active, source.
	 * @return array{name: string, description: string, instructions: string, active: bool, source: string}|WP_Error
	 */
	public static function sanitize( array $data ) {
		$name         = self::normalize_name( (string) ( $data['name'] ?? '' ) );
		$description  = mb_substr( sanitize_text_field( (string) ( $data['description'] ?? '' ) ), 0, self::MAX_DESCRIPTION );
		$instructions = trim( mb_substr( sanitize_textarea_field( (string) ( $data['instructions'] ?? '' ) ), 0, self::MAX_INSTRUCTIONS ) );
		$source       = self::SOURCE_AGENT === ( $data['source'] ?? '' ) ? self::SOURCE_AGENT : self::SOURCE_USER;

		if ( '' === $name ) {
			return new WP_Error( 'wp_cortex_skill_invalid', __( 'The skill name is required (letters, digits and hyphens).', 'wp-cortex' ), array( 'status' => 400 ) );
		}

		if ( '' === $description ) {
			return new WP_Error( 'wp_cortex_skill_invalid', __( 'The skill description is required.', 'wp-cortex' ), array( 'status' => 400 ) );
		}

		if ( '' === $instructions ) {
			return new WP_Error( 'wp_cortex_skill_invalid', __( 'The skill instructions are required.', 'wp-cortex' ), array( 'status' => 400 ) );
		}

		return array(
			'name'         => $name,
			'description'  => $description,
			'instructions' => $instructions,
			'active'       => ! isset( $data['active'] ) || (bool) $data['active'],
			'source'       => $source,
		);
	}

	/**
	 * Lists skills: most used first, then by name.
	 *
	 * @param bool $active_only Only active skills.
	 * @param int  $limit       Maximum rows, 0 for all.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( bool $active_only = false, int $limit = 0 ): array {
		global $wpdb;

		$table = self::table();
		$sql   = "SELECT * FROM {$table}" . ( $active_only ? ' WHERE active = 1' : '' ) . ' ORDER BY use_count DESC, name ASC';

		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( self::class, 'format' ), (array) $rows );
	}

	/**
	 * Loads a skill by ID.
	 *
	 * @param int $id Skill ID.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::format( $row ) : null;
	}

	/**
	 * Loads a skill by name.
	 *
	 * @param string $name Skill name.
	 * @return array<string, mixed>|null
	 */
	public function get_by_name( string $name ): ?array {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE name = %s", self::normalize_name( $name ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::format( $row ) : null;
	}

	/**
	 * Creates a skill.
	 *
	 * @param array $data    Raw fields, see sanitize().
	 * @param int   $user_id Author.
	 * @return int|WP_Error New skill ID.
	 */
	public function create( array $data, int $user_id ) {
		global $wpdb;

		$clean = self::sanitize( $data );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		if ( null !== $this->get_by_name( $clean['name'] ) ) {
			return $this->name_taken();
		}

		$now      = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'name'         => $clean['name'],
				'description'  => $clean['description'],
				'instructions' => $clean['instructions'],
				'active'       => $clean['active'] ? 1 : 0,
				'source'       => $clean['source'],
				'created_by'   => $user_id,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'wp_cortex_store_failed', __( 'Could not save the skill.', 'wp-cortex' ), array( 'status' => 500 ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates a skill. Missing fields keep their current value; the source is not changed.
	 *
	 * @param int   $id   Skill ID.
	 * @param array $data Raw fields, see sanitize().
	 * @return true|WP_Error
	 */
	public function update( int $id, array $data ) {
		global $wpdb;

		$current = $this->get( $id );

		if ( null === $current ) {
			return new WP_Error( 'wp_cortex_not_found', __( 'Skill not found.', 'wp-cortex' ), array( 'status' => 404 ) );
		}

		$clean = self::sanitize( array_merge( $current, array_intersect_key( $data, array_flip( array( 'name', 'description', 'instructions', 'active' ) ) ) ) );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$other = $this->get_by_name( $clean['name'] );

		if ( null !== $other && $other['id'] !== $id ) {
			return $this->name_taken();
		}

		$updated = $wpdb->update(
			self::table(),
			array(
				'name'         => $clean['name'],
				'description'  => $clean['description'],
				'instructions' => $clean['instructions'],
				'active'       => $clean['active'] ? 1 : 0,
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'wp_cortex_store_failed', __( 'Could not save the skill.', 'wp-cortex' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Deletes a skill.
	 *
	 * @param int $id Skill ID.
	 * @return bool True when a row was deleted.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Counts one use of a skill by the assistant.
	 *
	 * @param int $id Skill ID.
	 */
	public function record_use( int $id ): void {
		global $wpdb;

		$table = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET use_count = use_count + 1, last_used_at = %s WHERE id = %d", current_time( 'mysql', true ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Error for a name used by another skill.
	 */
	private function name_taken(): WP_Error {
		return new WP_Error( 'wp_cortex_skill_exists', __( 'A skill with this name already exists.', 'wp-cortex' ), array( 'status' => 409 ) );
	}

	/**
	 * Typed row.
	 *
	 * @param array $row Database row.
	 * @return array<string, mixed>
	 */
	private static function format( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'name'         => (string) $row['name'],
			'description'  => (string) $row['description'],
			'instructions' => (string) $row['instructions'],
			'active'       => (bool) (int) $row['active'],
			'source'       => (string) $row['source'],
			'use_count'    => (int) $row['use_count'],
			'last_used_at' => (string) ( $row['last_used_at'] ?? '' ),
			'created_by'   => (int) $row['created_by'],
			'created_at'   => (string) $row['created_at'],
			'updated_at'   => (string) $row['updated_at'],
		);
	}
}
