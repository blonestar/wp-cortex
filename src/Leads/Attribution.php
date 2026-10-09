<?php
/**
 * Marketing attribution of visitor conversations.
 *
 * @package WPCortex
 */

namespace WPCortex\Leads;

use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans the attribution the visitor chat widget sends with a message and classifies
 * where the visitor came from.
 *
 * The widget records the visit that brought the visitor (the "touch": landing page,
 * referring site, campaign parameters and ad click IDs) when the visit starts, keeps the
 * first one in the browser (when allowed) and the pages of the current visit, and sends
 * them with each chat message. Nothing is trusted: only known keys are kept, URLs are
 * reduced to their host and path (query strings can hold personal data), lengths are
 * capped and the channel is computed here, never taken from the browser. Device and
 * country come from the request headers.
 */
final class Attribution {

	/**
	 * Channels, in the order reports list them.
	 */
	public const CHANNELS = array( 'paid_search', 'paid_social', 'display', 'organic_search', 'organic_social', 'ai', 'email', 'affiliate', 'referral', 'other', 'direct' );

	/**
	 * Campaign parameters (Google Analytics names) and their maximum lengths.
	 */
	public const UTM_PARAMS = array(
		'utm_source'          => 100,
		'utm_medium'          => 100,
		'utm_campaign'        => 150,
		'utm_term'            => 150,
		'utm_content'         => 150,
		'utm_id'              => 100,
		'utm_source_platform' => 100,
	);

	/**
	 * Ad click IDs and the platform that adds them.
	 */
	public const CLICK_IDS = array(
		'gclid'     => 'Google Ads',
		'gbraid'    => 'Google Ads',
		'wbraid'    => 'Google Ads',
		'msclkid'   => 'Microsoft Ads',
		'fbclid'    => 'Meta',
		'li_fat_id' => 'LinkedIn Ads',
		'ttclid'    => 'TikTok Ads',
		'twclid'    => 'X Ads',
	);

	/**
	 * Consent settings (leads_consent): with a consent manager on the page, record only after
	 * marketing consent and, without one, record ("auto") or not at all ("require");
	 * "ignore" records whatever the visitor chose.
	 */
	public const CONSENT_MODES = array( 'auto', 'require', 'ignore' );

	/**
	 * Consent managers the widget recognizes, as it reports them.
	 */
	public const CONSENT_SOURCES = array( 'osano', 'onetrust', 'cookiebot', 'wp_consent_api', 'none' );

	/**
	 * Default OneTrust category of marketing cookies ("Targeting cookies").
	 */
	public const ONETRUST_GROUP = 'C0004';

	/**
	 * Most custom parameters (setting leads_extra_params).
	 */
	public const MAX_EXTRA_PARAMS = 10;

	/**
	 * Most pages of the visit kept in the journey.
	 */
	public const MAX_PAGES = 25;

	/**
	 * Largest attribution payload accepted, in bytes of JSON.
	 */
	public const MAX_PAYLOAD = 20000;

	/**
	 * Referring hosts by kind. A host matches an entry when it is the entry or a
	 * subdomain of it; entries ending with a dot match any top-level domain.
	 */
	private const AI_HOSTS = array( 'chatgpt.com', 'chat.openai.com', 'openai.com', 'perplexity.ai', 'gemini.google.com', 'bard.google.com', 'copilot.microsoft.com', 'claude.ai', 'you.com', 'phind.com', 'poe.com', 'chat.deepseek.com', 'deepseek.com', 'chat.mistral.ai', 'meta.ai', 'grok.com', 'kagi.com' );

	private const SEARCH_HOSTS = array( 'google.', 'bing.com', 'yahoo.', 'duckduckgo.com', 'yandex.', 'baidu.com', 'ecosia.org', 'search.brave.com', 'startpage.com', 'qwant.com', 'naver.com', 'seznam.cz', 'ask.com', 'aol.com' );

