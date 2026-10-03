<?php
/**
 * Plugin settings storage.
 *
 * @package WPCortex
 */

namespace WPCortex;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the wp_cortex_settings option.
 */
final class Settings {

	public const OPTION = 'wp_cortex_settings';

	/**
	 * Post statuses that may go into the admin index. The public index only ever holds "publish".
	 */
	public const ADMIN_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Maximum length of the custom chat instructions, in characters.
	 */
	public const CHAT_INSTRUCTIONS_MAX = 4000;

	/**
	 * Maximum length of the visitor chat title and welcome message, in characters.
	 */
	public const PUBLIC_CHAT_TITLE_MAX   = 60;
	public const PUBLIC_CHAT_WELCOME_MAX = 500;

	public const EMBEDDING_MODELS = array(
		'text-embedding-3-small' => array( 512, 1024, 1536 ),
		'text-embedding-3-large' => array( 256, 1024, 3072 ),
	);

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'post_types'               => array( 'post', 'page' ),
			'admin_statuses'           => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'auto_sync'                => true,
			'index_yoast'              => true,
			'index_acf'                => true,
			'acf_public'               => false,
			'meta_keys'                => array(),
			'index_media'              => false,
			'chunk_size'               => 1200,
			'chunk_overlap'            => 150,
			'batch_size'               => 10,
			'embeddings_enabled'       => true,
			'embedding_model'          => 'text-embedding-3-small',
			'embedding_dimensions'     => 1536,
			'chat_enabled'             => true,
			'chat_provider'            => '',
			'chat_model'               => '',
			'chat_instructions'        => '',
			'chat_reasoning'           => '',
			'chat_frontend'            => false,
			'public_chat_enabled'      => false,
			'public_chat_title'        => '',
			'public_chat_welcome'      => '',
			'public_chat_instructions' => '',
			'public_chat_rate_limit'   => 20,
			'public_chat_log'          => true,
			'public_chat_retention'    => 0,
			'public_chat_navigation'   => true,
			'public_chat_contact'      => true,
			'public_chat_store_ip'     => true,
			'public_chat_ip_header'    => '',
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Post types selected for indexing that still exist, plus "attachment" when media
	 * indexing is on.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = array_filter( (array) self::get( 'post_types' ), 'post_type_exists' );

