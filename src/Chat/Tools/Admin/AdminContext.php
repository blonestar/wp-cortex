<?php
/**
 * Turn context of the admin chat.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Admin;

use WPCortex\Admin\AdminPages;
use WPCortex\Chat\SkillStore;
use WPCortex\Chat\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * What the admin chat tools know about the turn: the screen the user is on, the posts
 * the model has seen, the UI actions for the browser and the active skills.
 */
final class AdminContext extends ToolContext {

	/**
	 * Screen context value sent by the chat panel on the front end of the site.
	 */
	public const FRONTEND_SCREEN = 'frontend';

	/**
	 * Admin screens the user can open.
	 *
	 * @var array<int, array{path: string, label: string}>
	 */
	private array $pages;

	/**
	 * Tab labels on the screen the user is viewing.
	 *
	 * @var string[]
	 */
	private array $tabs;

	/**
	 * Post IDs the model has legitimately seen.
	 *
	 * @var array<int, true>
	 */
	private array $known_ids = array();

	/**
	 * Post rows returned by tools in this turn, keyed by post ID, used for cards.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $seen = array();

	/**
	 * UI actions for the browser.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $actions = array();

	/**
	 * Constructor.
	 *
	 * @param array      $screen      Screen context sent by the browser: screen, post_id, admin_pages, tabs.
	 * @param int        $user_id     Current user.
	 * @param array      $skills      Active skills offered in this turn (empty when skills are off).
	 * @param SkillStore $skill_store Skill store.
	 */
	public function __construct( private array $screen, private int $user_id, private array $skills, private SkillStore $skill_store ) {
		$this->pages = AdminPages::sanitize( $screen['admin_pages'] ?? array() );
		$this->tabs  = AdminPages::sanitize_tabs( $screen['tabs'] ?? array() );

		if ( $this->post_id() > 0 ) {
			$this->known_ids[ $this->post_id() ] = true;
		}
	}

	public function scope(): string {
		return self::ADMIN;
	}

	/**
	 * Screen context sent by the browser.
	 *
	 * @return array<string, mixed>
	 */
	public function screen(): array {
		return $this->screen;
	}

	/**
	 * Whether the chat panel is open on the front end of the site.
	 */
	public function is_frontend(): bool {
		return self::FRONTEND_SCREEN === ( $this->screen['screen'] ?? '' );
	}

	/**
	 * Post being edited or viewed, 0 for none.
	 */
	public function post_id(): int {
		return (int) ( $this->screen['post_id'] ?? 0 );
	}

	/**
	 * Current user.
	 */
	public function user_id(): int {
		return $this->user_id;
	}

	/**
	 * Admin screens the user can open.
	 *
	 * @return array<int, array{path: string, label: string}>
	 */
	public function admin_pages(): array {
		return $this->pages;
	}

	/**
	 * Tab labels on the screen the user is viewing.
	 *
	 * @return string[]
	 */
	public function tabs(): array {
		return $this->tabs;
	}

	/**
	 * Active skills offered in this turn.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function skills(): array {
		return $this->skills;
	}

	/**
	 * Skill store.
	 */
	public function skill_store(): SkillStore {
		return $this->skill_store;
	}

	/**
	 * Whether the model has seen this post in a tool result (or it is the current post).
	 *
	 * @param int $post_id Post ID.
	 */
	public function is_known_post( int $post_id ): bool {
		return isset( $this->known_ids[ $post_id ] );
	}

	/**
	 * Remembers post IDs the model has seen.
	 *
	 * @param int[] $ids Post IDs.
	 */
	public function add_known_posts( array $ids ): void {
		foreach ( $ids as $id ) {
			$this->known_ids[ (int) $id ] = true;
		}
	}

