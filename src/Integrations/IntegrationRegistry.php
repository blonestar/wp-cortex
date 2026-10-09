<?php
/**
 * Registered lead integrations and their settings.
 *
 * @package WPCortex
 */

namespace WPCortex\Integrations;

use WPCortex\Integrations\Builtin\HubSpot;
use WPCortex\Integrations\Builtin\Webhook;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the integrations: the built-in ones, then those of the theme's
 * wp-cortex/integrations/ files (each returns an Integration; a child theme file replaces
 * the parent theme file with the same name; files starting with "_" are skipped), then
 * the wp_cortex_lead_integrations filter. Settings are stored in the `integrations`
 * setting, keyed by integration ID.
 */
final class IntegrationRegistry {

	/**
	 * Folder of the theme's integration files.
	 */
	public const THEME_DIRECTORY = 'wp-cortex/integrations';

	/**
	 * Events an integration can receive.
	 */
	public const EVENTS = array( 'created', 'updated' );

	/**
	 * Longest text setting.
	 */
	private const MAX_TEXT = 2000;

	/**
	 * Integrations of this request, keyed by ID.
	 *
	 * @var array<string, Integration>|null
	 */
	private static ?array $integrations = null;

	/**
	 * Every integration, keyed by ID, in the order they were added.
	 *
	 * @return array<string, Integration>
	 */
	public static function all(): array {
		if ( null !== self::$integrations ) {
			return self::$integrations;
		}

		$list = array( new Webhook(), new HubSpot() );

		foreach ( self::theme_integrations() as $integration ) {
			$list[] = $integration;
		}

		/**
		 * Filters the lead integrations: add your own Integration subclasses or remove
		 * built-in ones.
		 *
		 * @param Integration[] $list Integrations.
		 */
		$list   = (array) apply_filters( 'wp_cortex_lead_integrations', $list );
		$result = array();

		foreach ( $list as $integration ) {
			if ( ! $integration instanceof Integration ) {
				_doing_it_wrong( __METHOD__, esc_html__( 'Lead integrations must extend WPCortex\Integrations\Integration.', 'wp-cortex' ), '0.12.0' );
				continue;
			}

			$id = $integration->id();

			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,40}$/', $id ) ) {
				_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Invalid lead integration ID "%s".', $id ) ), '0.12.0' );
				continue;
			}