		if ( self::get( 'index_media' ) ) {
			$types[] = 'attachment';
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Statuses for the admin index; "publish" is always included.
	 *
	 * @return string[]
	 */
	public static function admin_statuses(): array {
		$statuses = array_intersect( (array) self::get( 'admin_statuses' ), self::ADMIN_STATUSES );

		return array_values( array_unique( array_merge( array( 'publish' ), $statuses ) ) );
	}

	/**
	 * Identifier of the embedding configuration, stored next to every vector so a
	 * model or dimension change marks existing vectors as stale.
	 */
	public static function embedding_signature(): string {
		return self::get( 'embedding_model' ) . ':' . (int) self::get( 'embedding_dimensions' );
	}

	/**
	 * Sanitizes the option on save.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		$post_types = array_map( 'sanitize_key', (array) ( $input['post_types'] ?? array() ) );
		$statuses   = array_map( 'sanitize_key', (array) ( $input['admin_statuses'] ?? array() ) );

		$meta_keys = $input['meta_keys'] ?? array();
		if ( is_string( $meta_keys ) ) {
			$meta_keys = preg_split( '/[\r\n,]+/', $meta_keys );
		}
		$meta_keys = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $meta_keys ) ) ) );

		$model = (string) ( $input['embedding_model'] ?? $defaults['embedding_model'] );
		if ( ! isset( self::EMBEDDING_MODELS[ $model ] ) ) {
			$model = $defaults['embedding_model'];
		}

		$dimensions = (int) ( $input['embedding_dimensions'] ?? 0 );
		if ( ! in_array( $dimensions, self::EMBEDDING_MODELS[ $model ], true ) ) {
			$dimensions = max( self::EMBEDDING_MODELS[ $model ] );
		}

		$chat_instructions = trim( sanitize_textarea_field( (string) ( $input['chat_instructions'] ?? '' ) ) );
		$chat_instructions = mb_substr( $chat_instructions, 0, self::CHAT_INSTRUCTIONS_MAX );

		$public_instructions = trim( sanitize_textarea_field( (string) ( $input['public_chat_instructions'] ?? '' ) ) );
		$public_welcome      = trim( sanitize_textarea_field( (string) ( $input['public_chat_welcome'] ?? '' ) ) );
		$public_title        = trim( sanitize_text_field( (string) ( $input['public_chat_title'] ?? '' ) ) );

		$chunk_size = self::clamp( $input['chunk_size'] ?? $defaults['chunk_size'], 300, 6000 );

		return array(
			'post_types'               => array_values( array_filter( $post_types, 'post_type_exists' ) ),
			'admin_statuses'           => array_values( array_intersect( $statuses, self::ADMIN_STATUSES ) ),
			'auto_sync'                => ! empty( $input['auto_sync'] ),
			'index_yoast'              => ! empty( $input['index_yoast'] ),
			'index_acf'                => ! empty( $input['index_acf'] ),
			'acf_public'               => ! empty( $input['acf_public'] ),
			'meta_keys'                => $meta_keys,
			'index_media'              => ! empty( $input['index_media'] ),
			'chunk_size'               => $chunk_size,
			'chunk_overlap'            => self::clamp( $input['chunk_overlap'] ?? $defaults['chunk_overlap'], 0, (int) floor( $chunk_size / 2 ) ),
			'batch_size'               => self::clamp( $input['batch_size'] ?? $defaults['batch_size'], 1, 100 ),
			'embeddings_enabled'       => ! empty( $input['embeddings_enabled'] ),
			'embedding_model'          => $model,
			'embedding_dimensions'     => $dimensions,
			'chat_enabled'             => ! empty( $input['chat_enabled'] ),
			'chat_provider'            => sanitize_key( (string) ( $input['chat_provider'] ?? '' ) ),
			'chat_model'               => trim( preg_replace( '/[^A-Za-z0-9._:~\/-]/', '', (string) ( $input['chat_model'] ?? '' ) ) ),
			'chat_instructions'        => $chat_instructions,
			'chat_reasoning'           => sanitize_key( (string) ( $input['chat_reasoning'] ?? '' ) ),
			'chat_frontend'            => ! empty( $input['chat_frontend'] ),
			'public_chat_enabled'      => ! empty( $input['public_chat_enabled'] ),
			'public_chat_title'        => mb_substr( $public_title, 0, self::PUBLIC_CHAT_TITLE_MAX ),
			'public_chat_welcome'      => mb_substr( $public_welcome, 0, self::PUBLIC_CHAT_WELCOME_MAX ),
			'public_chat_instructions' => mb_substr( $public_instructions, 0, self::CHAT_INSTRUCTIONS_MAX ),
			'public_chat_rate_limit'   => self::clamp( $input['public_chat_rate_limit'] ?? $defaults['public_chat_rate_limit'], 1, 1000 ),
			'public_chat_log'          => ! empty( $input['public_chat_log'] ),
			'public_chat_retention'    => self::clamp( $input['public_chat_retention'] ?? $defaults['public_chat_retention'], 0, 3650 ),
			'public_chat_navigation'   => ! empty( $input['public_chat_navigation'] ),
			'public_chat_contact'      => ! empty( $input['public_chat_contact'] ),
			'public_chat_store_ip'     => ! empty( $input['public_chat_store_ip'] ),
			'public_chat_ip_header'    => isset( Chat\ClientIp::HEADERS[ $input['public_chat_ip_header'] ?? '' ] ) ? (string) $input['public_chat_ip_header'] : '',
		);
	}

	/**
	 * Clamps a value to an integer range.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 */
	private static function clamp( $value, int $min, int $max ): int {
		return max( $min, min( $max, (int) $value ) );
	}
}