	private const SOCIAL_HOSTS = array( 'facebook.com', 'fb.com', 'fb.me', 'instagram.com', 'linkedin.com', 'lnkd.in', 't.co', 'twitter.com', 'x.com', 'youtube.com', 'youtu.be', 'reddit.com', 'pinterest.', 'tiktok.com', 'threads.net', 'threads.com', 'bsky.app', 'quora.com', 'whatsapp.com', 'telegram.org', 't.me', 'snapchat.com', 'vk.com', 'xing.com', 'medium.com', 'tumblr.com', 'discord.com' );

	private const EMAIL_HOSTS = array( 'mail.google.com', 'outlook.live.com', 'outlook.office.com', 'outlook.office365.com', 'mail.yahoo.com', 'mail.proton.me', 'webmail.' );

	/**
	 * utm_source values (lowercase) of AI assistants that do not send a referrer.
	 */
	private const AI_SOURCES = array( 'chatgpt', 'chatgpt.com', 'openai', 'perplexity', 'perplexity.ai', 'gemini', 'copilot', 'claude', 'claude.ai', 'deepseek', 'mistral', 'grok' );

	/**
	 * Country code headers set by CDNs and hosts, in order of preference.
	 */
	private const COUNTRY_HEADERS = array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_GEOIP_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY', 'HTTP_FASTLY_GEO_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE' );

	/**
	 * Whether attribution is collected: leads, the setting and the conversation log are on.
	 */
	public static function enabled(): bool {
		return Settings::leads_enabled() && (bool) Settings::get( 'leads_attribution' ) && (bool) Settings::get( 'public_chat_log' );
	}

	/**
	 * Sanitizes the OneTrust category ID of marketing cookies (letters, digits, "-" and "_").
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_onetrust_group( $value ): string {
		$value = trim( is_scalar( $value ) ? (string) $value : '' );

		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,32}$/', $value ) ? $value : self::ONETRUST_GROUP;
	}

	/**
	 * Consent manager names for the admin screens.
	 *
	 * @return array<string, string>
	 */
	public static function consent_labels(): array {
		return array(
			'osano'          => __( 'Osano', 'wp-cortex' ),
			'onetrust'       => __( 'OneTrust', 'wp-cortex' ),
			'cookiebot'      => __( 'Cookiebot', 'wp-cortex' ),
			'wp_consent_api' => __( 'WP Consent API', 'wp-cortex' ),
			'none'           => __( 'None detected', 'wp-cortex' ),
		);
	}

	/**
	 * Channel names for the admin screens.
	 *
	 * @return array<string, string>
	 */
	public static function channel_labels(): array {
		return array(
			'paid_search'    => __( 'Paid search', 'wp-cortex' ),
			'paid_social'    => __( 'Paid social', 'wp-cortex' ),
			'display'        => __( 'Display', 'wp-cortex' ),
			'organic_search' => __( 'Organic search', 'wp-cortex' ),
			'organic_social' => __( 'Organic social', 'wp-cortex' ),
			'ai'             => __( 'AI assistants', 'wp-cortex' ),
			'email'          => __( 'Email', 'wp-cortex' ),
			'affiliate'      => __( 'Affiliate', 'wp-cortex' ),
			'referral'       => __( 'Referral', 'wp-cortex' ),
			'other'          => __( 'Other campaigns', 'wp-cortex' ),
			'direct'         => __( 'Direct', 'wp-cortex' ),
			''               => __( 'Unknown', 'wp-cortex' ),
		);
	}

	/**
	 * Custom parameters to record besides the campaign parameters and click IDs.
	 *
	 * @return string[]
	 */
	public static function extra_params(): array {
		return array_values( array_diff( (array) Settings::get( 'leads_extra_params' ), array_keys( self::UTM_PARAMS ), array_keys( self::CLICK_IDS ) ) );
	}

	/**
	 * Every query parameter the widget records.
	 *
	 * @return string[]
	 */
	public static function tracked_params(): array {
		return array_merge( array_keys( self::UTM_PARAMS ), array_keys( self::CLICK_IDS ), self::extra_params() );
	}