			$result[ $id ] = $integration;
		}

		self::$integrations = $result;

		return $result;
	}

	/**
	 * One integration.
	 *
	 * @param string $id Integration ID.
	 */
	public static function get( string $id ): ?Integration {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * Settings of an integration with the defaults: enabled (off), events (both) and the
	 * defaults of its fields.
	 *
	 * @param Integration $integration Integration.
	 * @return array<string, mixed>
	 */
	public static function settings( Integration $integration ): array {
		$stored   = (array) Settings::get( 'integrations' );
		$settings = is_array( $stored[ $integration->id() ] ?? null ) ? $stored[ $integration->id() ] : array();
		$defaults = array(
			'enabled' => false,
			'events'  => self::EVENTS,
		);

		foreach ( $integration->fields() as $key => $field ) {
			$defaults[ $key ] = $field['default'] ?? ( 'checkbox' === ( $field['type'] ?? '' ) ? false : '' );
		}

		return array_merge( $defaults, array_intersect_key( $settings, $defaults ) );
	}

	/**
	 * Integrations that are switched on and configured, with their settings, while leads
	 * are on.
	 *
	 * @return array<string, array{integration: Integration, settings: array}>
	 */
	public static function active(): array {
		if ( ! Settings::leads_enabled() ) {
			return array();
		}

		$active = array();

		foreach ( self::all() as $id => $integration ) {
			$settings = self::settings( $integration );

			if ( $settings['enabled'] && $integration->is_configured( $settings ) ) {
				$active[ $id ] = array(
					'integration' => $integration,
					'settings'    => $settings,
				);
			}
		}

		return $active;
	}

	/**
	 * Sanitizes the `integrations` setting. Integrations shown in the form (`shown`) take
	 * the submitted values; a secret left empty keeps the stored one. Settings of
	 * integrations that are not registered right now (for example a theme integration
	 * while another theme is active) are kept. Without input the stored value stays.
	 *
	 * @param mixed $input  Raw input.
	 * @param array $stored Stored value.
	 * @return array<string, array<string, mixed>>
	 */
	public static function sanitize( $input, array $stored ): array {
		$result = array();

		foreach ( $stored as $id => $settings ) {
			if ( is_string( $id ) && 1 === preg_match( '/^[a-z0-9_-]{1,40}$/', $id ) && is_array( $settings ) ) {
				$result[ $id ] = $settings;
			}
		}

		if ( ! is_array( $input ) ) {
			return $result;
		}

		foreach ( self::all() as $id => $integration ) {
			if ( ! is_array( $input[ $id ] ?? null ) || empty( $input[ $id ]['shown'] ) ) {
				continue;
			}

			$settings = self::sanitize_one( $integration, $input[ $id ], (array) ( $result[ $id ] ?? array() ) );
			$valid    = $settings['enabled'] ? $integration->validate( $settings ) : true;

			// An integration that cannot work stays off, with the reason on the settings screen.
			if ( is_wp_error( $valid ) ) {
				$settings['enabled'] = false;

				if ( function_exists( 'add_settings_error' ) ) {
					/* translators: 1: integration name, 2: reason. */
					add_settings_error( Settings::OPTION, 'wp_cortex_integration_' . $id, sprintf( __( '%1$s was not switched on. %2$s', 'wp-cortex' ), $integration->label(), $valid->get_error_message() ) );
				}
			}

			$result[ $id ] = $settings;
		}

		return $result;
	}

	/**
	 * Sanitizes the settings of one integration.
	 *
	 * @param Integration $integration Integration.
	 * @param array       $input       Raw input.
	 * @param array       $stored      Stored settings (for secrets left empty).
	 * @return array<string, mixed>
	 */
	public static function sanitize_one( Integration $integration, array $input, array $stored ): array {
		$settings = array(
			'enabled' => ! empty( $input['enabled'] ),
			'events'  => array_values( array_intersect( self::EVENTS, (array) ( $input['events'] ?? array() ) ) ),
		);

		foreach ( $integration->fields() as $key => $field ) {
			$type  = (string) ( $field['type'] ?? 'text' );
			$value = $input[ $key ] ?? '';

			switch ( $type ) {
				case 'checkbox':
					$settings[ $key ] = ! empty( $value );
					break;
				case 'url':
					$settings[ $key ] = esc_url_raw( trim( (string) $value ), array( 'https', 'http' ) );
					break;
				case 'textarea':
					$settings[ $key ] = mb_substr( trim( sanitize_textarea_field( (string) $value ) ), 0, self::MAX_TEXT );
					break;
				case 'select':
					$options          = array_map( 'strval', array_keys( (array) ( $field['options'] ?? array() ) ) );
					$settings[ $key ] = in_array( (string) $value, $options, true ) ? (string) $value : (string) ( $field['default'] ?? ( $options[0] ?? '' ) );
					break;
				case 'secret':
					$value            = trim( (string) $value );
					$settings[ $key ] = '' === $value ? (string) ( $stored[ $key ] ?? '' ) : mb_substr( $value, 0, self::MAX_TEXT );
					break;
				default:
					$settings[ $key ] = mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, self::MAX_TEXT );
			}
		}

		return $settings;
	}

	/**
	 * Integrations returned by the theme's files; child theme files replace parent theme
	 * files with the same name.
	 *
	 * @return Integration[]
	 */
	private static function theme_integrations(): array {
		$found       = array();
		$directories = array_unique( array( get_template_directory(), get_stylesheet_directory() ) );
		$load        = static function ( string $file ) {
			return include $file;
		};

		foreach ( $directories as $directory ) {
			$files = glob( trailingslashit( $directory ) . self::THEME_DIRECTORY . '/*.php' );

			foreach ( $files ? $files : array() as $file ) {
				$name = basename( $file, '.php' );

				if ( ! str_starts_with( $name, '_' ) ) {
					$found[ $name ] = $load( $file );
				}
			}
		}

		return array_values( $found );
	}
}
