<?php
/**
 * Visitor chat tool: get_page.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Public;

use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the text and public fields of one page of the public index.
 */
final class GetPage extends PublicTool {

	private const DOCUMENT_CHARS = 5000;

	public function name(): string {
		return 'get_page';
	}

	public function label(): string {
		return __( 'Read a page', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Returns the text and details of one published page by its ID (taken from search_site results). Use it when the snippets are not enough to answer.';
	}

	/**
	 * Arguments.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function parameters( ToolContext $context ): ?array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'ID of the page.',
				),
			),
			'required'   => array( 'post_id' ),
		);
	}

	/**
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array( 'Use get_page to read a page when the snippets are not enough.' );
	}

	/**
	 * Reads the page.
	 *
	 * @param array       $args    Arguments: post_id.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$visitor = $this->visitor( $context );
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$doc     = $visitor->get_public_document( $post_id );

		if ( null === $doc ) {
			return array( 'error' => 'No published page with this ID.' );
		}

		$remaining = self::DOCUMENT_CHARS;
		$content   = array();

		foreach ( $doc['chunks'] as $chunk ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$text       = mb_substr( (string) $chunk['content'], 0, $remaining );
			$remaining -= mb_strlen( $text );
			$content[]  = array(
				'section' => (string) $chunk['heading'],
				'text'    => $text,
			);
		}

		$fields = array();

		foreach ( $doc['fields'] as $field ) {
			$fields[] = array(
				'name'  => (string) $field['name'],
				'value' => mb_substr( (string) $field['value'], 0, 300 ),
			);
		}

		$seen = $visitor->seen();

		$visitor->remember( $post_id, (string) $doc['title'], (string) $doc['url'], $seen[ $post_id ]['snippet'] ?? (string) ( $doc['excerpt'] ?? '' ) );

		return array(
			'id'        => $post_id,
			'title'     => (string) $doc['title'],
			'post_type' => (string) ( $doc['subtype'] ?? '' ),
			'url'       => (string) $doc['url'],
			'author'    => (string) ( $doc['author_name'] ?? '' ),
			'date'      => substr( (string) ( $doc['published_at'] ?? '' ), 0, 10 ),
			'fields'    => $fields,
			'content'   => $content,
			'truncated' => count( $content ) < count( $doc['chunks'] ) || ( $content && $remaining <= 0 ),
		);
	}
}