	/**
	 * Cleans a custom parameter list: lowercase names of letters, digits, "_" and "-".
	 *
	 * @param mixed $input Comma or line separated names, or a list.
	 * @return string[]
	 */
	public static function sanitize_param_names( $input ): array {
		if ( is_string( $input ) ) {
			$input = preg_split( '/[\s,]+/', $input, -1, PREG_SPLIT_NO_EMPTY );
		}

		$names = array();

		foreach ( (array) $input as $name ) {
			$name = strtolower( trim( (string) $name ) );

			if ( 1 === preg_match( '/^[a-z0-9_-]{1,40}$/', $name ) ) {
				$names[ $name ] = $name;
			}
		}

		return array_slice( array_values( $names ), 0, self::MAX_EXTRA_PARAMS );
	}

	/**
	 * Cleans the attribution sent by the widget and adds the device, country and the
	 * channel of each touch.
	 *
	 * @param mixed $raw Attribution from the request.
	 * @return array<string, mixed>|null Clean attribution, null when there is nothing usable.
	 */
	public static function from_request( $raw ): ?array {
		if ( ! is_array( $raw ) || strlen( (string) wp_json_encode( $raw ) ) > self::MAX_PAYLOAD ) {
			return null;
		}

		$last  = self::touch( $raw['last'] ?? null );
		$first = self::touch( $raw['first'] ?? null );

		if ( null === $last && null === $first ) {
			return null;
		}

		$last  = $last ?? $first;
		$first = $first ?? $last;

		$client  = is_array( $raw['client'] ?? null ) ? $raw['client'] : array();
		$lang    = (string) ( $client['lang'] ?? '' );
		$tz      = (string) ( $client['tz'] ?? '' );
		$screen  = (string) ( $client['screen'] ?? '' );
		$consent = (string) ( $client['consent'] ?? '' );

		return array(
			'first'   => $first,
			'last'    => $last,
			'visits'  => max( 1, min( 100000, (int) ( $raw['visits'] ?? 1 ) ) ),
			'pages'   => self::pages( $raw['pages'] ?? array() ),
			// Consent manager the widget found on the page ("none" when there was none).
			'consent' => in_array( $consent, self::CONSENT_SOURCES, true ) ? $consent : '',
			'device'  => array_filter(
				array_merge(
					self::device( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' ),
					array(
						'language' => 1 === preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}$/', $lang ) ? $lang : '',
						'timezone' => in_array( $tz, timezone_identifiers_list(), true ) ? $tz : '',
						'screen'   => 1 === preg_match( '/^\d{2,5}x\d{2,5}$/', $screen ) ? $screen : '',
						'country'  => self::country(),
					)
				)
			),
		);
	}

	/**
	 * Merges new attribution into the stored one: the first touch never changes once
	 * stored, the latest touch, visit count and device follow the latest message and the
	 * pages of the visit are kept in order without repeats.
	 *
	 * @param array $stored  Stored attribution (empty when none).
	 * @param array $current Clean attribution of this message.
	 * @param bool  $frozen  Whether the conversation is already a lead: the touches are kept as they were when it became one.
	 * @return array<string, mixed>
	 */
	public static function merge( array $stored, array $current, bool $frozen ): array {
		if ( ! $stored ) {
			return $current;
		}

		$merged = $stored;

		if ( empty( $stored['first'] ) || ( isset( $current['first']['at'], $stored['first']['at'] ) && strcmp( (string) $current['first']['at'], (string) $stored['first']['at'] ) < 0 && ! $frozen ) ) {
			$merged['first'] = $current['first'];
		}

		if ( ! $frozen ) {
			$merged['last']   = $current['last'];
			$merged['visits'] = max( (int) ( $stored['visits'] ?? 1 ), (int) $current['visits'] );
		}

		$merged['device'] = array_merge( (array) ( $stored['device'] ?? array() ), (array) $current['device'] );

		if ( ! empty( $current['consent'] ) ) {
			$merged['consent'] = $current['consent'];
		}

		$pages = array();

		foreach ( array_merge( (array) ( $stored['pages'] ?? array() ), (array) $current['pages'] ) as $page ) {
			$pages[ $page['path'] . '|' . $page['at'] ] = $page;
		}

		uasort( $pages, static fn( $a, $b ) => strcmp( (string) $a['at'], (string) $b['at'] ) );
		$merged['pages'] = array_slice( array_values( $pages ), -self::MAX_PAGES );

		return $merged;
	}