	/**
	 * Remembers the posts in a tool response: their IDs, and their rows for cards.
	 *
	 * @param mixed $payload Tool response.
	 */
	public function remember_response( $payload ): void {
		$this->add_known_posts( self::post_ids_in( $payload ) );

		if ( ! is_array( $payload ) ) {
			return;
		}

		$rows = isset( $payload['results'] ) && is_array( $payload['results'] ) ? $payload['results'] : array();

		foreach ( (array) ( $payload['groups'] ?? array() ) as $group ) {
			$rows = array_merge( $rows, (array) ( $group['posts'] ?? array() ) );
		}

		if ( isset( $payload['id'], $payload['title'] ) ) {
			$rows[] = $payload;
		}

		foreach ( $rows as $row ) {
			$id = is_array( $row ) ? (int) ( $row['id'] ?? 0 ) : 0;

			// Rows without URLs (duplicate groups) are left to the WordPress fallback for cards.
			if ( $id < 1 || ! isset( $row['url'] ) ) {
				continue;
			}

			$card = array(
				'id'        => $id,
				'title'     => (string) ( $row['title'] ?? '' ),
				'post_type' => (string) ( $row['post_type'] ?? '' ),
				'status'    => (string) ( $row['status'] ?? '' ),
				'author'    => (string) ( $row['author'] ?? '' ),
				'url'       => (string) ( $row['url'] ?? '' ),
				'edit_url'  => (string) ( $row['edit_url'] ?? '' ),
				'snippet'   => (string) ( $row['snippet'] ?? '' ),
			);

			// Keep an earlier snippet when a later row (e.g. get-document) has none.
			if ( '' === $card['snippet'] && isset( $this->seen[ $id ] ) ) {
				$card['snippet'] = $this->seen[ $id ]['snippet'];
			}

			$this->seen[ $id ] = $card;
		}
	}

	/**
	 * Post rows returned by tools in this turn, keyed by post ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function seen(): array {
		return $this->seen;
	}

	/**
	 * Post IDs contained in a tool response (search results, duplicate groups or a single
	 * document), also in a truncated response.
	 *
	 * @param mixed $payload Tool response.
	 * @return int[]
	 */
	public static function post_ids_in( $payload ): array {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		if ( isset( $payload['partial_json'] ) && is_string( $payload['partial_json'] ) ) {
			preg_match_all( '/"id":(\d+)/', $payload['partial_json'], $matches );

			return array_map( 'intval', $matches[1] );
		}

		$ids = array();

		if ( isset( $payload['results'] ) && is_array( $payload['results'] ) ) {
			foreach ( $payload['results'] as $row ) {
				if ( isset( $row['id'] ) ) {
					$ids[] = (int) $row['id'];
				}
			}
		}

		if ( isset( $payload['groups'] ) && is_array( $payload['groups'] ) ) {
			foreach ( $payload['groups'] as $group ) {
				foreach ( (array) ( $group['posts'] ?? array() ) as $row ) {
					if ( isset( $row['id'] ) ) {
						$ids[] = (int) $row['id'];
					}
				}
			}
		}

		if ( isset( $payload['id'] ) && is_numeric( $payload['id'] ) ) {
			$ids[] = (int) $payload['id'];
		}

		return $ids;
	}

	/**
	 * Adds a navigate action. The browser can only go to one page per reply, so a later
	 * navigation (for example the model correcting itself with a more exact screen)
	 * replaces an earlier one, and with it any tab queued for that page.
	 *
	 * @param array $action The navigate action: url, title and optionally post_id and tab.
	 */
	public function navigate( array $action ): void {
		$this->actions = array_values(
			array_filter(
				$this->actions,
				static function ( array $item ): bool {
					return 'navigate' !== $item['type'];
				}
			)
		);

		$this->actions[] = array( 'type' => 'navigate' ) + $action;
	}

	/**
	 * Tab path queued for the page the browser navigates to in this reply.
	 *
	 * @return string|null Null when there is no navigation, "" when it has no tab.
	 */
	public function navigation_tab(): ?string {
		foreach ( $this->actions as $action ) {
			if ( 'navigate' === $action['type'] ) {
				return (string) ( $action['tab'] ?? '' );
			}
		}

		return null;
	}

	/**
	 * Sets the tab path opened after the navigation of this reply.
	 *
	 * @param string $tab Tab path.
	 */
	public function set_navigation_tab( string $tab ): void {
		foreach ( $this->actions as $index => $action ) {
			if ( 'navigate' === $action['type'] ) {
				$this->actions[ $index ]['tab'] = $tab;
			}
		}
	}

	/**
	 * Adds a UI action other than navigation, for example select_tab.
	 *
	 * @param array $action Action with a type.
	 */
	public function add_action( array $action ): void {
		$this->actions[] = $action;
	}

	/**
	 * UI actions for the browser.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function actions(): array {
		return $this->actions;
	}
}
