<?php
/**
 * Location and protection of the SQLite data directory.
 *
 * @package WPCortex
 */

namespace WPCortex\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves where the index databases live.
 *
 * Preferred: a directory outside the web root, set via the WP_CORTEX_DATA_DIR constant.
 * Fallback: a randomly named hidden directory in uploads (".wp-cortex-<random>"), protected
 * with deny rules. Nginx ignores the deny rules, but most Nginx configurations refuse paths
 * with a segment starting with a dot; where neither applies, the fallback relies on the
 * unguessable directory name.
 */
final class Storage {

	public const SCOPE_PUBLIC = 'public';
	public const SCOPE_ADMIN  = 'admin';

	public const SCOPES = array( self::SCOPE_PUBLIC, self::SCOPE_ADMIN );

	private const DIR_OPTION         = 'wp_cortex_data_dir_name';
	private const IMAGES_DIR         = 'visitor-images';
	private const EXPOSURE_TRANSIENT = 'wp_cortex_storage_exposed';
	private const DIR_PREFIX         = '.wp-cortex-';

	/**
	 * Directory name resolved in this request.
	 *
	 * @var string|null
	 */
	private static ?string $dir_name = null;

	/**
	 * Absolute path of the data directory (no trailing slash).
	 */
	public static function data_dir(): string {
		if ( defined( 'WP_CORTEX_DATA_DIR' ) && WP_CORTEX_DATA_DIR ) {
			return untrailingslashit( (string) WP_CORTEX_DATA_DIR );
		}

		return untrailingslashit( wp_upload_dir( null, false )['basedir'] ) . '/' . self::dir_name();
	}

	/**
	 * Whether the data directory sits inside the publicly served uploads directory.
	 */
	public static function is_in_uploads(): bool {
		return ! ( defined( 'WP_CORTEX_DATA_DIR' ) && WP_CORTEX_DATA_DIR );
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
		delete_transient( self::EXPOSURE_TRANSIENT );
		self::$dir_name = null;
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
		if ( self::is_in_uploads() ) {
			return untrailingslashit( wp_upload_dir( null, false )['baseurl'] ) . '/' . self::dir_name();
		}

		$dir  = wp_normalize_path( self::data_dir() );
		$root = trailingslashit( wp_normalize_path( ABSPATH ) );

		if ( 0 !== strpos( $dir . '/', $root ) ) {
			return null;
		}

		return untrailingslashit( site_url( '/' . ltrim( substr( $dir, strlen( $root ) ), '/' ) ) );
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
	 * Random, persistent, hidden directory name inside uploads.
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