	/**
	 * Columns stored next to the JSON for filters and reports.
	 *
	 * @param array $attribution Clean attribution.
	 * @return array<string, mixed>
	 */
	public static function columns( array $attribution ): array {
		$first = (array) ( $attribution['first'] ?? array() );
		$last  = (array) ( $attribution['last'] ?? array() );

		return array(
			'channel'       => (string) ( $last['channel'] ?? '' ),
			'first_channel' => (string) ( $first['channel'] ?? '' ),
			'source'        => mb_substr( (string) ( $last['source'] ?? '' ), 0, 191 ),
			'medium'        => mb_substr( (string) ( $last['medium'] ?? '' ), 0, 191 ),
			'campaign'      => mb_substr( (string) ( $last['params']['utm_campaign'] ?? '' ), 0, 191 ),
			'referrer_host' => mb_substr( self::host( (string) ( $last['referrer'] ?? '' ) ), 0, 191 ),
			'landing_path'  => mb_substr( (string) ( $last['landing'] ?? '' ), 0, 191 ),
			'first_seen'    => ! empty( $first['at'] ) ? (string) $first['at'] : null,
			'visits'        => (int) ( $attribution['visits'] ?? 0 ),
		);
	}

	/**
	 * Channel, source and medium of a touch, like the default channel grouping of
	 * Google Analytics: campaign parameters first, then click IDs, then the referrer.
	 *
	 * @param array  $params   Recorded parameters.
	 * @param string $referrer Referring URL, empty for none.
	 * @return array{channel: string, source: string, medium: string}
	 */
	public static function classify( array $params, string $referrer ): array {
		$source   = strtolower( trim( (string) ( $params['utm_source'] ?? '' ) ) );
		$medium   = strtolower( trim( (string) ( $params['utm_medium'] ?? '' ) ) );
		$host     = self::host( $referrer );
		$ref_kind = '' !== $host ? self::host_kind( $host ) : '';

		if ( '' === $source && '' === $medium ) {
			if ( ! empty( $params['gclid'] ) || ! empty( $params['gbraid'] ) || ! empty( $params['wbraid'] ) ) {
				return self::result( 'paid_search', 'google', 'cpc' );
			}

			if ( ! empty( $params['msclkid'] ) ) {
				return self::result( 'paid_search', 'bing', 'cpc' );
			}

			foreach ( array( 'ttclid' => 'tiktok', 'li_fat_id' => 'linkedin', 'twclid' => 'x' ) as $key => $network ) {
				if ( ! empty( $params[ $key ] ) ) {
					return self::result( 'paid_social', $network, 'paid' );
				}
			}

			if ( '' === $host ) {
				// Facebook adds fbclid to organic links too, so it only says where the click came from.
				return ! empty( $params['fbclid'] ) ? self::result( 'organic_social', 'facebook', 'social' ) : self::result( 'direct', '(direct)', '(none)' );
			}

			$medium = array(
				'ai'     => 'ai-assistant',
				'search' => 'organic',
				'social' => 'social',
				'email'  => 'email',
			)[ $ref_kind ] ?? 'referral';

			return self::result( self::referrer_channel( $ref_kind ), self::source_name( $host ), $medium );
		}

		if ( '' === $source ) {
			$source = '' !== $host ? self::source_name( $host ) : '(not set)';
		}

		$source_kind = self::host_kind( $source );

		if ( in_array( $source, self::AI_SOURCES, true ) || 'ai' === $source_kind || 'ai' === $ref_kind ) {
			return self::result( 'ai', $source, '' !== $medium ? $medium : 'referral' );
		}

		$kind = '' !== $source_kind ? $source_kind : $ref_kind;

		if ( 1 === preg_match( '/^(.*cp.*|ppc|retargeting|paid.*)$/', $medium ) ) {
			if ( 'social' === $kind || 1 === preg_match( '/social/', $medium ) || self::is_social_name( $source ) ) {
				return self::result( 'paid_social', $source, $medium );
			}

			return self::result( 'search' === $kind || self::is_search_name( $source ) || 1 === preg_match( '/^(cpc|ppc|paidsearch|paid_search|paid-search)$/', $medium ) ? 'paid_search' : 'display', $source, $medium );
		}

		if ( in_array( $medium, array( 'display', 'banner', 'cpm', 'expandable', 'interstitial', 'programmatic' ), true ) ) {
			return self::result( 'display', $source, $medium );
		}

		if ( in_array( $medium, array( 'email', 'e-mail', 'e_mail', 'e mail', 'newsletter' ), true ) || 1 === preg_match( '/^(email|e-mail|e_mail|newsletter)$/', $source ) ) {
			return self::result( 'email', $source, '' !== $medium ? $medium : 'email' );
		}

		if ( 'affiliate' === $medium || 'affiliates' === $medium || 'partner' === $medium ) {
			return self::result( 'affiliate', $source, $medium );
		}

		if ( in_array( $medium, array( 'social', 'social-network', 'social-media', 'sm', 'social network', 'social media' ), true ) || 'social' === $kind || self::is_social_name( $source ) ) {
			return self::result( 'organic_social', $source, '' !== $medium ? $medium : 'social' );
		}

		if ( 'organic' === $medium || 'search' === $kind || self::is_search_name( $source ) ) {
			return self::result( 'organic_search', $source, '' !== $medium ? $medium : 'organic' );
		}

		if ( 'referral' === $medium ) {
			return self::result( 'referral', $source, $medium );
		}

		return self::result( 'other', $source, '' !== $medium ? $medium : '(not set)' );
	}

