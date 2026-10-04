<?php
/**
 * GitHub release updater.
 *
 * @package WPCortex
 */

namespace WPCortex\Update;

defined( 'ABSPATH' ) || exit;

/**
 * Connects stable GitHub releases to the native WordPress plugin updater.
 */
final class PluginUpdater {

	/**
	 * Plugin slug used by WordPress's Plugin Installation API.
	 */
	private const PLUGIN_SLUG = 'wp-cortex';

	/**
	 * Update URI declared in the plugin header.
	 */
	private const UPDATE_URI = 'https://github.com/blonestar/wp-cortex/';

	/**
	 * Public GitHub Releases endpoint.
	 */
	private const RELEASE_API_URL = 'https://api.github.com/repos/blonestar/wp-cortex/releases/latest';

	/**
	 * Release ZIP filename prefix.
	 */
	private const RELEASE_ASSET_PREFIX = 'wp-cortex-';

	/**
	 * Cached latest release metadata.
	 */
	private const RELEASE_TRANSIENT = 'wp_cortex_update_release';

	/**
	 * Cache duration matches WordPress's normal plugin update cadence.
	 */
	private const RELEASE_CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Minimum WordPress version advertised to the Plugin Installation API.
	 */
	private const REQUIRED_WP_VERSION = '7.0';

	/**
	 * Minimum PHP version advertised to the Plugin Installation API.
	 */
	private const REQUIRED_PHP_VERSION = '8.1';

	/**
	 * Registers the WordPress update and plugin-information hooks.
	 */
	public static function register(): void {
		add_filter( 'update_plugins_github.com', array( self::class, 'filter_update' ), 10, 4 );
		add_filter( 'plugins_api', array( self::class, 'filter_plugin_information' ), 10, 3 );
		add_action( 'load-update-core.php', array( self::class, 'clear_release_cache' ) );
		add_action( 'load-plugins.php', array( self::class, 'clear_release_cache' ) );
		add_action( 'upgrader_process_complete', array( self::class, 'clear_cache_after_update' ), 10, 2 );
	}

	/**
	 * Adds a newer stable GitHub release to WordPress's update response.
	 *
	 * @param mixed    $update Existing update response.
	 * @param array    $plugin_data Installed plugin headers.
	 * @param string   $plugin_file Plugin basename.
	 * @param string[] $locales Installed site locales.
	 * @return mixed Updated response or the original response.
	 */
	public static function filter_update( $update, array $plugin_data, string $plugin_file, array $locales ) {
		unset( $locales );

		if ( self::plugin_file() !== $plugin_file || self::UPDATE_URI !== ( $plugin_data['UpdateURI'] ?? '' ) ) {
			return $update;
		}

		$release = self::latest_release();

		if ( empty( $release ) || version_compare( WP_CORTEX_VERSION, $release['version'], '>=' ) ) {
			return $update;
		}

		return array(
			'id'           => self::UPDATE_URI,
			'slug'         => self::PLUGIN_SLUG,
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => $release['html_url'],
			'package'      => $release['package'],
			'requires'     => self::REQUIRED_WP_VERSION,
			'requires_php' => self::REQUIRED_PHP_VERSION,
			'last_updated' => $release['published_at'],
			'autoupdate'   => false,
		);
	}

	/**
	 * Supplies release details when WordPress opens the plugin-information modal.
	 *
	 * @param mixed  $result Existing Plugin Installation API response.
	 * @param string $action API action.
	 * @param object $args API arguments.
	 * @return mixed Plugin information or the original response.
	 */
	public static function filter_plugin_information( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::PLUGIN_SLUG !== sanitize_key( $args->slug ) ) {
			return $result;
		}

		$release = self::latest_release();

		if ( empty( $release ) ) {
			return $result;
		}

		$description  = '<p>' . esc_html__( 'WP Cortex indexes WordPress content into protected local SQLite databases for structured search, full-text search, vector search and AI chat.', 'wp-cortex' ) . '</p>';
		$installation = '<p>' . esc_html__( 'Install the ZIP from the WordPress Plugins screen, activate WP Cortex, then open Cortex > Settings.', 'wp-cortex' ) . '</p>';

