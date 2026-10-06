<?php
/**
 * Location and protection of the SQLite data directory.
 *
 * @package WPCortex
 */

namespace WPCortex\Storage;

use WPCortex\Indexing\IndexRun;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves where the index databases live.
 *
 * The data directory is a randomly named hidden directory (".wp-cortex-<random>"). Its parent
 * is, in order of preference:
 *
 * 1. The WP_CORTEX_DATA_DIR constant, used as the data directory itself.
 * 2. The host's private directory that the web server never serves (WP Engine, Pantheon).
 * 3. The directory above the web root, when PHP can write to it.
 * 4. Fallback: uploads, protected with deny rules. Nginx ignores the deny rules, but most
 *    Nginx configurations refuse paths with a segment starting with a dot; where neither
 *    applies, the fallback relies on the unguessable directory name.
 *
 * The first writable location is chosen once and stored (as a location key, not a path, so
 * copies of the site to other environments resolve it again). Data that earlier versions
 * stored in uploads is moved to a private location by maybe_relocate().
 */
final class Storage {

	public const SCOPE_PUBLIC = 'public';
	public const SCOPE_ADMIN  = 'admin';

	public const SCOPES = array( self::SCOPE_PUBLIC, self::SCOPE_ADMIN );

	private const DIR_OPTION         = 'wp_cortex_data_dir_name';
	private const LOCATION_OPTION    = 'wp_cortex_data_location';
	private const IMAGES_DIR         = 'visitor-images';
	private const EXPOSURE_TRANSIENT = 'wp_cortex_storage_exposed';
	private const DIR_PREFIX         = '.wp-cortex-';

	public const LOCATION_CONSTANT = 'constant';
	public const LOCATION_WPENGINE = 'wpengine';
	public const LOCATION_PANTHEON = 'pantheon';
	public const LOCATION_OUTSIDE  = 'outside';
	public const LOCATION_UPLOADS  = 'uploads';

	/**
	 * Automatic locations, in order of preference (uploads is the fallback).
	 */
	private const LOCATIONS = array( self::LOCATION_WPENGINE, self::LOCATION_PANTHEON, self::LOCATION_OUTSIDE, self::LOCATION_UPLOADS );

	/**
	 * Directory name resolved in this request.
	 *
	 * @var string|null
	 */
	private static ?string $dir_name = null;

	/**
	 * Location key resolved in this request.
	 *
	 * @var string|null
	 */
	private static ?string $location = null;

	/**
	 * Absolute path of the data directory (no trailing slash).
	 */
	public static function data_dir(): string {
		$location = self::location();

		if ( self::LOCATION_CONSTANT === $location ) {
			return untrailingslashit( (string) WP_CORTEX_DATA_DIR );
		}

		return self::location_base( $location ) . '/' . self::dir_name();
	}

	/**
	 * Where the data directory is: one of the LOCATION_* constants.
	 *
	 * Chooses and stores the location on first use. While data of an earlier version is
	 * still in uploads, that is the location until maybe_relocate() moves it.
	 */
	public static function location(): string {
		if ( defined( 'WP_CORTEX_DATA_DIR' ) && WP_CORTEX_DATA_DIR ) {
			return self::LOCATION_CONSTANT;
		}

		if ( null !== self::$location ) {
			return self::$location;
		}

		$stored = get_option( self::LOCATION_OPTION );

		if ( is_string( $stored ) && in_array( $stored, self::LOCATIONS, true ) && null !== self::location_base( $stored ) ) {
			self::$location = $stored;
		} elseif ( false === $stored && self::has_legacy_dir() ) {
			self::$location = self::LOCATION_UPLOADS;
		} else {
			self::$location = self::pick_location();
			update_option( self::LOCATION_OPTION, self::$location, false );
		}

		return self::$location;
	}

	/**
	 * Whether the data directory sits inside the publicly served uploads directory, as the
	 * fallback location.
	 */
	public static function is_in_uploads(): bool {
		return self::LOCATION_UPLOADS === self::location();
	}

