<?php
/**
 * Normalized representation of an indexable object.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing;

defined( 'ABSPATH' ) || exit;

/**
 * What extractors produce and the store persists. Fields and extra sections carry a
 * "public" flag; the public index only receives the ones marked public.
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
	 * @var array<int, array{source: string, name: string, value: string, public: bool}>
	 */
	public array $fields = array();

	/**
	 * Additional text sections (e.g. long ACF text fields) that are chunked alongside the body.
	 *
	 * @var array<int, array{heading: string, text: string, public: bool}>
	 */
	public array $sections = array();

	/**
	 * Adds a structured field.
	 *
	 * @param string $source Origin, e.g. "taxonomy", "yoast", "acf", "meta".
	 * @param string $name   Field name.
	 * @param mixed  $value  Scalar value.
	 * @param bool   $public Whether the field may appear in the public index.
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
	 * Adds a text section.
	 *
	 * @param string $heading Section heading.
	 * @param string $text    Plain text or HTML.
	 * @param bool   $public  Whether the section may appear in the public index.
	 */
	public function add_section( string $heading, string $text, bool $public = false ): void {
		$this->sections[] = array(
			'heading' => $heading,
			'text'    => $text,
			'public'  => $public,
		);
	}

	/**
	 * Copy that only contains data allowed in the given scope.
	 *
	 * @param bool $public Whether the copy is for the public index.
	 */
	public function for_scope( bool $public ): self {
		if ( ! $public ) {
			return clone $this;
		}

		$copy           = clone $this;
		$copy->fields   = array_values( array_filter( $this->fields, static fn( $f ) => $f['public'] ) );
		$copy->sections = array_values( array_filter( $this->sections, static fn( $s ) => $s['public'] ) );

		return $copy;
	}
}
