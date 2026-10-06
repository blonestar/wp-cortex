<?php
/**
 * Which index each field goes into.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing;

use WPCortex\Indexing\Extractors\DescribesFields;
use WPCortex\Settings;
use WPCortex\Storage\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the indexes of a field from the `field_scopes` setting. Keys are
 * "source:name"; a key ending in "*" matches every name with that prefix and a key
 * without dots also matches the nested values of that field (ACF groups and repeaters).
 * Fields without a rule keep the extractor's default: always in the admin index, in the
 * public index only when the extractor marked them public.
 */
final class FieldPolicy {

	/**
	 * Scope values a rule may have.
	 */
	public const VALUES = array( 'both', 'admin', 'public', 'none' );

	/**
	 * Rules: field key => one of VALUES.
	 *
	 * @var array<string, string>
	 */
	private array $rules;

	/**
	 * FieldPolicy constructor.
	 *
	 * @param array<string, string> $rules Field key => scope value.
	 */
	public function __construct( array $rules ) {
		$this->rules = array_filter( $rules, static fn( $value ) => in_array( $value, self::VALUES, true ) );
	}

	/**
	 * Policy from the saved settings.
	 */
	public static function from_settings(): self {
		return new self( (array) Settings::get( 'field_scopes' ) );
	}

	/**
	 * Whether a field (or a section built from it) belongs in a scope.
	 *
	 * @param string $scope          Storage::SCOPE_PUBLIC or Storage::SCOPE_ADMIN.
	 * @param string $key            Field key ("source:name").
	 * @param bool   $default_public The extractor's default for the public index.
	 */
	public function allows( string $scope, string $key, bool $default_public ): bool {
		$rule = $this->rule( $key );

		if ( null === $rule ) {
			return Storage::SCOPE_PUBLIC === $scope ? $default_public : true;
		}

		return 'both' === $rule || $scope === $rule;
	}

	/**
	 * Rule that applies to a key: the exact key, then the top-level field, then the
	 * longest matching wildcard.
	 *
	 * @param string $key Field key.
	 */
	private function rule( string $key ): ?string {
		if ( isset( $this->rules[ $key ] ) ) {
			return $this->rules[ $key ];
		}

		$dot = strpos( $key, '.' );
		if ( false !== $dot && isset( $this->rules[ substr( $key, 0, $dot ) ] ) ) {
			return $this->rules[ substr( $key, 0, $dot ) ];
		}

		$match  = null;
		$length = -1;

		foreach ( $this->rules as $pattern => $value ) {
			if ( ! str_ends_with( $pattern, '*' ) ) {
				continue;
			}

			$prefix = substr( $pattern, 0, -1 );
			if ( strlen( $prefix ) > $length && str_starts_with( $key, $prefix ) ) {
				$match  = $value;
				$length = strlen( $prefix );
			}
		}

		return $match;
	}

	/**
	 * Configurable fields of the available extractors, grouped by extractor label.
	 *
	 * @return array<string, array<string, array{label: string, public: bool, group?: string, type?: string, post_types?: string[]}>> Group label => field key => field.
	 */
	public static function catalog(): array {
		$groups = array();

		foreach ( Indexer::extractors() as $extractor ) {
			if ( ! $extractor instanceof DescribesFields || ! $extractor->is_available() ) {
				continue;
			}

			$fields = $extractor->fields();
			if ( $fields ) {
				$groups[ $extractor->fields_label() ] = $fields;
			}
		}

		return $groups;
	}
}
