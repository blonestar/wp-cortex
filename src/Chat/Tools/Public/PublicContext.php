<?php
/**
 * Turn context of the visitor chat.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Public;

use WPCortex\Chat\Tools\ToolContext;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * What the visitor chat tools know about the turn. Its search() only reads the public
 * index; the visitor's own conversation is the only stored data it points to.
 */
final class PublicContext extends ToolContext {

	/**
	 * Public rows the model has seen in this turn (search results, pages read and the
	 * current page), keyed by post ID; only these can be cited.
	 *
	 * @var array<int, array{id: int, title: string, url: string, snippet: string}>
	 */
	private array $seen = array();

	/**
	 * Page the visitor is taken to after this turn; with reload set, the current page is
	 * reloaded instead.
	 *
	 * @var array{id: int, title: string, url: string, reload?: bool}|null
	 */
	private ?array $navigation = null;

	/**
	 * Public document of the page the visitor is viewing, false until loaded.
	 *
	 * @var array|null|false
	 */
	private $current = false;

	/**
	 * Constructor.
	 *
	 * @param int    $chat_id    Stored conversation of this visitor, 0 when the log is off.
	 * @param int    $post_id    Post the visitor is viewing according to the browser, 0 for none.
	 * @param string $page_url   URL of the page the visitor is viewing, already sanitized.
	 * @param string $image_name Image of this message stored with the conversation, "" for none.
	 */
	public function __construct( private int $chat_id, private int $post_id, private string $page_url, private string $image_name ) {
		$current = $this->current();

		if ( $current ) {
			$this->seen[ $current['id'] ] = $current;
		}
	}

	public function scope(): string {
		return self::PUBLIC;
	}

	/**
	 * Stored conversation of this visitor, 0 when the log is off.
	 */
	public function chat_id(): int {
		return $this->chat_id;
	}

	/**
	 * Post the visitor is viewing according to the browser (not checked), 0 for none.
	 */
	public function post_id(): int {
		return $this->post_id;
	}

	/**
	 * URL of the page the visitor is viewing (on the site's own host, or "").
	 */
	public function page_url(): string {
		return $this->page_url;
	}

	/**
	 * Name of the image attached to this message and stored with the conversation, "" for none.
	 */
	public function image_name(): string {
		return $this->image_name;
	}

	/**
	 * Page the visitor is viewing, when it is in the public index.
	 *
	 * @return array{id: int, title: string, url: string, snippet: string}|null
	 */
	public function current(): ?array {
		if ( false === $this->current ) {
			$doc           = $this->get_public_document( $this->post_id );
			$this->current = null === $doc ? null : array(
				'id'      => $this->post_id,
				'title'   => (string) $doc['title'],
				'url'     => (string) $doc['url'],
				'snippet' => '',
			);
		}

		return $this->current;
	}

	/**
	 * Post types of the public index, without media.
	 *
	 * @return string[]
	 */
	public function post_types(): array {
		return array_values( array_diff( Settings::post_types( self::PUBLIC ), array( 'attachment' ) ) );
	}

	/**
	 * Gets a public visitor-chat document of an allowed content type.
	 *
	 * Media attachments are valid public index documents when media indexing is enabled,
	 * but they are not pages the visitor chat may read or open.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array<string, mixed>|null
	 */
	public function get_public_document( int $post_id ): ?array {
		if ( $post_id < 1 ) {
			return null;
		}

		try {
			$doc = $this->search()->get_document( $post_id );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( null === $doc
			|| 'post' !== (string) ( $doc['object_type'] ?? '' )
			|| ! in_array( (string) ( $doc['subtype'] ?? '' ), $this->post_types(), true ) ) {
			return null;
		}

		return $doc;
	}

	/**
	 * Authors with content in the public index that match a name, with their archive URL.
	 *
	 * The URL is empty when the site has no author archives (for example disabled in Yoast SEO).
	 *
	 * @param string $author Author name.
	 * @return array<int, array{id: int, name: string, count: int, url: string}>
	 */
	public function public_authors( string $author ): array {
		$types = $this->post_types();

		if ( ! $types || '' === $author ) {
			return array();
		}

		try {
			$authors = $this->search()->find_authors( mb_substr( $author, 0, 100 ), array( 'post_types' => $types ) );
		} catch ( \Throwable $e ) {
			return array();
		}

		$archives = ! ( class_exists( 'WPSEO_Options' ) && \WPSEO_Options::get( 'disable-author' ) );

		foreach ( $authors as &$row ) {
			$row['url'] = $archives && get_userdata( $row['id'] ) ? (string) get_author_posts_url( $row['id'] ) : '';
		}
		unset( $row );

		return $authors;
	}

	/**
	 * Remembers a public page the model has seen, so the answer can cite it.
	 *
	 * @param int    $id      Post ID.
	 * @param string $title   Title.
	 * @param string $url     URL.
	 * @param string $snippet Snippet shown with the source; an earlier one is kept when this is "".
	 */
	public function remember( int $id, string $title, string $url, string $snippet = '' ): void {
		$this->seen[ $id ] = array(
			'id'      => $id,
			'title'   => $title,
			'url'     => $url,
			'snippet' => '' !== $snippet ? $snippet : (string) ( $this->seen[ $id ]['snippet'] ?? '' ),
		);
	}

	/**
	 * Public pages the model has seen in this turn, keyed by post ID.
	 *
	 * @return array<int, array{id: int, title: string, url: string, snippet: string}>
	 */
	public function seen(): array {
		return $this->seen;
	}

	/**
	 * Takes the visitor to a page after this turn; a later call replaces an earlier one.
	 * Only call it with pages from the public index (or public author archives).
	 *
	 * @param int    $id    Post ID, 0 for an author archive.
	 * @param string $title Title.
	 * @param string $url   URL.
	 */
	public function navigate( int $id, string $title, string $url ): void {
		$this->navigation = array(
			'id'    => $id,
			'title' => $title,
			'url'   => esc_url_raw( $url ),
		);
	}

	/**
	 * Reloads the page the visitor is on after this turn; replaces an earlier navigation.
	 * The URL is only kept for the conversation log (the browser reloads whatever it shows)
	 * and is already limited to the site's own host.
	 */
	public function reload(): void {
		$current = $this->current();

		$this->navigation = array(
			'id'     => $current ? (int) $current['id'] : 0,
			'title'  => $current ? wp_specialchars_decode( (string) $current['title'], ENT_QUOTES ) : '',
			'url'    => $this->page_url,
			'reload' => true,
		);
	}

	/**
	 * Page the visitor is taken to after this turn.
	 *
	 * @return array{id: int, title: string, url: string, reload?: bool}|null
	 */
	public function navigation(): ?array {
		return $this->navigation;
	}
}
