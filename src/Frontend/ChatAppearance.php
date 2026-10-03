<?php
/**
 * Visitor chat appearance settings as CSS.
 *
 * @package WPCortex
 */

namespace WPCortex\Frontend;

use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the public_chat_* appearance settings into CSS custom properties and classes
 * for the visitor chat root (front end) and its preview (settings screen).
 */
final class ChatAppearance {

	/**
	 * Size settings and the custom property each one sets, in pixels.
	 */
	private const SIZE_VARS = array(
		'public_chat_radius'        => '--wp-cortex-radius',
		'public_chat_width'         => '--wp-cortex-width',
		'public_chat_height'        => '--wp-cortex-height',
		'public_chat_launcher_size' => '--wp-cortex-launcher',
		'public_chat_offset'        => '--wp-cortex-offset',
		'public_chat_font_size'     => '--wp-cortex-font-size',
	);

	/**
	 * Custom properties from the saved settings.
	 *
	 * @return array<string, string> Property name => value.
	 */
	public static function vars(): array {
		$accent = (string) Settings::get( 'public_chat_accent' );
		$vars   = array(
			'--wp-cortex-accent'      => $accent,
			'--wp-cortex-accent-text' => self::text_on( $accent ),
		);

		foreach ( self::SIZE_VARS as $key => $property ) {
			$vars[ $property ] = (int) Settings::get( $key ) . 'px';
		}

		return $vars;
	}

	/**
	 * Declarations for a style attribute or rule body.
	 */
	public static function declarations(): string {
		$css = '';
		foreach ( self::vars() as $property => $value ) {
			$css .= $property . ':' . $value . ';';
		}

		return $css;
	}

	/**
	 * Classes for the color scheme, position and font.
	 *
	 * @return string[]
	 */
	public static function classes(): array {
		$classes = array( 'wp-cortex-pchat-scheme-' . sanitize_html_class( (string) Settings::get( 'public_chat_scheme' ) ) );

		if ( 'left' === Settings::get( 'public_chat_position' ) ) {
			$classes[] = 'wp-cortex-pchat-left';
		}
		if ( 'theme' === Settings::get( 'public_chat_font' ) ) {
			$classes[] = 'wp-cortex-pchat-font-theme';
		}

		return $classes;
	}

	/**
	 * Text color readable on a background: white or near black, whichever contrasts more.
	 * Mirrored in settings.js for the live preview.
	 *
	 * @param string $hex Background color, #rgb or #rrggbb.
	 */
	public static function text_on( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return '#fff';
		}

		$luminance = 0.0;
		foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
			$c          = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
			$luminance += $weight * ( $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4 );
		}

		// Contrast with white vs. with #1d2327 (luminance about 0.016).
		return 1.05 / ( $luminance + 0.05 ) >= ( $luminance + 0.05 ) / 0.066 ? '#fff' : '#1d2327';
	}
}