	/**
	 * Host of a URL without "www.", lowercase; empty when it is not a URL.
	 *
	 * @param string $url URL.
	 */
	public static function host( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return (string) preg_replace( '/^(www|m|l|lm)\./', '', $host );
	}

	/**
	 * Device type, browser and operating system from the user agent (coarse, no
	 * version numbers: enough for reports, not for fingerprinting).
	 *
	 * @param string $ua User agent.
	 * @return array{type?: string, browser?: string, os?: string}
	 */
	public static function device( string $ua ): array {
		if ( '' === $ua ) {
			return array();
		}

		$type = 'desktop';

		if ( 1 === preg_match( '/iPad|Tablet|Nexus (7|9|10)|SM-T|Kindle|Silk|PlayBook|(Android(?!.*Mobile))/i', $ua ) ) {
			$type = 'tablet';
		} elseif ( 1 === preg_match( '/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|Opera Mini/i', $ua ) ) {
			$type = 'mobile';
		}

		if ( 1 === preg_match( '/bot|crawl|spider|slurp|headless|lighthouse/i', $ua ) ) {
			$type = 'bot';
		}

		$browsers = array(
			'Edge'             => '/Edg(e|A|iOS)?\//',
			'Opera'            => '/OPR\/|Opera/',
			'Samsung Internet' => '/SamsungBrowser/',
			'Firefox'          => '/Firefox|FxiOS/',
			'Chrome'           => '/Chrome|CriOS/',
			'Safari'           => '/Safari/',
		);
		$systems  = array(
			'iOS'      => '/iPhone|iPad|iPod/',
			'Android'  => '/Android/',
			'Windows'  => '/Windows/',
			'macOS'    => '/Macintosh|Mac OS X/',
			'ChromeOS' => '/CrOS/',
			'Linux'    => '/Linux/',
		);

		$device = array( 'type' => $type );

		foreach ( $browsers as $name => $pattern ) {
			if ( 1 === preg_match( $pattern, $ua ) ) {
				$device['browser'] = $name;
				break;
			}
		}

		foreach ( $systems as $name => $pattern ) {
			if ( 1 === preg_match( $pattern, $ua ) ) {
				$device['os'] = $name;
				break;
			}
		}

		return $device;
	}

