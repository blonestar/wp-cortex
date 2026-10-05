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
	 * Checks over HTTP whether the admin database can be downloaded. Result is cached.
	 *
	 * @return bool|null True if exposed, false if protected, null if it could not be determined.
	 */
	public static function is_exposed(): ?bool {
		if ( ! self::is_in_uploads() || ! file_exists( self::db_path( self::SCOPE_ADMIN ) ) ) {
			return false;
		}

		$cached = get_transient( self::EXPOSURE_TRANSIENT );
		if ( false !== $cached ) {
			return 'unknown' === $cached ? null : 'yes' === $cached;
		}

		$url      = untrailingslashit( wp_upload_dir( null, false )['baseurl'] ) . '/' . self::dir_name() . '/' . self::SCOPE_ADMIN . '.sqlite';
		$response = wp_remote_head(
			$url,
			array(
				'timeout'   => 3,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = 'unknown';
		} else {
			$result = 200 === wp_remote_retrieve_response_code( $response ) ? 'yes' : 'no';
		}

		set_transient( self::EXPOSURE_TRANSIENT, $result, 12 * HOUR_IN_SECONDS );

		return 'unknown' === $result ? null : 'yes' === $result;
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