	/**
	 * Moves data that earlier versions stored in uploads to the first writable private
	 * location. Runs once per site, on an admin request, and not while an index run is
	 * running; stays in uploads when no private location is writable or the move fails.
	 */
	public static function maybe_relocate(): void {
		if ( self::LOCATION_CONSTANT === self::location() || false !== get_option( self::LOCATION_OPTION ) ) {
			return;
		}

		$run = IndexRun::state();
		if ( $run && 'running' === $run['status'] ) {
			return;
		}

		// Claims the move: add_option() fails when another request stored a location first.
		if ( ! add_option( self::LOCATION_OPTION, self::LOCATION_UPLOADS, '', false ) ) {
			return;
		}

		$target = self::pick_location();

		if ( self::LOCATION_UPLOADS !== $target && self::move_dir( self::location_base( self::LOCATION_UPLOADS ) . '/' . self::dir_name(), self::location_base( $target ) . '/' . self::dir_name() ) ) {
			update_option( self::LOCATION_OPTION, $target, false );
			self::$location = $target;
			delete_transient( self::EXPOSURE_TRANSIENT );
			return;
		}

		self::$location = self::LOCATION_UPLOADS;
	}

	/**
	 * Human-readable description of the data directory location.
	 */
	public static function location_label(): string {
		switch ( self::location() ) {
			case self::LOCATION_CONSTANT:
				return __( 'set by WP_CORTEX_DATA_DIR', 'wp-cortex' );
			case self::LOCATION_WPENGINE:
				return __( 'WP Engine private directory (_wpeprivate)', 'wp-cortex' );
			case self::LOCATION_PANTHEON:
				return __( 'Pantheon private directory (uploads/private)', 'wp-cortex' );
			case self::LOCATION_OUTSIDE:
				return __( 'outside the web root', 'wp-cortex' );
			default:
				return __( 'uploads directory', 'wp-cortex' );
		}
	}

	/**
	 * Absolute path of a scope's database file.
	 *
	 * @param string $scope One of self::SCOPES.
	 */
	public static function db_path( string $scope ): string {
		return self::data_dir() . '/' . $scope . '.sqlite';
	}

	/**
	 * Absolute path of the directory with the images visitors attach in the visitor chat,
	 * one subdirectory per conversation.
	 */
	public static function visitor_images_dir(): string {
		return self::data_dir() . '/' . self::IMAGES_DIR;
	}

