<?php
/**
 * State of one chat turn shared with the tools.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools;

use WPCortex\Search\SearchService;

defined( 'ABSPATH' ) || exit;

/**
 * Base of the admin and visitor tool contexts.
 *
 * The scope decides which index search() reads: a PublicContext only ever gets the
 * public index.
 */
abstract class ToolContext {

	public const ADMIN  = 'admin';
	public const PUBLIC = 'public';

	/**
	 * Search service of the scope, created on first use.
	 *
	 * @var SearchService|null
	 */
	private ?SearchService $search = null;

	/**
	 * Transcript items shown after the answer, keyed so a later item can replace an earlier one.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $items = array();

	/**
	 * Scope of the chat: ToolContext::ADMIN or ToolContext::PUBLIC.
	 */
	abstract public function scope(): string;

	/**
	 * Search service over the index of this scope.
	 */
	public function search(): SearchService {
		if ( null === $this->search ) {
			$this->search = SearchService::for_scope( $this->scope() );
		}

		return $this->search;
	}

	/**
	 * Adds a transcript item shown after the answer (for example a notice), or replaces
	 * the earlier item with the same key.
	 *
	 * @param string               $key  Key, for example "report:12".
	 * @param array<string, mixed> $item Item with at least a role.
	 */
	public function set_item( string $key, array $item ): void {
		$this->items[ $key ] = $item;
	}

	/**
	 * Whether an item with this key was added in this turn.
	 *
	 * @param string $key Key.
	 */
	public function has_item( string $key ): bool {
		return isset( $this->items[ $key ] );
	}

	/**
	 * Items added by the tools, in the order they were first added.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function items(): array {
		return array_values( $this->items );
	}
}