		return (object) array(
			'name'              => 'WP Cortex',
			'slug'              => self::PLUGIN_SLUG,
			'version'           => $release['version'],
			'author'            => 'Bojan',
			'homepage'          => 'https://github.com/blonestar/wp-cortex',
			'requires'          => self::REQUIRED_WP_VERSION,
			'requires_php'      => self::REQUIRED_PHP_VERSION,
			'last_updated'      => $release['published_at'],
			'download_link'     => $release['package'],
			'short_description' => __( 'A local memory layer for WordPress content, search and AI chat.', 'wp-cortex' ),
			'sections'          => array(
				'description'  => $description,
				'installation' => $installation,
				'changelog'    => self::format_release_notes( $release['body'] ),
			),
		);
	}

	/**
	 * Clears the cached release before a manual update check.
	 */
	public static function clear_release_cache(): void {
		delete_transient( self::RELEASE_TRANSIENT );
	}

	/**
	 * Clears release metadata after this plugin has been upgraded.
	 *
	 * @param mixed $upgrader WordPress upgrader instance.
	 * @param array $options Upgrade options.
	 */
	public static function clear_cache_after_update( $upgrader, array $options ): void {
		unset( $upgrader );

		if ( 'plugin' !== ( $options['type'] ?? '' ) ) {
			return;
		}

		$updated_plugins = (array) ( $options['plugins'] ?? array() );

		if ( ! empty( $options['plugin'] ) ) {
			$updated_plugins[] = (string) $options['plugin'];
		}

		if ( ! in_array( self::plugin_file(), $updated_plugins, true ) ) {
			return;
		}

		self::clear_release_cache();
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * Returns the latest cached stable release, fetching it when necessary.
	 *
	 * @return array{version: string, tag: string, html_url: string, package: string, published_at: string, body: string} Release data or an empty array.
	 */
	private static function latest_release(): array {
		$cached = get_transient( self::RELEASE_TRANSIENT );

		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		$release = self::fetch_release();
		set_transient( self::RELEASE_TRANSIENT, $release, self::RELEASE_CACHE_TTL );

		return $release;
	}

	/**
	 * Fetches and validates the latest stable GitHub release.
	 *
	 * @return array{version: string, tag: string, html_url: string, package: string, published_at: string, body: string} Release data or an empty array.
	 */
	private static function fetch_release(): array {
		$response = wp_safe_remote_get(
			self::RELEASE_API_URL,
			array(
				'timeout'     => 5,
				'redirection' => 3,
				'headers'     => array(
					'Accept'               => 'application/vnd.github+json',
					'User-Agent'           => 'WP Cortex/' . WP_CORTEX_VERSION,
					'X-GitHub-Api-Version' => '2022-11-28',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? self::normalize_release( $data ) : array();
	}

	/**
	 * Validates the release shape and selects the package built by our workflow.
	 *
	 * @param array<string, mixed> $data GitHub API response.
	 * @return array{version: string, tag: string, html_url: string, package: string, published_at: string, body: string} Release data or an empty array.
	 */
	private static function normalize_release( array $data ): array {
		if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return array();
		}

		$tag = isset( $data['tag_name'] ) ? trim( (string) $data['tag_name'] ) : '';

		if ( ! preg_match( '/^v([0-9]+\.[0-9]+\.[0-9]+)$/D', $tag, $matches ) ) {
			return array();
		}

		$version                = $matches[1];
		$html_url               = (string) ( $data['html_url'] ?? '' );
		$expected_release_path  = '/blonestar/wp-cortex/releases/tag/' . $tag;
		$asset_name             = self::RELEASE_ASSET_PREFIX . $tag . '.zip';
		$expected_package_path  = '/blonestar/wp-cortex/releases/download/' . $tag . '/' . $asset_name;
		$package                = '';

		if ( ! self::is_allowed_github_url( $html_url, $expected_release_path ) ) {
			return array();
		}

		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( ! is_array( $asset ) || $asset_name !== (string) ( $asset['name'] ?? '' ) ) {
				continue;
			}

			$asset_url = (string) ( $asset['browser_download_url'] ?? '' );

			if ( self::is_allowed_github_url( $asset_url, $expected_package_path ) ) {
				$package = $asset_url;
				break;
			}
		}

		if ( '' === $package ) {
			return array();
		}

		return array(
			'version'      => $version,
			'tag'          => $tag,
			'html_url'     => esc_url_raw( $html_url ),
			'package'      => esc_url_raw( $package ),
			'published_at' => sanitize_text_field( (string) ( $data['published_at'] ?? '' ) ),
			'body'         => is_string( $data['body'] ?? null ) ? $data['body'] : '',
		);
	}

	/**
	 * Checks that a release URL points to the expected GitHub repository and path.
	 *
	 * @param string $url URL returned by GitHub.
	 * @param string $expected_path Expected path.
	 */
	private static function is_allowed_github_url( string $url, string $expected_path ): bool {
		$parts = wp_parse_url( $url );

		return is_array( $parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& 'github.com' === strtolower( (string) ( $parts['host'] ?? '' ) )
			&& $expected_path === (string) ( $parts['path'] ?? '' );
	}

	/**
	 * Converts plain-text GitHub release notes into safe Plugin API HTML.
	 *
	 * @param string $notes Release notes.
	 */
	private static function format_release_notes( string $notes ): string {
		if ( '' === trim( $notes ) ) {
			return '<p>' . esc_html__( 'See the GitHub release for details.', 'wp-cortex' ) . '</p>';
		}

		return wp_kses_post( wpautop( make_clickable( esc_html( $notes ) ) ) );
	}

	/**
	 * Returns the installed plugin basename.
	 */
	private static function plugin_file(): string {
		return plugin_basename( WP_CORTEX_FILE );
	}
}
