<?php
/**
 * Reads CHANGELOG.md for the Changelog tab of the settings screen.
 *
 * @package WPCortex
 */

namespace WPCortex\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Parses the Keep a Changelog file shipped with the plugin into releases and renders them.
 */
final class Changelog {

	/**
	 * Path of the changelog file.
	 */
	private string $path;

	/**
	 * Constructor.
	 *
	 * @param string $path Changelog file; the plugin's CHANGELOG.md by default.
	 */
	public function __construct( string $path = '' ) {
		$this->path = '' !== $path ? $path : WP_CORTEX_DIR . 'CHANGELOG.md';
	}

	/**
	 * Releases, newest first.
	 *
	 * Each release has its version ("Unreleased" for pending changes), date (may be empty)
	 * and groups: change type ("Added", "Fixed"...) => list of entries in Markdown.
	 *
	 * @return array<int, array{version: string, date: string, groups: array<string, string[]>}>
	 */
	public function releases(): array {
		if ( ! is_readable( $this->path ) ) {
			return array();
		}

		$lines    = preg_split( '/\r\n|\r|\n/', (string) file_get_contents( $this->path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.
		$releases = array();
		$release  = -1;
		$group    = '';
		$entry    = -1;

		foreach ( $lines as $line ) {
			if ( preg_match( '/^##\s+\[?([^\]\s]+)\]?(?:\s+-\s+(\S+))?/', $line, $match ) ) {
				$releases[] = array(
					'version' => $match[1],
					'date'    => $match[2] ?? '',
					'groups'  => array(),
				);
				$release    = count( $releases ) - 1;
				$group      = '';
				$entry      = -1;
				continue;
			}

			if ( $release < 0 ) {
				continue;
			}

			if ( preg_match( '/^###\s+(.+)$/', $line, $match ) ) {
				$group = trim( $match[1] );

				$releases[ $release ]['groups'][ $group ] = array();
				$entry                                    = -1;
				continue;
			}

			if ( '' === $group ) {
				continue;
			}

			if ( preg_match( '/^[-*]\s+(.+)$/', $line, $match ) ) {
				$releases[ $release ]['groups'][ $group ][] = trim( $match[1] );

				$entry = count( $releases[ $release ]['groups'][ $group ] ) - 1;
			} elseif ( $entry >= 0 && preg_match( '/^\s+(\S.*)$/', $line, $match ) ) {
				// Indented continuation of the previous entry.
				$releases[ $release ]['groups'][ $group ][ $entry ] .= ' ' . trim( $match[1] );
			} elseif ( '' === trim( $line ) ) {
				$entry = -1;
			}
		}

		// Hide an empty Unreleased section.
		return array_values(
			array_filter(
				$releases,
				static function ( array $item ): bool {
					return array() !== array_filter( $item['groups'] );
				}
			)
		);
	}

	/**
	 * Renders the releases as collapsible panels.
	 *
	 * Pending changes and the latest release start expanded; the installed version is marked.
	 */
	public function render(): void {
		$releases = $this->releases();

		if ( array() === $releases ) {
			echo '<div class="wp-cortex-card wp-cortex-settings-card"><p>' . esc_html__( 'The changelog file could not be read.', 'wp-cortex' ) . '</p></div>';
			return;
		}

		echo '<div class="wp-cortex-changelog">';
		echo '<div class="wp-cortex-changelog-toolbar">';
		echo '<p class="wp-cortex-section-intro">' . esc_html__( 'Notable changes in each version of WP Cortex.', 'wp-cortex' ) . '</p>';
		echo '<div class="wp-cortex-changelog-actions">';
		echo '<button type="button" class="button-link" data-changelog-toggle="open">' . esc_html__( 'Expand all', 'wp-cortex' ) . '</button>';
		echo '<span aria-hidden="true">|</span>';
		echo '<button type="button" class="button-link" data-changelog-toggle="close">' . esc_html__( 'Collapse all', 'wp-cortex' ) . '</button>';
		echo '</div></div>';

		$opened = 0;
		foreach ( $releases as $release ) {
			$is_unreleased = 'unreleased' === strtolower( $release['version'] );
			$is_installed  = WP_CORTEX_VERSION === $release['version'];
			$is_open       = $is_unreleased || 0 === $opened;

			if ( ! $is_unreleased ) {
				++$opened;
			}

			printf(
				'<details class="wp-cortex-card wp-cortex-changelog-release%1$s"%2$s>',
				$is_installed ? ' is-installed' : '',
				$is_open ? ' open' : ''
			);

			echo '<summary><span class="wp-cortex-changelog-version">';
			echo esc_html( $is_unreleased ? __( 'Unreleased', 'wp-cortex' ) : $release['version'] );
			echo '</span>';

			if ( $is_installed ) {
				echo '<span class="wp-cortex-changelog-badge is-installed">' . esc_html__( 'Installed', 'wp-cortex' ) . '</span>';
			} elseif ( $is_unreleased ) {
				echo '<span class="wp-cortex-changelog-badge">' . esc_html__( 'In development', 'wp-cortex' ) . '</span>';
			}

			if ( '' !== $release['date'] ) {
				$time = strtotime( $release['date'] );
				printf(
					'<time class="wp-cortex-changelog-date" datetime="%1$s">%2$s</time>',
					esc_attr( $release['date'] ),
					esc_html( false !== $time ? date_i18n( get_option( 'date_format' ), $time ) : $release['date'] )
				);
			}

			echo '<span class="wp-cortex-changelog-counts">';
			foreach ( $release['groups'] as $type => $entries ) {
				if ( array() === $entries ) {
					continue;
				}
				printf(
					'<span class="wp-cortex-changelog-count %1$s">%2$s</span>',
					esc_attr( $this->type_class( $type ) ),
					esc_html( sprintf( '%d %s', count( $entries ), $this->type_label( $type ) ) )
				);
			}
			echo '</span></summary>';

			echo '<div class="wp-cortex-changelog-body">';
			foreach ( $release['groups'] as $type => $entries ) {
				if ( array() === $entries ) {
					continue;
				}
				printf(
					'<h3><span class="wp-cortex-changelog-type %1$s">%2$s</span></h3><ul>',
					esc_attr( $this->type_class( $type ) ),
					esc_html( $this->type_label( $type ) )
				);
				foreach ( $entries as $text ) {
					echo '<li>' . $this->inline( $text ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in inline().
				}
				echo '</ul>';
			}
			echo '</div></details>';
		}

		echo '</div>';
	}

	/**
	 * Translated label of a change type.
	 *
	 * @param string $type Change type heading from the file.
	 */
	private function type_label( string $type ): string {
		$labels = array(
			'added'      => __( 'Added', 'wp-cortex' ),
			'changed'    => __( 'Changed', 'wp-cortex' ),
			'deprecated' => __( 'Deprecated', 'wp-cortex' ),
			'removed'    => __( 'Removed', 'wp-cortex' ),
			'fixed'      => __( 'Fixed', 'wp-cortex' ),
			'security'   => __( 'Security', 'wp-cortex' ),
		);

		return $labels[ strtolower( $type ) ] ?? $type;
	}

	/**
	 * CSS class of a change type.
	 *
	 * @param string $type Change type heading from the file.
	 */
	private function type_class( string $type ): string {
		return 'is-' . sanitize_html_class( strtolower( $type ) );
	}

	/**
	 * Converts the inline Markdown used in entries (code, bold, links) to escaped HTML.
	 *
	 * @param string $text Entry text.
	 */
	private function inline( string $text ): string {
		$html = esc_html( $text );

		// Code spans first so their contents are not formatted further.
		$codes = array();
		$html  = (string) preg_replace_callback(
			'/`([^`]+)`/',
			static function ( array $match ) use ( &$codes ): string {
				$codes[] = '<code>' . $match[1] . '</code>';
				return "\x00" . ( count( $codes ) - 1 ) . "\x00";
			},
			$html
		);

		$html = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
		$html = (string) preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( array $match ): string {
				$url = esc_url( wp_specialchars_decode( $match[2] ), array( 'http', 'https' ) );
				return '' !== $url ? '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $match[1] . '</a>' : $match[1];
			},
			$html
		);

		return (string) preg_replace_callback(
			"/\x00(\d+)\x00/",
			static function ( array $match ) use ( $codes ): string {
				return $codes[ (int) $match[1] ];
			},
			$html
		);
	}
}