	/**
	 * Two-letter country code from a CDN or host header, empty when none is set.
	 */
	private static function country(): string {
		foreach ( self::COUNTRY_HEADERS as $header ) {
			$value = isset( $_SERVER[ $header ] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ) : '';

			// XX and T1 (Tor) are Cloudflare's "unknown" codes.
			if ( 1 === preg_match( '/^[A-Z]{2}$/', $value ) && ! in_array( $value, array( 'XX', 'T1' ), true ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Cleans one touch: when it started, the landing page, the referrer and the parameters.
	 *
	 * @param mixed $raw Touch from the request.
	 * @return array<string, mixed>|null
	 */
	private static function touch( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$at = self::date( $raw['at'] ?? '' );

		if ( '' === $at ) {
			return null;
		}

		$params  = array();
		$raw_par = is_array( $raw['params'] ?? null ) ? $raw['params'] : array();

		foreach ( self::tracked_params() as $key ) {
			if ( ! isset( $raw_par[ $key ] ) || ! is_scalar( $raw_par[ $key ] ) ) {
				continue;
			}

			$max   = self::UTM_PARAMS[ $key ] ?? ( isset( self::CLICK_IDS[ $key ] ) ? 255 : 150 );
			$value = trim( mb_substr( sanitize_text_field( (string) $raw_par[ $key ] ), 0, $max ) );

			if ( '' !== $value ) {
				$params[ $key ] = $value;
			}
		}

		$referrer = self::referrer( (string) ( $raw['referrer'] ?? '' ) );
		$touch    = array(
			'at'       => $at,
			'landing'  => self::path( (string) ( $raw['landing'] ?? '' ) ),
			'referrer' => $referrer,
			'params'   => $params,
		);

		return array_merge( $touch, self::classify( $params, $referrer ) );
	}

	/**
	 * Pages of the visit: path, title and time, oldest first.
	 *
	 * @param mixed $raw Pages from the request.
	 * @return array<int, array{path: string, title: string, at: string}>
	 */
	private static function pages( $raw ): array {
		$pages = array();

		foreach ( array_slice( is_array( $raw ) ? array_values( $raw ) : array(), -self::MAX_PAGES ) as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}

			$path = self::path( (string) ( $page['path'] ?? '' ) );
			$at   = self::date( $page['at'] ?? '' );

			if ( '' === $path || '' === $at ) {
				continue;
			}

			$pages[] = array(
				'path'  => $path,
				'title' => mb_substr( trim( sanitize_text_field( (string) ( $page['title'] ?? '' ) ) ), 0, 150 ),
				'at'    => $at,
			);
		}

		return $pages;
	}

	/**
	 * A site path ("/services/seo/"), without query string or fragment; empty when invalid.
	 *
	 * @param string $path Path from the browser.
	 */
	private static function path( string $path ): string {
		$path = (string) strtok( trim( $path ), '?#' );

		if ( '' === $path || '/' !== $path[0] || str_starts_with( $path, '//' ) ) {
			return '';
		}

		$path = (string) preg_replace( '/[^\p{L}\p{N}\/._~%!$&\'()*+,;=:@-]/u', '', $path );

		return mb_substr( $path, 0, 300 );
	}

	/**
	 * Referring URL reduced to scheme, host and path; empty for this site or a non-http URL.
	 *
	 * @param string $url URL from document.referrer.
	 */
	private static function referrer( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );

		if ( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host ) {
			return '';
		}

		$url = strtolower( (string) $parts['scheme'] ) . '://' . $host . self::path( (string) ( $parts['path'] ?? '/' ) );

		return mb_substr( esc_url_raw( $url, array( 'http', 'https' ) ), 0, 500 );
	}

	/**
	 * A browser time (ISO 8601) as a UTC MySQL date, empty when invalid or out of range
	 * (more than two years ago or in the future).
	 *
	 * @param mixed $value Time from the browser.
	 */
	private static function date( $value ): string {
		$time = is_string( $value ) ? strtotime( $value ) : false;

		if ( false === $time || $time > time() + HOUR_IN_SECONDS || $time < time() - 2 * YEAR_IN_SECONDS ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', $time );
	}

	/**
	 * Kind of a referring host or source name: ai, search, social, email or "".
	 *
	 * @param string $host Host or source name.
	 */
	private static function host_kind( string $host ): string {
		foreach ( array(
			'ai'     => self::AI_HOSTS,
			'email'  => self::EMAIL_HOSTS,
			'search' => self::SEARCH_HOSTS,
			'social' => self::SOCIAL_HOSTS,
		) as $kind => $hosts ) {
			foreach ( $hosts as $entry ) {
				if ( self::host_matches( $host, $entry ) ) {
					return $kind;
				}
			}
		}

		return '';
	}

	/**
	 * Whether a host is an entry or one of its subdomains ("google." matches any google.tld).
	 *
	 * @param string $host  Host.
	 * @param string $entry List entry.
	 */
	private static function host_matches( string $host, string $entry ): bool {
		if ( str_ends_with( $entry, '.' ) ) {
			return 1 === preg_match( '/(^|\.)' . preg_quote( $entry, '/' ) . '[a-z.]{2,6}$/', $host );
		}

		return $host === $entry || str_ends_with( $host, '.' . $entry );
	}

	/**
	 * Channel of a referrer of the given kind.
	 *
	 * @param string $kind Host kind.
	 */
	private static function referrer_channel( string $kind ): string {
		return array(
			'ai'     => 'ai',
			'search' => 'organic_search',
			'social' => 'organic_social',
			'email'  => 'email',
		)[ $kind ] ?? 'referral';
	}

	/**
	 * Short source name of a referring host: "google" for www.google.co.uk, the host otherwise.
	 *
	 * @param string $host Host.
	 */
	private static function source_name( string $host ): string {
		foreach ( array( 'google', 'bing', 'yahoo', 'duckduckgo', 'yandex', 'baidu', 'facebook', 'instagram', 'linkedin', 'pinterest', 'reddit', 'youtube', 'tiktok' ) as $name ) {
			if ( 1 === preg_match( '/(^|\.)' . $name . '\./', $host ) && ! in_array( self::host_kind( $host ), array( 'ai', 'email' ), true ) ) {
				return $name;
			}
		}

		if ( in_array( $host, array( 't.co', 'twitter.com', 'x.com' ), true ) ) {
			return 'x';
		}

		if ( 'lnkd.in' === $host ) {
			return 'linkedin';
		}

		return $host;
	}

	/**
	 * Whether a utm_source names a search engine.
	 *
	 * @param string $source Source.
	 */
	private static function is_search_name( string $source ): bool {
		return in_array( $source, array( 'google', 'bing', 'yahoo', 'duckduckgo', 'yandex', 'baidu', 'ecosia', 'brave', 'adwords', 'google_ads', 'googleads', 'microsoft', 'microsoft_ads' ), true );
	}

	/**
	 * Whether a utm_source names a social network.
	 *
	 * @param string $source Source.
	 */
	private static function is_social_name( string $source ): bool {
		return in_array( $source, array( 'facebook', 'fb', 'meta', 'instagram', 'ig', 'linkedin', 'twitter', 'x', 'youtube', 'reddit', 'pinterest', 'tiktok', 'threads', 'bluesky', 'snapchat', 'whatsapp', 'telegram', 'quora', 'xing' ), true );
	}

	/**
	 * Classification result.
	 *
	 * @param string $channel Channel.
	 * @param string $source  Source.
	 * @param string $medium  Medium.
	 * @return array{channel: string, source: string, medium: string}
	 */
	private static function result( string $channel, string $source, string $medium ): array {
		return array(
			'channel' => $channel,
			'source'  => mb_substr( $source, 0, 100 ),
			'medium'  => mb_substr( $medium, 0, 100 ),
		);
	}
}
