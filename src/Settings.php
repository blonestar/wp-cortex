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
	 * Default language of chat skills and the maximum length of the setting, in characters.
	 */
	public const SKILLS_LANGUAGE_DEFAULT = 'English';
	public const SKILLS_LANGUAGE_MAX     = 60;

	/**
	 * Visitor chat (and summary) provider value that uses whatever provider the admin chat would pick.
	 * An empty visitor chat or summary provider means "same provider, model and reasoning as the admin chat".
	 */
	public const PUBLIC_CHAT_PROVIDER_AUTO = 'auto';

	/**
	 * Languages of the visitor chat summary (the first is the default): the site language or the visitor's.
	 */
	public const SUMMARY_LANGUAGES = array( 'site', 'visitor' );

	/**
	 * Maximum length of the visitor chat title and welcome message, in characters.
	 */
	public const PUBLIC_CHAT_TITLE_MAX   = 60;
	public const PUBLIC_CHAT_WELCOME_MAX = 500;

	/**
	 * Maximum length of the visitor chat launcher label, in characters.
	 */
	public const PUBLIC_CHAT_LABEL_MAX = 30;

	/**
	 * Maximum length of the visitor chat message box placeholder, in characters.
	 */
	public const PUBLIC_CHAT_PLACEHOLDER_MAX = 80;

	/**
	 * Visitor chat appearance choices: setting key => allowed values (the first is the default).
	 */
	public const PUBLIC_CHAT_CHOICES = array(
		'public_chat_scheme'   => array( 'light', 'dark', 'auto' ),
		'public_chat_position' => array( 'right', 'left' ),
		'public_chat_font'     => array( 'system', 'theme' ),
	);

	/**
	 * Visitor chat sizes in pixels: setting key => array( min, max ).
	 */
	public const PUBLIC_CHAT_SIZES = array(
		'public_chat_radius'        => array( 0, 28 ),
		'public_chat_width'         => array( 300, 600 ),
		'public_chat_height'        => array( 400, 800 ),
		'public_chat_launcher_size' => array( 40, 80 ),
		'public_chat_offset'        => array( 0, 80 ),
		'public_chat_font_size'     => array( 12, 18 ),
	);

	/**
	 * Highest per-message call limit of a chat tool (0 means no limit).
	 */
	public const TOOL_LIMIT_MAX = 20;

	/**
	 * Chats whose tools can be switched off and limited under Settings > Chat tools.
	 */
	public const TOOL_SCOPES = array( 'admin', 'public' );

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
			'admin_post_types'           => array( 'post', 'page' ),
			'public_post_types'          => array( 'post', 'page' ),
			'admin_statuses'             => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'auto_sync'                  => true,
			'index_yoast'                => true,
			'index_acf'                  => true,
			'meta_keys'                  => array(),
			'field_scopes'               => array(),
			'chunk_size'                 => 1200,
			'chunk_overlap'              => 150,
			'batch_size'                 => 10,
			'uninstall_delete_index'     => true,
			'embeddings_enabled'         => true,
			'embedding_model'            => 'text-embedding-3-small',
			'embedding_dimensions'       => 1536,
			'chat_enabled'               => true,
			'chat_provider'              => '',
			'chat_model'                 => '',
			'chat_instructions'          => '',
			'chat_reasoning'             => '',
			'chat_frontend'              => false,
			'skills_enabled'             => true,
			'skills_language'            => self::SKILLS_LANGUAGE_DEFAULT,
			'skills_instructions'        => '',
			'chat_tools'                 => array(
				'admin'  => array(
					'off'    => array(),
					'limits' => array(),
				),
				'public' => array(
					'off'    => array(),
					'limits' => array(),
				),
			),
			'chat_abilities'             => array(),
			'public_chat_enabled'        => false,
			'public_chat_title'          => '',
			'public_chat_welcome'        => '',
			'public_chat_placeholder'    => '',
			'public_chat_instructions'   => '',
			'public_chat_provider'       => '',
			'public_chat_model'          => '',
			'public_chat_reasoning'      => '',
			'public_chat_rate_limit'     => 20,
			'public_chat_images'         => false,
			'public_chat_attach_icon'    => false,
			'public_chat_image_limit'    => 10,
			'public_chat_log'            => true,
			'public_chat_retention'      => 0,
			'public_chat_navigation'     => true,
			'public_chat_contact'        => true,
			'public_chat_reports'        => true,
			'public_chat_report_email'   => '',
			'public_chat_store_ip'       => true,
			'public_chat_ip_header'      => '',
			'public_chat_accent'         => '#2271b1',
			'public_chat_scheme'         => 'light',
			'public_chat_position'       => 'right',
			'public_chat_launcher_label' => '',
			'public_chat_font'           => 'system',
			'public_chat_radius'         => 14,
			'public_chat_width'          => 380,
			'public_chat_height'         => 560,
			'public_chat_launcher_size'  => 56,
			'public_chat_offset'         => 20,
			'public_chat_font_size'      => 14,
			'summary_provider'           => '',
			'summary_model'              => '',
			'summary_reasoning'          => '',
			'summary_language'           => 'site',
			'summary_instructions'       => '',
			'leads_enabled'              => true,
			'leads_attribution'          => false,
			'leads_attribution_days'     => 90,
			'leads_extra_params'         => array(),
			'leads_consent'              => 'auto',
			'leads_onetrust_group'       => Leads\Attribution::ONETRUST_GROUP,
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), self::upgrade( is_array( $stored ) ? $stored : array() ) );
	}

	/**
	 * Maps settings saved before the admin and public indexes were configured separately:
	 * "post_types" and "index_media" become both post type lists. "acf_public" is dropped,
	 * so ACF fields go back to the admin index only until they are chosen for the public
	 * index one by one. Saving the settings drops the old keys.
	 *
	 * @param array<string, mixed> $stored Stored settings.
	 * @return array<string, mixed>
	 */
	private static function upgrade( array $stored ): array {
		if ( isset( $stored['post_types'] ) && ! isset( $stored['admin_post_types'] ) ) {
			$types = (array) $stored['post_types'];
			if ( ! empty( $stored['index_media'] ) ) {
				$types[] = 'attachment';
			}

			$stored['admin_post_types']  = $types;
			$stored['public_post_types'] = $types;
		}

		unset( $stored['post_types'], $stored['index_media'], $stored['acf_public'] );

		return $stored;
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
	 * Post types selected for an index that still exist ("attachment" is media). The
	 * public index only keeps the viewable ones.
	 *
	 * @param string $scope Storage\Storage::SCOPE_ADMIN, SCOPE_PUBLIC, or empty for both indexes.
	 * @return string[]
	 */
	public static function post_types( string $scope = '' ): array {
		if ( '' === $scope ) {
			return array_values( array_unique( array_merge( self::post_types( Storage\Storage::SCOPE_ADMIN ), self::post_types( Storage\Storage::SCOPE_PUBLIC ) ) ) );
		}

		$types = array_filter( (array) self::get( $scope . '_post_types' ), 'post_type_exists' );
		if ( Storage\Storage::SCOPE_PUBLIC === $scope ) {
			$types = array_filter( $types, 'is_post_type_viewable' );
		}

		return array_values( array_unique( array_map( 'strval', $types ) ) );
	}

	/**
	 * Whether media (attachments) go into at least one index.
	 */
	public static function index_media(): bool {
		return in_array( 'attachment', self::post_types(), true );
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
	 * Whether chat skills are on: the Skills screen, the skills REST routes and the
	 * use_skill and propose_skill tools of the admin chat.
	 */
	public static function skills_enabled(): bool {
		return (bool) self::get( 'skills_enabled' );
	}

	/**
	 * Whether leads are on: the Leads screen, the leads REST routes, the lead status and
	 * AI rating of visitor conversations and, while `leads_attribution` is on, attribution.
	 */
	public static function leads_enabled(): bool {
		return (bool) self::get( 'leads_enabled' );
	}

	/**
	 * Language the admin chat writes skills in.
	 */
	public static function skills_language(): string {
		$language = trim( (string) self::get( 'skills_language' ) );

		return '' !== $language ? $language : self::SKILLS_LANGUAGE_DEFAULT;
	}

	/**
	 * Whether a chat tool is switched on under Settings > Chat tools (tools are on
	 * unless switched off). Its own setting, for example public_chat_reports, may still
	 * keep it from being offered.
	 *
	 * @param string $scope Chat: "admin" or "public".
	 * @param string $name  Function name of the tool.
	 */
	public static function tool_enabled( string $scope, string $name ): bool {
		$tools = self::get( 'chat_tools' );

		return ! in_array( $name, (array) ( $tools[ $scope ]['off'] ?? array() ), true );
	}

	/**
	 * How many times a chat tool may be called per message, 0 for no limit.
	 *
	 * @param string $scope Chat: "admin" or "public".
	 * @param string $name  Function name of the tool.
	 */
	public static function tool_limit( string $scope, string $name ): int {
		$tools = self::get( 'chat_tools' );

		return (int) ( $tools[ $scope ]['limits'][ $name ] ?? 0 );
	}

	/**
	 * Registered abilities of other plugins (and WordPress) the admin chat may use. The
	 * plugin's own abilities are built-in admin chat tools and are not listed here.
	 *
	 * @return string[] Ability names.
	 */
	public static function chat_abilities(): array {
		return array_values( array_map( 'strval', (array) self::get( 'chat_abilities' ) ) );
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

		$admin_types  = array_map( 'sanitize_key', (array) ( $input['admin_post_types'] ?? array() ) );
		$public_types = array_map( 'sanitize_key', (array) ( $input['public_post_types'] ?? array() ) );
		$statuses     = array_map( 'sanitize_key', (array) ( $input['admin_statuses'] ?? array() ) );

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

		$skills_language     = trim( sanitize_text_field( (string) ( $input['skills_language'] ?? '' ) ) );
		$skills_instructions = trim( sanitize_textarea_field( (string) ( $input['skills_instructions'] ?? '' ) ) );

		$public_instructions = trim( sanitize_textarea_field( (string) ( $input['public_chat_instructions'] ?? '' ) ) );
		$public_welcome      = trim( sanitize_textarea_field( (string) ( $input['public_chat_welcome'] ?? '' ) ) );
		$public_title        = trim( sanitize_text_field( (string) ( $input['public_chat_title'] ?? '' ) ) );
		$public_placeholder  = trim( sanitize_text_field( (string) ( $input['public_chat_placeholder'] ?? '' ) ) );

		$public_provider = sanitize_key( (string) ( $input['public_chat_provider'] ?? '' ) );
		$public_custom   = '' !== $public_provider && self::PUBLIC_CHAT_PROVIDER_AUTO !== $public_provider;

		$summary_provider     = sanitize_key( (string) ( $input['summary_provider'] ?? '' ) );
		$summary_custom       = '' !== $summary_provider && self::PUBLIC_CHAT_PROVIDER_AUTO !== $summary_provider;
		$summary_language     = (string) ( $input['summary_language'] ?? '' );
		$summary_instructions = trim( sanitize_textarea_field( (string) ( $input['summary_instructions'] ?? '' ) ) );

		$chunk_size = self::clamp( $input['chunk_size'] ?? $defaults['chunk_size'], 300, 6000 );

		$accent     = sanitize_hex_color( (string) ( $input['public_chat_accent'] ?? '' ) );
		$appearance = array(
			'public_chat_accent'         => $accent ? $accent : $defaults['public_chat_accent'],
			'public_chat_launcher_label' => mb_substr( trim( sanitize_text_field( (string) ( $input['public_chat_launcher_label'] ?? '' ) ) ), 0, self::PUBLIC_CHAT_LABEL_MAX ),
		);
		foreach ( self::PUBLIC_CHAT_CHOICES as $key => $choices ) {
			$value              = (string) ( $input[ $key ] ?? '' );
			$appearance[ $key ] = in_array( $value, $choices, true ) ? $value : $choices[0];
		}
		foreach ( self::PUBLIC_CHAT_SIZES as $key => $range ) {
			$appearance[ $key ] = self::clamp( $input[ $key ] ?? $defaults[ $key ], $range[0], $range[1] );
		}

		$stored = get_option( self::OPTION, array() );
		$stored = array_merge( $defaults, self::upgrade( is_array( $stored ) ? $stored : array() ) );

		return $appearance + array(
			'admin_post_types'         => array_values( array_unique( array_filter( $admin_types, 'post_type_exists' ) ) ),
			'public_post_types'        => array_values( array_unique( array_filter( $public_types, 'post_type_exists' ) ) ),
			'admin_statuses'           => array_values( array_intersect( $statuses, self::ADMIN_STATUSES ) ),
			'auto_sync'                => ! empty( $input['auto_sync'] ),
			'index_yoast'              => ! empty( $input['index_yoast'] ),
			'index_acf'                => ! empty( $input['index_acf'] ),
			'meta_keys'                => $meta_keys,
			'field_scopes'             => self::sanitize_field_scopes( $input['field_scopes'] ?? array() ),
			'chunk_size'               => $chunk_size,
			'chunk_overlap'            => self::clamp( $input['chunk_overlap'] ?? $defaults['chunk_overlap'], 0, (int) floor( $chunk_size / 2 ) ),
			'batch_size'               => self::clamp( $input['batch_size'] ?? $defaults['batch_size'], 1, 100 ),
			'uninstall_delete_index'   => ! empty( $input['uninstall_delete_index'] ),
			'embeddings_enabled'       => ! empty( $input['embeddings_enabled'] ),
			'embedding_model'          => $model,
			'embedding_dimensions'     => $dimensions,
			'chat_enabled'             => ! empty( $input['chat_enabled'] ),
			'chat_provider'            => sanitize_key( (string) ( $input['chat_provider'] ?? '' ) ),
			'chat_model'               => self::sanitize_model( $input['chat_model'] ?? '' ),
			'chat_instructions'        => $chat_instructions,
			'chat_reasoning'           => sanitize_key( (string) ( $input['chat_reasoning'] ?? '' ) ),
			'chat_frontend'            => ! empty( $input['chat_frontend'] ),
			'skills_enabled'           => ! empty( $input['skills_enabled'] ),
			'skills_language'          => '' !== $skills_language ? mb_substr( $skills_language, 0, self::SKILLS_LANGUAGE_MAX ) : self::SKILLS_LANGUAGE_DEFAULT,
			'skills_instructions'      => mb_substr( $skills_instructions, 0, self::CHAT_INSTRUCTIONS_MAX ),
			'chat_tools'               => self::sanitize_chat_tools( $input['chat_tools'] ?? null, (array) $stored['chat_tools'] ),
			'chat_abilities'           => self::sanitize_chat_abilities( $input['chat_abilities'] ?? null, (array) $stored['chat_abilities'] ),
			'public_chat_enabled'      => ! empty( $input['public_chat_enabled'] ),
			'public_chat_title'        => mb_substr( $public_title, 0, self::PUBLIC_CHAT_TITLE_MAX ),
			'public_chat_welcome'      => mb_substr( $public_welcome, 0, self::PUBLIC_CHAT_WELCOME_MAX ),
			'public_chat_placeholder'  => mb_substr( $public_placeholder, 0, self::PUBLIC_CHAT_PLACEHOLDER_MAX ),
			'public_chat_instructions' => mb_substr( $public_instructions, 0, self::CHAT_INSTRUCTIONS_MAX ),
			'public_chat_provider'     => $public_provider,
			'public_chat_model'        => $public_custom ? self::sanitize_model( $input['public_chat_model'] ?? '' ) : '',
			'public_chat_reasoning'    => $public_custom ? sanitize_key( (string) ( $input['public_chat_reasoning'] ?? '' ) ) : '',
			'public_chat_rate_limit'   => self::clamp( $input['public_chat_rate_limit'] ?? $defaults['public_chat_rate_limit'], 1, 1000 ),
			'public_chat_images'       => ! empty( $input['public_chat_images'] ),
			'public_chat_attach_icon'  => ! empty( $input['public_chat_attach_icon'] ),
			'public_chat_image_limit'  => self::clamp( $input['public_chat_image_limit'] ?? $defaults['public_chat_image_limit'], 1, 1000 ),
			'public_chat_log'          => ! empty( $input['public_chat_log'] ),
			'public_chat_retention'    => self::clamp( $input['public_chat_retention'] ?? $defaults['public_chat_retention'], 0, 3650 ),
			'public_chat_navigation'   => ! empty( $input['public_chat_navigation'] ),
			'public_chat_contact'      => ! empty( $input['public_chat_contact'] ),
			'public_chat_reports'      => ! empty( $input['public_chat_reports'] ),
			'public_chat_report_email' => self::sanitize_emails( $input['public_chat_report_email'] ?? '' ),
			'public_chat_store_ip'     => ! empty( $input['public_chat_store_ip'] ),
			'public_chat_ip_header'    => isset( Chat\ClientIp::HEADERS[ $input['public_chat_ip_header'] ?? '' ] ) ? (string) $input['public_chat_ip_header'] : '',
			'summary_provider'         => $summary_provider,
			'summary_model'            => $summary_custom ? self::sanitize_model( $input['summary_model'] ?? '' ) : '',
			'summary_reasoning'        => $summary_custom ? sanitize_key( (string) ( $input['summary_reasoning'] ?? '' ) ) : '',
			'summary_language'         => in_array( $summary_language, self::SUMMARY_LANGUAGES, true ) ? $summary_language : self::SUMMARY_LANGUAGES[0],
			'summary_instructions'     => mb_substr( $summary_instructions, 0, self::CHAT_INSTRUCTIONS_MAX ),
			'leads_enabled'            => ! empty( $input['leads_enabled'] ),
			'leads_attribution'        => ! empty( $input['leads_attribution'] ),
			'leads_attribution_days'   => self::clamp( $input['leads_attribution_days'] ?? $defaults['leads_attribution_days'], 0, 730 ),
			'leads_extra_params'       => Leads\Attribution::sanitize_param_names( $input['leads_extra_params'] ?? array() ),
			'leads_consent'            => in_array( $input['leads_consent'] ?? '', Leads\Attribution::CONSENT_MODES, true ) ? (string) $input['leads_consent'] : 'auto',
			'leads_onetrust_group'     => Leads\Attribution::sanitize_onetrust_group( $input['leads_onetrust_group'] ?? '' ),
		);
	}

	/**
	 * Field rules from the Admin index and Public index sections. Each section sends the
	 * keys it shows (`known[<scope>]`) and the checked ones (`<scope>`). Only choices that
	 * differ from what the field would get anyway (the extractor's default or a wildcard
	 * rule) are stored, so better defaults still reach fields nobody changed. Rules of
	 * fields that are not shown (for example while their data source is off) are kept.
	 * Already sanitized rules (field key => value) are kept as they are.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, string> Field key => FieldPolicy value.
	 */
	private static function sanitize_field_scopes( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$rules = array();

		if ( ! isset( $input['known'] ) ) {
			foreach ( $input as $key => $value ) {
				$key = self::sanitize_field_key( $key );
				if ( '' !== $key && in_array( $value, Indexing\FieldPolicy::VALUES, true ) ) {
					$rules[ $key ] = $value;
				}
			}

			return $rules;
		}

		$stored = get_option( self::OPTION, array() );
		$stored = self::upgrade( is_array( $stored ) ? $stored : array() );
		foreach ( (array) ( $stored['field_scopes'] ?? array() ) as $key => $value ) {
			if ( in_array( $value, Indexing\FieldPolicy::VALUES, true ) ) {
				$rules[ (string) $key ] = $value;
			}
		}

		$catalog = array();
		foreach ( Indexing\FieldPolicy::catalog() as $fields ) {
			$catalog += $fields;
		}

		$known   = array();
		$checked = array();
		foreach ( Storage\Storage::SCOPES as $scope ) {
			$known[ $scope ]   = array_map( array( self::class, 'sanitize_field_key' ), (array) ( $input['known'][ $scope ] ?? array() ) );
			$checked[ $scope ] = array_map( array( self::class, 'sanitize_field_key' ), (array) ( $input[ $scope ] ?? array() ) );
		}

		$keys = array_values( array_intersect( array_unique( array_merge( ...array_values( $known ) ) ), array_keys( $catalog ) ) );

		// Wildcards first: the other fields are compared with what the wildcards give them.
		usort( $keys, static fn( $a, $b ) => ( str_ends_with( $b, '*' ) <=> str_ends_with( $a, '*' ) ) ?: strcmp( $a, $b ) );

		foreach ( $keys as $key ) {
			$default = (bool) $catalog[ $key ]['public'];
			$current = new Indexing\FieldPolicy( $rules );
			$without = $rules;
			unset( $without[ $key ] );
			$inherited = new Indexing\FieldPolicy( $without );

			$want = array();
			$same = true;
			foreach ( Storage\Storage::SCOPES as $scope ) {
				$want[ $scope ] = in_array( $key, $known[ $scope ], true )
					? in_array( $key, $checked[ $scope ], true )
					: $current->allows( $scope, $key, $default );
				$same           = $same && $want[ $scope ] === $inherited->allows( $scope, $key, $default );
			}

			if ( $same ) {
				unset( $rules[ $key ] );
				continue;
			}

			$in_admin      = $want[ Storage\Storage::SCOPE_ADMIN ];
			$in_public     = $want[ Storage\Storage::SCOPE_PUBLIC ];
			$rules[ $key ] = $in_admin ? ( $in_public ? 'both' : 'admin' ) : ( $in_public ? 'public' : 'none' );
		}

		ksort( $rules );

		return $rules;
	}

	/**
	 * Tool switches and limits from Settings > Chat tools. Each chat sends the tools it
	 * shows (`known`), the switched-on ones (`on`), the limits (`limit`) and the tools
	 * that only have a limit there (`limit_known`, the abilities of other plugins); choices for
	 * tools that are not shown (for example a theme tool while another theme is active)
	 * are kept. Already sanitized values (`off`, `limits`) are kept as they are; without
	 * input the stored value stays.
	 *
	 * @param mixed $input  Raw input.
	 * @param array $stored Stored value.
	 * @return array<string, array{off: string[], limits: array<string, int>}>
	 */
	private static function sanitize_chat_tools( $input, array $stored ): array {
		$result = array();

		foreach ( self::TOOL_SCOPES as $scope ) {
			$old    = is_array( $stored[ $scope ] ?? null ) ? $stored[ $scope ] : array();
			$off    = self::tool_names( $old['off'] ?? array() );
			$limits = self::tool_limits( $old['limits'] ?? array() );
			$value  = is_array( $input ) && is_array( $input[ $scope ] ?? null ) ? $input[ $scope ] : null;

			if ( null !== $value && isset( $value['known'] ) ) {
				$known  = self::tool_names( $value['known'] );
				$on     = self::tool_names( $value['on'] ?? array() );
				$shown  = array_flip( array_merge( $known, self::tool_names( $value['limit_known'] ?? array() ) ) );
				$off    = array_merge( array_diff( $off, $known ), array_diff( $known, $on ) );
				$limits = array_merge(
					array_diff_key( $limits, $shown ),
					array_intersect_key( self::tool_limits( $value['limit'] ?? array() ), $shown )
				);
			} elseif ( null !== $value ) {
				$off    = self::tool_names( $value['off'] ?? array() );
				$limits = self::tool_limits( $value['limits'] ?? array() );
			}

			$off = array_values( array_unique( $off ) );
			sort( $off );
			ksort( $limits );

			$result[ $scope ] = array(
				'off'    => $off,
				'limits' => $limits,
			);
		}

		return $result;
	}

	/**
	 * Abilities the admin chat may use, from Settings > Chat tools (`known` and `on`, keeping
	 * the choices of abilities that are not registered right now), or an already sanitized
	 * list; without input the stored list stays.
	 *
	 * @param mixed $input  Raw input.
	 * @param array $stored Stored list.
	 * @return string[]
	 */
	private static function sanitize_chat_abilities( $input, array $stored ): array {
		$stored = self::ability_names( $stored );

		if ( ! is_array( $input ) ) {
			$list = $stored;
		} elseif ( isset( $input['known'] ) ) {
			$known = self::ability_names( (array) $input['known'] );
			$list  = array_merge( array_diff( $stored, $known ), array_intersect( self::ability_names( (array) ( $input['on'] ?? array() ) ), $known ) );
		} else {
			$list = self::ability_names( $input );
		}

		$list = array_values( array_unique( $list ) );
		sort( $list );

		return $list;
	}

	/**
	 * Valid tool function names from a list.
	 *
	 * @param mixed $names Raw names.
	 * @return string[]
	 */
	private static function tool_names( $names ): array {
		return array_values( array_filter( array_map( 'strval', (array) $names ), static fn( string $name ) => (bool) preg_match( '/^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/', $name ) ) );
	}

	/**
	 * Per-message limits keyed by tool function name, without the "no limit" (0) entries.
	 *
	 * @param mixed $limits Raw limits.
	 * @return array<string, int>
	 */
	private static function tool_limits( $limits ): array {
		$clean = array();

		foreach ( (array) $limits as $name => $limit ) {
			$limit = self::clamp( $limit, 0, self::TOOL_LIMIT_MAX );

			if ( $limit > 0 && self::tool_names( array( (string) $name ) ) ) {
				$clean[ (string) $name ] = $limit;
			}
		}

		return $clean;
	}

	/**
	 * Valid ability names ("namespace/name") from a list, without the plugin's own abilities.
	 *
	 * @param array $names Raw names.
	 * @return string[]
	 */
	private static function ability_names( array $names ): array {
		return array_values(
			array_filter(
				array_map( 'strval', $names ),
				static fn( string $name ) => (bool) preg_match( '#^[a-z0-9-]+(/[a-z0-9-]+)+$#', $name ) && ! str_starts_with( $name, 'wp-cortex/' )
			)
		);
	}

	/**
	 * A field key ("source:name", the name may end in "*"), or an empty string when invalid.
	 *
	 * @param mixed $key Raw key.
	 */
	private static function sanitize_field_key( $key ): string {
		$key = mb_substr( trim( sanitize_text_field( (string) $key ) ), 0, 200 );

		return preg_match( '/^[a-z0-9_-]+:\S+$/', $key ) ? $key : '';
	}

	/**
	 * Valid, unique email addresses from a comma, semicolon or space separated list,
	 * joined with ", " (at most Chat\IssueReportMailer::MAX_RECIPIENTS).
	 *
	 * @param mixed $value Raw list.
	 */
	private static function sanitize_emails( $value ): string {
		$emails = array();

		foreach ( preg_split( '/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY ) as $address ) {
			if ( is_email( $address ) ) {
				$emails[ strtolower( sanitize_email( $address ) ) ] = sanitize_email( $address );
			}
		}

		return implode( ', ', array_slice( array_values( $emails ), 0, Chat\IssueReportMailer::MAX_RECIPIENTS ) );
	}

	/**
	 * Strips a model ID down to the characters provider model IDs use.
	 *
	 * @param mixed $value Raw model ID.
	 */
	private static function sanitize_model( $value ): string {
		return trim( (string) preg_replace( '/[^A-Za-z0-9._:~\/-]/', '', (string) $value ) );
	}

	/**
	 * Provider, model and reasoning level of a chat.
	 *
	 * The visitor chat and the visitor chat summary use their own choice, or the admin chat's
	 * when their provider is empty; "auto" lets the AI Client pick any configured provider
	 * with its default model.
	 *
	 * @param string $chat Which configuration: "admin", "public" (visitor chat) or "summary" (visitor chat summary).
	 * @return array{provider: string, model: string, reasoning: string}
	 */
	public static function chat_model_config( string $chat = 'admin' ): array {
		$all      = self::all();
		$prefixes = array(
			'public'  => 'public_chat_',
			'summary' => 'summary_',
		);
		$prefix   = $prefixes[ $chat ] ?? 'chat_';

		if ( 'chat_' !== $prefix && '' === (string) $all[ $prefix . 'provider' ] ) {
			$prefix = 'chat_';
		}

		if ( 'chat_' !== $prefix && self::PUBLIC_CHAT_PROVIDER_AUTO === $all[ $prefix . 'provider' ] ) {
			return array(
				'provider'  => '',
				'model'     => '',
				'reasoning' => '',
			);
		}

		return array(
			'provider'  => (string) $all[ $prefix . 'provider' ],
			'model'     => (string) $all[ $prefix . 'model' ],
			'reasoning' => (string) $all[ $prefix . 'reasoning' ],
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
