<?php
/**
 * Visitor chat tool: go_to_page.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Public;

use WPCortex\Chat\Tools\ToolContext;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Opens a page of the public index, or the author archive of an author with content in
 * the public index, in the visitor's browser. Offered while public_chat_navigation is on.
 */
final class GoToPage extends PublicTool {

	public function name(): string {
		return 'go_to_page';
	}

	public function label(): string {
		return __( 'Go to page', 'wp-cortex' );
	}

	/**
	 * Offered while navigation is on.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		return (bool) Settings::get( 'public_chat_navigation' );
	}

	/**
	 * What else decides whether the tool is offered.
	 */
	public function availability_note(): string {
		return __( 'Needs Visitor chat > Assistant actions > Take visitors to a page.', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Opens a published page of this website in the visitor\'s browser (the chat stays open), or the author page listing all posts of an author (pass author instead of post_id; only for authors whose author_page is true in search_site results). Call it only when the visitor explicitly asks to be taken to a page or has just confirmed your offer to take them there.';
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
					'description' => 'ID of the page, taken from search_site results.',
				),
				'author'  => array(
					'type'        => 'string',
					'description' => 'Author name, to open that author\'s page instead of a post.',
				),
			),
		);
	}

	/**
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array( 'When a page would help the visitor (for example the contact page for someone who wants to get in touch), you may offer to take them there. Call go_to_page only when the visitor explicitly asks to be taken to a page or has confirmed your offer in their last message; never open a page on your own initiative. After calling it, reply with one short sentence.' );
	}

	/**
	 * Queues the navigation.
	 *
	 * @param array       $args    Arguments: post_id or author.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$visitor = $this->visitor( $context );
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$author  = trim( (string) ( $args['author'] ?? '' ) );

		if ( $post_id < 1 && '' !== $author ) {
			return $this->go_to_author_page( $visitor, $author );
		}

		$doc = $visitor->get_public_document( $post_id );

		if ( null === $doc || '' === (string) $doc['url'] ) {
			return array( 'error' => 'No published page with this ID.' );
		}

		$title = wp_specialchars_decode( (string) $doc['title'], ENT_QUOTES );

		$visitor->navigate( $post_id, $title, (string) $doc['url'] );

		return array(
			'opened' => true,
			'title'  => $title,
			'note'   => 'The page opens right after your reply. In one short sentence, tell the visitor you are taking them to this page.',
		);
	}

	/**
	 * Opens the author archive of an author with content in the public index.
	 *
	 * @param PublicContext $visitor Turn context.
	 * @param string        $author  Author name.
	 * @return array<string, mixed>
	 */
	private function go_to_author_page( PublicContext $visitor, string $author ): array {
		$authors = $visitor->public_authors( $author );

		if ( ! $authors ) {
			return array( 'error' => 'No author with published content matches this name.' );
		}

		if ( count( $authors ) > 1 ) {
			return array(
				'error'   => 'Several authors match this name. Ask the visitor which one they mean.',
				'authors' => array_column( $authors, 'name' ),
			);
		}

		if ( '' === $authors[0]['url'] ) {
			return array( 'error' => 'This website has no author pages. Offer the list of the author\'s posts instead (search_site with author).' );
		}

		/* translators: %s: author name. */
		$title = sprintf( __( 'Author: %s', 'wp-cortex' ), $authors[0]['name'] );

		$visitor->navigate( 0, $title, $authors[0]['url'] );

		return array(
			'opened' => true,
			'title'  => $title,
			'note'   => 'The author page opens right after your reply. In one short sentence, tell the visitor you are taking them to it.',
		);
	}
}