	/**
	 * Creates the data directory with deny rules. Safe to call repeatedly.
	 *
	 * @throws \RuntimeException When the directory cannot be created.
	 */
	public static function ensure_data_dir(): string {
		$dir = self::data_dir();

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( sprintf( 'WP Cortex could not create its data directory: %s', $dir ) );
		}

		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);

		foreach ( $files as $name => $contents ) {
			if ( ! file_exists( "$dir/$name" ) ) {
				file_put_contents( "$dir/$name", $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}

		return $dir;
	}

	/**
	 * Deletes a scope's database (including WAL/SHM side files).
	 *
	 * @param string $scope One of self::SCOPES.
	 */
	public static function delete_db( string $scope ): void {
		Database::close( $scope );

		foreach ( array( '', '-wal', '-shm' ) as $suffix ) {
			$file = self::db_path( $scope ) . $suffix;
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Removes the whole data directory. Used on uninstall.
	 */
	public static function delete_data_dir(): void {
		if ( ! ( defined( 'WP_CORTEX_DATA_DIR' ) && WP_CORTEX_DATA_DIR ) && false === get_option( self::LOCATION_OPTION ) && ! self::has_legacy_dir() ) {
			// Nothing was ever stored: do not choose (and create) a location just to delete it.
			delete_option( self::DIR_OPTION );
			delete_option( self::LOCATION_OPTION );
			delete_transient( self::EXPOSURE_TRANSIENT );
			return;
		}

		foreach ( self::SCOPES as $scope ) {
			self::delete_db( $scope );
		}

		self::delete_dir( self::visitor_images_dir() );

		$dir = self::data_dir();
		foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $name ) {
			if ( file_exists( "$dir/$name" ) ) {
				wp_delete_file( "$dir/$name" );
			}
		}

		if ( is_dir( $dir ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		delete_option( self::DIR_OPTION );
		delete_option( self::LOCATION_OPTION );
		delete_transient( self::EXPOSURE_TRANSIENT );
		self::$dir_name = null;
		self::$location = null;
	}

	/**
	 * Deletes a directory with its files and subdirectories. Symbolic links are removed,
	 * never followed.
	 *
	 * @param string $dir Absolute path.
	 */
	public static function delete_dir( string $dir ): void {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}

		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$path = $dir . '/' . $name;

			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::delete_dir( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Whether a database file can be downloaded over HTTP, from the cached exposure report.
	 *
	 * @return bool|null True if exposed, false if protected, null if it could not be determined.
	 */
	public static function is_exposed(): ?bool {
		$status = self::exposure_report()['status'];

		if ( 'unknown' === $status ) {
			return null;
		}

		return 'exposed' === $status;
	}

	/**
	 * Checks over HTTP whether the data directory lists its files and whether the database
	 * files can be downloaded. The result is cached for 12 hours, or until a database file
	 * appears or disappears.
	 *
	 * @param bool $force Run the check again even when a cached result exists.
	 * @return array{status: string, checked_at: int, present: string[], checks: array<int, array{target: string, status: string, code: int, error: string}>}
	 *         Status is "ok", "exposed", "unknown" (the server could not reach itself) or
	 *         "outside" (the directory is outside the web root and is not tested). Check
	 *         statuses are "protected", "exposed", "reachable" (directory answers without
	 *         listing files), "missing" (database not created yet) or "unknown".
	 */
	public static function exposure_report( bool $force = false ): array {
		$present = array_values(
			array_filter(
				self::SCOPES,
				static function ( string $scope ): bool {
					return file_exists( self::db_path( $scope ) );
				}
			)
		);

		$cached = get_transient( self::EXPOSURE_TRANSIENT );
		if ( ! $force && is_array( $cached ) && isset( $cached['status'], $cached['present'] ) && $cached['present'] === $present ) {
			return $cached;
		}

		$report = array(
			'status'     => 'ok',
			'checked_at' => time(),
			'present'    => $present,
			'checks'     => array(),
		);

		$base_url = self::data_dir_url();

		if ( null === $base_url ) {
			$report['status'] = 'outside';
		} else {
			$report['checks'][] = self::check_directory( $base_url );

			foreach ( self::SCOPES as $scope ) {
				$report['checks'][] = in_array( $scope, $present, true )
					? self::check_database( $scope, $base_url . '/' . $scope . '.sqlite' )
					: self::check_result( $scope, 'missing' );
			}

			$statuses = wp_list_pluck( $report['checks'], 'status' );

			if ( in_array( 'exposed', $statuses, true ) ) {
				$report['status'] = 'exposed';
			} elseif ( in_array( 'unknown', $statuses, true ) ) {
				$report['status'] = 'unknown';
			}
		}

		set_transient( self::EXPOSURE_TRANSIENT, $report, 12 * HOUR_IN_SECONDS );

		return $report;
	}

	/**
	 * Public URL of the data directory (no trailing slash), or null when it is outside the
	 * WordPress directory and therefore not served by the site.
	 */
	private static function data_dir_url(): ?string {
		$dir     = wp_normalize_path( self::data_dir() );
		$uploads = wp_upload_dir( null, false );
		$bases   = array(
			trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) => trailingslashit( $uploads['baseurl'] ),
			trailingslashit( wp_normalize_path( ABSPATH ) )             => trailingslashit( site_url( '/' ) ),
		);

		foreach ( $bases as $path => $url ) {
			if ( 0 === strpos( $dir . '/', $path ) ) {
				return untrailingslashit( $url . ltrim( substr( $dir, strlen( $path ) ), '/' ) );
			}
		}

		return null;
	}

	/**
	 * Requests the data directory itself: exposed when the response lists the database files.
	 *
	 * @param string $url Directory URL.
	 * @return array{target: string, status: string, code: int, error: string}
	 */
	private static function check_directory( string $url ): array {
		$response = self::probe( trailingslashit( $url ), 65536, array() );

		if ( is_wp_error( $response ) ) {
			return self::check_result( 'directory', 'unknown', 0, $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return self::check_result( 'directory', 'protected', $code );
		}

		$listed = false !== strpos( wp_remote_retrieve_body( $response ), '.sqlite' );

		return self::check_result( 'directory', $listed ? 'exposed' : 'reachable', $code );
	}

	/**
	 * Requests the first bytes of a database file: exposed when they are the SQLite header,
	 * so a "200" error page or a redirect to the home page does not count.
	 *
	 * @param string $scope One of self::SCOPES.
	 * @param string $url   Database file URL.
	 * @return array{target: string, status: string, code: int, error: string}
	 */
	private static function check_database( string $scope, string $url ): array {
		$response = self::probe( $url, 16, array( 'Range' => 'bytes=0-15' ) );

		if ( is_wp_error( $response ) ) {
			return self::check_result( $scope, 'unknown', 0, $response->get_error_message() );
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$sqlite = in_array( $code, array( 200, 206 ), true ) && 0 === strpos( wp_remote_retrieve_body( $response ), 'SQLite format 3' );

		return self::check_result( $scope, $sqlite ? 'exposed' : 'protected', $code );
	}

	/**
	 * Unauthenticated GET request from the server to its own URL.
	 *
	 * @param string                $url     URL.
	 * @param int                   $limit   Maximum number of body bytes to read.
	 * @param array<string, string> $headers Extra request headers.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function probe( string $url, int $limit, array $headers ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'sslverify'           => false,
				'limit_response_size' => $limit,
				'headers'             => $headers,
				'cookies'             => array(),
			)
		);
	}

	/**
	 * Builds one entry of the exposure report.
	 *
	 * @param string $target "directory" or a scope.
	 * @param string $status Check status.
	 * @param int    $code   HTTP status code, 0 when no response was received.
	 * @param string $error  Request error message.
	 * @return array{target: string, status: string, code: int, error: string}
	 */
	private static function check_result( string $target, string $status, int $code = 0, string $error = '' ): array {
		return array(
			'target' => $target,
			'status' => $status,
			'code'   => $code,
			'error'  => $error,
		);
	}

	/**
	 * First automatic location PHP can write to; uploads when no other one is writable.
	 */
	private static function pick_location(): string {
		foreach ( self::LOCATIONS as $location ) {
			$base = self::location_base( $location );

			if ( null !== $base && ( self::LOCATION_UPLOADS === $location || self::is_writable_dir( $base ) ) ) {
				return $location;
			}
		}

		return self::LOCATION_UPLOADS;
	}

	/**
	 * Parent directory of the data directory for an automatic location (no trailing slash),
	 * or null when the location does not apply to this site.
	 *
	 * @param string $location One of self::LOCATIONS.
	 */
	private static function location_base( string $location ): ?string {
		switch ( $location ) {
			case self::LOCATION_WPENGINE:
				$base = untrailingslashit( wp_normalize_path( ABSPATH ) ) . '/_wpeprivate';
				return ( defined( 'WPE_APIKEY' ) || function_exists( 'is_wpe' ) ) && @is_dir( $base ) ? $base : null; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			case self::LOCATION_PANTHEON:
				$base = untrailingslashit( wp_normalize_path( wp_upload_dir( null, false )['basedir'] ) ) . '/private';
				return defined( 'PANTHEON_ENVIRONMENT' ) || ! empty( $_ENV['PANTHEON_ENVIRONMENT'] ) ? $base : null;

			case self::LOCATION_OUTSIDE:
				return self::outside_base();

			case self::LOCATION_UPLOADS:
				return untrailingslashit( wp_upload_dir( null, false )['basedir'] );
		}

		return null;
	}

	/**
	 * Directory above the web root (the directory that serves the site's home URL), or null
	 * when it cannot be determined or could be served by the web server.
	 */
	private static function outside_base(): ?string {
		$abspath = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$path    = trim( (string) wp_parse_url( is_multisite() ? network_site_url() : site_url(), PHP_URL_PATH ), '/' );

		// WordPress in a subdirectory: ABSPATH ends with the site URL path.
		if ( '' !== $path ) {
			if ( substr( $abspath, -strlen( $path ) - 1 ) !== '/' . $path ) {
				return null;
			}
			$abspath = substr( $abspath, 0, -strlen( $path ) - 1 );
		}

		$parent = dirname( $abspath );

		if ( '' === $parent || '/' === $parent || '.' === $parent || $parent === $abspath || preg_match( '#^[A-Za-z]:/?$#', $parent ) ) {
			return null;
		}

		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) $_SERVER['DOCUMENT_ROOT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$parent_real   = @realpath( $parent ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$root_real     = '' !== $document_root ? @realpath( $document_root ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $parent_real ) {
			return null;
		}

		if ( false !== $root_real && 0 === strpos( trailingslashit( wp_normalize_path( $parent_real ) ), trailingslashit( wp_normalize_path( $root_real ) ) ) ) {
			return null;
		}

		return $parent;
	}

	/**
	 * Whether PHP can create files in a directory, tested with a real write: is_writable()
	 * is unreliable on network file systems and read-only mounts. Creates the directory
	 * when it is missing (and its parent exists).
	 *
	 * @param string $dir Absolute path.
	 */
	private static function is_writable_dir( string $dir ): bool {
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
		if ( ! @is_dir( $dir ) && ( ! @is_dir( dirname( $dir ) ) || ! @mkdir( $dir, 0755 ) ) ) {
			return false;
		}

		$probe = $dir . '/' . self::DIR_PREFIX . 'probe-' . strtolower( wp_generate_password( 8, false ) );

		if ( false === @file_put_contents( $probe, '1' ) ) {
			return false;
		}

		@unlink( $probe );
		// phpcs:enable

		return true;
	}

	/**
	 * Whether an earlier version left a data directory in uploads, before locations were
	 * stored.
	 */
	private static function has_legacy_dir(): bool {
		$name = get_option( self::DIR_OPTION );

		if ( ! is_string( $name ) || '' === $name ) {
			return false;
		}

		$base = untrailingslashit( wp_upload_dir( null, false )['basedir'] );

		return is_dir( "$base/$name" ) || is_dir( "$base/.$name" );
	}

	/**
	 * Moves the data directory: renamed when possible (atomic on one file system), copied
	 * and then deleted otherwise. A failed copy is removed and the source kept.
	 *
	 * @param string $from Current directory.
	 * @param string $to   New directory; must not exist yet.
	 */
	private static function move_dir( string $from, string $to ): bool {
		if ( ! is_dir( $from ) ) {
			return true;
		}

		if ( file_exists( $to ) ) {
			return false;
		}

		foreach ( self::SCOPES as $scope ) {
			Database::close( $scope );
		}

		if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			return true;
		}

		if ( ! self::copy_dir( $from, $to ) ) {
			self::delete_dir( $to );
			return false;
		}

		self::delete_dir( $from );

		return true;
	}

	/**
	 * Copies a directory with its files and subdirectories. Symbolic links are skipped.
	 *
	 * @param string $from Source directory.
	 * @param string $to   Target directory.
	 */
	private static function copy_dir( string $from, string $to ): bool {
		if ( ! wp_mkdir_p( $to ) ) {
			return false;
		}

		foreach ( (array) scandir( $from ) as $name ) {
			$source = $from . '/' . $name;

			if ( '.' === $name || '..' === $name || is_link( $source ) ) {
				continue;
			}

			$target = $to . '/' . $name;
			$copied = is_dir( $source ) ? self::copy_dir( $source, $target ) : @copy( $source, $target ) && filesize( $source ) === filesize( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( ! $copied ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Random, persistent, hidden directory name.
	 */
	private static function dir_name(): string {
		if ( null !== self::$dir_name ) {
			return self::$dir_name;
		}

		$name = get_option( self::DIR_OPTION );

		if ( ! is_string( $name ) || '' === $name ) {
			$name = self::DIR_PREFIX . strtolower( wp_generate_password( 16, false ) );
			update_option( self::DIR_OPTION, $name, false );
		} elseif ( '.' !== $name[0] ) {
			$name = self::hide_dir( $name );
		}

		self::$dir_name = $name;

		return $name;
	}

	/**
	 * Renames a directory created before hidden names were used ("wp-cortex-<random>") to
	 * its hidden name (".wp-cortex-<random>"), so web servers that refuse dot paths stop
	 * serving it. Keeps the old name when the rename fails; it is retried on a later request.
	 *
	 * @param string $name Current directory name.
	 * @return string Directory name to use.
	 */
	private static function hide_dir( string $name ): string {
		$base   = untrailingslashit( wp_upload_dir( null, false )['basedir'] );
		$hidden = '.' . $name;

		if ( is_dir( "$base/$name" ) && ! file_exists( "$base/$hidden" ) ) {
			foreach ( self::SCOPES as $scope ) {
				Database::close( $scope );
			}

			if ( ! @rename( "$base/$name", "$base/$hidden" ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				return $name;
			}
		} elseif ( is_dir( "$base/$name" ) ) {
			// Both exist: never overwrite or merge, keep using the directory with the data.
			return $name;
		}

		update_option( self::DIR_OPTION, $hidden, false );
		delete_transient( self::EXPOSURE_TRANSIENT );

		return $hidden;
	}
}
