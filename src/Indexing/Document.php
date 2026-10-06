<?php
/**
 * Normalized representation of an indexable object.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing;

use WPCortex\Storage\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * What extractors produce and the store persists. Fields and extra sections carry the
 * extractor's "public" default; FieldPolicy (the field_scopes setting) decides which
 * index each one actually goes into, and for_scope() is the only place that applies it.
 */
final class Document {

	public string $object_type = 'post';
	public int $object_id      = 0;
	public string $subtype     = '';
	public string $status      = '';
	public string $title       = '';
	public string $url         = '';
	public string $excerpt     = '';
	public int $author_id      = 0;
	public string $author_name = '';
	public string $published_at = '';
	public string $modified_at  = '';

	/**
	 * Rendered main content (HTML).
	 */
	public string $body_html = '';

	/**
	 * Structured fields.
	 *
	 * @var array<int, array{source: string, name: string, value: string, public: bool, public_value?: string|null}>
	 */
	public array $fields = array();

	/**
	 * Additional text sections (e.g. long ACF text fields) that are chunked alongside the body.
	 *
	 * @var array<int, array{heading: string, text: string, public: bool, field: string}>
	 */
	public array $sections = array();

	/**
	 * Adds a structured field.
	 *
	 * @param string $source Origin, e.g. "taxonomy", "yoast", "acf", "meta".
	 * @param string $name   Field name.
	 * @param mixed  $value  Scalar value.
	 * @param bool   $public Whether the field goes into the public index unless the field_scopes setting says otherwise.
	 */
	public function add_field( string $source, string $name, $value, bool $public = false ): void {
		$this->fields[] = array(
			'source' => $source,
			'name'   => $name,
			'value'  => is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value,
			'public' => $public,
		);
	}

	/**
	 * Adds a structured field whose value in the public index differs from the admin one,
	 * for example a list of related posts where only the published ones may be named
	 * publicly. A null public value keeps the field out of the public index whatever the
	 * field_scopes setting says.
	 *
	 * @param string      $source       Origin, e.g. "acf".
	 * @param string      $name         Field name.
	 * @param string      $value        Value in the admin index.
	 * @param string|null $public_value Value in the public index, or null for none.
	 * @param bool        $public       Whether the field goes into the public index unless the field_scopes setting says otherwise.
	 */
	public function add_scoped_field( string $source, string $name, string $value, ?string $public_value, bool $public = false ): void {
		$this->fields[] = array(
			'source'       => $source,
			'name'         => $name,
			'value'        => $value,
			'public'       => $public,
			'public_value' => $public_value,
		);
	}

	/**
	 * Adds a text section.
	 *
	 * @param string $heading Section heading.
	 * @param string $text    Plain text or HTML.
	 * @param bool   $public  Whether the section goes into the public index unless its field's rule says otherwise.
	 * @param string $field   Key ("source:name") of the field the section comes from; its rule applies
	 *                        to the section. Empty for sections that are not tied to a field.
	 */
	public function add_section( string $heading, string $text, bool $public = false, string $field = '' ): void {
		$this->sections[] = array(
			'heading' => $heading,
			'text'    => $text,
			'public'  => $public,
			'field'   => $field,
		);
	}

	/**
	 * Copy that only contains the fields and sections allowed in the given scope.
	 *
	 * @param string      $scope  Storage::SCOPE_PUBLIC or Storage::SCOPE_ADMIN.
	 * @param FieldPolicy $policy Field rules.
	 */
	public function for_scope( string $scope, FieldPolicy $policy ): self {
		$copy         = clone $this;
		$copy->fields = array();

		foreach ( $this->fields as $field ) {
			if ( ! $policy->allows( $scope, $field['source'] . ':' . $field['name'], $field['public'] ) ) {
				continue;
			}

			if ( array_key_exists( 'public_value', $field ) ) {
				if ( Storage::SCOPE_PUBLIC === $scope ) {
					if ( null === $field['public_value'] ) {
						continue;
					}
					$field['value'] = $field['public_value'];
				}
				unset( $field['public_value'] );
			}

			$copy->fields[] = $field;
		}

		$copy->sections = array_values(
			array_filter(
				$this->sections,
				static fn( $s ) => '' === $s['field']
					? ( Storage::SCOPE_PUBLIC !== $scope || $s['public'] )
					: $policy->allows( $scope, $s['field'], $s['public'] )
			)
		);

		return $copy;
	}
}
