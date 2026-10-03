<?php
/**
 * Splits content into retrieval chunks.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing;

defined( 'ABSPATH' ) || exit;

/**
 * Splits rendered HTML at headings, then packs paragraphs into chunks of roughly
 * $size characters with $overlap characters carried over between neighbours.
 */
final class Chunker {

	/**
	 * Chunker constructor.
	 *
	 * @param int $size    Target chunk size in characters.
	 * @param int $overlap Characters repeated from the previous chunk.
	 */
	public function __construct( private int $size, private int $overlap ) {}

	/**
	 * Chunks for a document.
	 *
	 * @param Document $doc Document (already reduced to its scope).
	 * @return array<int, array{heading: string, content: string}>
	 */
	public function chunk( Document $doc ): array {
		$sections = $this->sections_from_html( $doc->body_html );
		foreach ( $doc->sections as $section ) {
			$sections[] = array(
				'heading' => $section['heading'],
				'text'    => self::html_to_text( $section['text'] ),
			);
		}

		$chunks = array();
		$buffer = null;

		foreach ( $sections as $section ) {
			$parts = $this->split_section( $section['heading'], $section['text'] );

			if ( count( $parts ) !== 1 ) {
				if ( $buffer ) {
					$chunks[] = $buffer;
					$buffer   = null;
				}
				array_push( $chunks, ...$parts );
				continue;
			}

			// Consecutive short sections are packed together, keeping later headings inline.
			$inline = '' === $parts[0]['heading'] ? $parts[0]['content'] : $parts[0]['heading'] . "\n" . $parts[0]['content'];

			if ( $buffer && mb_strlen( $buffer['content'] ) + mb_strlen( $inline ) + 2 <= $this->size ) {
				$buffer['content'] .= "\n\n" . $inline;
				continue;
			}

			if ( $buffer ) {
				$chunks[] = $buffer;
			}
			$buffer = $parts[0];
		}

		if ( $buffer ) {
			$chunks[] = $buffer;
		}

		// Objects without body text are still findable by title and excerpt.
		if ( ! $chunks ) {
			$fallback = trim( $doc->title . "\n\n" . $doc->excerpt );
			if ( '' !== $fallback ) {
				$chunks[] = array(
					'heading' => '',
					'content' => $fallback,
				);
			}
		}

		return $chunks;
	}

	/**
	 * Converts HTML to plain text, keeping block boundaries as line breaks.
	 *
	 * @param string $html HTML.
	 */
	public static function html_to_text( string $html ): string {
		$html = (string) preg_replace( '#<(script|style|noscript|template|svg|iframe|form|nav)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = (string) preg_replace( '#<!--.*?-->#s', ' ', $html );
		$html = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = (string) preg_replace( '#</(p|div|li|tr|h[1-6]|blockquote|pre|section|article|figcaption|dt|dd)>#i', "\n\n", $html );
		$html = (string) preg_replace( '#<(td|th)\b[^>]*>#i', ' | ', $html );

		$text = html_entity_decode( wp_strip_all_tags( $html, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\u{00A0}", ' ', $text );
		$text = (string) preg_replace( '/\h+/u', ' ', $text );
		$text = (string) preg_replace( '/ ?\R ?/u', "\n", $text );
		$text = (string) preg_replace( '/\n{3,}/u', "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Splits HTML into heading-delimited sections.
	 *
	 * @param string $html HTML.
	 * @return array<int, array{heading: string, text: string}>
	 */
	private function sections_from_html( string $html ): array {
		$parts    = preg_split( '#(<h[1-6]\b[^>]*>.*?</h[1-6]>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array( $html );
		$sections = array();
		$heading  = '';

		foreach ( $parts as $part ) {
			if ( preg_match( '#^<h[1-6]\b#i', $part ) ) {
				$heading = self::html_to_text( $part );
				continue;
			}

			$text = self::html_to_text( $part );
			if ( '' !== $text ) {
				$sections[] = array(
					'heading' => $heading,
					'text'    => $text,
				);
			}
		}

		return $sections;
	}

	/**
	 * Packs a section's paragraphs into size-limited chunks.
	 *
	 * @param string $heading Section heading.
	 * @param string $text    Section text.
	 * @return array<int, array{heading: string, content: string}>
	 */
	private function split_section( string $heading, string $text ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array();
		}

		$pieces = array();
		foreach ( preg_split( '/\n{2,}/u', $text ) as $paragraph ) {
			array_push( $pieces, ...$this->split_long( trim( $paragraph ) ) );
		}

		$chunks  = array();
		$current = '';

		foreach ( $pieces as $piece ) {
			if ( '' === $piece ) {
				continue;
			}

			if ( '' !== $current && mb_strlen( $current ) + mb_strlen( $piece ) + 2 > $this->size ) {
				$chunks[] = $current;
				$current  = $this->tail( $current );
				$current  = '' === $current ? $piece : $current . "\n\n" . $piece;
				continue;
			}

			$current = '' === $current ? $piece : $current . "\n\n" . $piece;
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return array_map(
			static fn( string $content ) => array(
				'heading' => $heading,
				'content' => $content,
			),
			$chunks
		);
	}

	/**
	 * Splits a paragraph longer than the chunk size at sentence (or word) boundaries.
	 *
	 * @param string $paragraph Paragraph.
	 * @return string[]
	 */
	private function split_long( string $paragraph ): array {
		if ( mb_strlen( $paragraph ) <= $this->size ) {
			return array( $paragraph );
		}

		$units  = preg_split( '/(?<=[.!?])\s+/u', $paragraph ) ?: array( $paragraph );
		$pieces = array();
		$buffer = '';

		foreach ( $units as $unit ) {
			// A single sentence longer than the limit is cut at word boundaries.
			while ( mb_strlen( $unit ) > $this->size ) {
				$cut    = mb_strrpos( mb_substr( $unit, 0, $this->size ), ' ' ) ?: $this->size;
				$head   = trim( mb_substr( $unit, 0, $cut ) );
				$unit   = trim( mb_substr( $unit, $cut ) );
				$pieces = array_merge( $pieces, '' === $buffer ? array() : array( $buffer ), array( $head ) );
				$buffer = '';
			}

			if ( '' !== $buffer && mb_strlen( $buffer ) + mb_strlen( $unit ) + 1 > $this->size ) {
				$pieces[] = $buffer;
				$buffer   = '';
			}

			$buffer = '' === $buffer ? $unit : $buffer . ' ' . $unit;
		}

		if ( '' !== $buffer ) {
			$pieces[] = $buffer;
		}

		return $pieces;
	}

	/**
	 * Last $overlap characters of a chunk, starting at a word boundary.
	 *
	 * @param string $chunk Chunk text.
	 */
	private function tail( string $chunk ): string {
		if ( $this->overlap <= 0 || mb_strlen( $chunk ) <= $this->overlap ) {
			return '';
		}

		$tail  = mb_substr( $chunk, -$this->overlap );
		$space = mb_strpos( $tail, ' ' );

		return trim( false === $space ? $tail : mb_substr( $tail, $space ) );
	}
}
