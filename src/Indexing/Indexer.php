<?php
/**
 * Indexing pipeline.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing;

use WP_Post;
use WPCortex\Embeddings\EmbeddingProvider;
use WPCortex\Embeddings\OpenAIEmbeddings;
use WPCortex\Embeddings\VectorCodec;
use WPCortex\Indexing\Extractors\AcfExtractor;
use WPCortex\Indexing\Extractors\CoreExtractor;
use WPCortex\Indexing\Extractors\Extractor;
use WPCortex\Indexing\Extractors\MetaExtractor;
use WPCortex\Indexing\Extractors\TaxonomyExtractor;
use WPCortex\Indexing\Extractors\YoastExtractor;
use WPCortex\Settings;
use WPCortex\Storage\Database;
use WPCortex\Storage\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Turns posts into documents and chunks, embeds them in a single call per batch and
 * writes them to the public and admin databases.
 */
final class Indexer {

	private EmbeddingProvider $embeddings;

	/**
	 * Extractors that contribute to every document.
	 *
	 * @var Extractor[]
	 */
	private array $extractors;

	private Chunker $chunker;

	/**
	 * Indexer constructor.
	 *
	 * @param EmbeddingProvider|null $embeddings Embedding provider; defaults to OpenAI.
	 */
	public function __construct( ?EmbeddingProvider $embeddings = null ) {
		$this->embeddings = $embeddings ?? new OpenAIEmbeddings();

		$extractors = array(
			new CoreExtractor(),
			new TaxonomyExtractor(),
			new YoastExtractor(),
			new AcfExtractor(),
			new MetaExtractor(),
		);

		/**
		 * Filters the extractors used to build documents.
		 *
		 * @param Extractor[] $extractors Extractor instances.
		 */
		$this->extractors = array_values( array_filter( (array) apply_filters( 'wp_cortex_extractors', $extractors ), static fn( $e ) => $e instanceof Extractor ) );

		$this->chunker = new Chunker( (int) Settings::get( 'chunk_size' ), (int) Settings::get( 'chunk_overlap' ) );
	}

	/**
	 * Number of posts that belong in the admin index.
	 */
	public static function count_eligible(): int {
		global $wpdb;

		$where = self::eligible_post_where();
		if ( '' === $where ) {
			return 0;
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Next eligible post IDs after a cursor, in ascending order.
	 *
	 * @param int $after_id Last processed post ID.
	 * @param int $limit    Maximum number of IDs.
	 * @return int[]
	 */
	public static function next_ids( int $after_id, int $limit ): array {
		global $wpdb;

		$where = self::eligible_post_where();
		if ( '' === $where ) {
			return array();
		}

		$sql = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE $where AND ID > %d ORDER BY ID ASC LIMIT %d", $after_id, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Indexes posts into both scopes, removing the ones that are no longer eligible.
	 *
	 * @param int[]       $post_ids Post IDs.
	 * @param string|null $run_id   Index run ID; marks documents as seen by the run.
	 * @return array{indexed: int, skipped: int, removed: int, failed: int, embedded_chunks: int, tokens: int, errors: array<int, array{post_id: int, message: string}>}
	 */
	public function index_posts( array $post_ids, ?string $run_id = null ): array {
		$result = array(
			'indexed'         => 0,
			'skipped'         => 0,
			'removed'         => 0,
			'failed'          => 0,
			'embedded_chunks' => 0,
			'tokens'          => 0,
			'errors'          => array(),
		);

		$tokens_before = $this->embeddings instanceof OpenAIEmbeddings ? $this->embeddings->tokens_used : 0;
		$signature     = Settings::embedding_signature();
		$wanted        = (bool) Settings::get( 'embeddings_enabled' );
		$active        = $wanted && $this->embeddings->is_configured();

		if ( $wanted && ! $active ) {
			$result['errors'][] = array(
				'post_id' => 0,
				'message' => 'OpenAI API key is not configured; documents were indexed without embeddings.',
			);
		}

		// Phase 1: build documents and decide what has to be written.
		$prepared = array();

		foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $post_id ) {
			try {
				$writes = $this->prepare_post( $post_id, $run_id, $active, $signature, $result );
				if ( $writes ) {
					$prepared[ $post_id ] = $writes;
				}
			} catch ( \Throwable $e ) {
				++$result['failed'];
				$result['errors'][] = array(
					'post_id' => $post_id,
					'message' => $e->getMessage(),
				);
			}
		}

		// Phase 2: one embedding call for every chunk text that has no stored vector yet.
		$vectors = array();

		if ( $active && $prepared ) {
			try {
				$vectors = $this->embed_chunks( $prepared, $signature, $result );
			} catch ( \Throwable $e ) {
				$result['errors'][] = array(
					'post_id' => 0,
					'message' => $e->getMessage(),
				);
			}
		}

		// Phase 3: write.
		foreach ( $prepared as $post_id => $writes ) {
			try {
				foreach ( $writes as $scope => $write ) {
					$chunks = array();

					foreach ( $write['chunks'] as $chunk ) {
						$chunks[] = array(
							'heading'   => $chunk['heading'],
							'content'   => $chunk['content'],
							'hash'      => $chunk['hash'],
							'embedding' => $vectors[ $chunk['hash'] ] ?? null,
						);
					}

					Database::get( $scope )->save_document( $write['doc'], $chunks, $write['hash'], $signature, $run_id );
				}
				++$result['indexed'];
			} catch ( \Throwable $e ) {
				++$result['failed'];
				$result['errors'][] = array(
					'post_id' => $post_id,
					'message' => $e->getMessage(),
				);
			}
		}

		if ( $this->embeddings instanceof OpenAIEmbeddings ) {
			$result['tokens'] = $this->embeddings->tokens_used - $tokens_before;
		}

		return $result;
	}

	/**
	 * Builds the documents of one post for every scope that needs a write.
	 *
	 * Removes the post from scopes it is not eligible for and counts it as skipped
	 * when every eligible scope is up to date.
	 *
	 * @param int         $post_id   Post ID.
	 * @param string|null $run_id    Index run ID.
	 * @param bool        $active    Whether embeddings will be generated.
	 * @param string      $signature Embedding signature.
	 * @param array       $result    Result counters (modified).
	 * @return array<string, array{doc: Document, hash: string, chunks: array<int, array{heading: string, content: string, hash: string}>}> Writes by scope.
	 */
	private function prepare_post( int $post_id, ?string $run_id, bool $active, string $signature, array &$result ): array {
		$post = get_post( $post_id );

		$admin_ok  = $post instanceof WP_Post
			&& in_array( $post->post_type, Settings::post_types(), true )
			&& in_array( $post->post_status, Settings::admin_statuses(), true );
		$public_ok = $admin_ok
			&& 'publish' === $post->post_status
			&& '' === (string) $post->post_password
			&& is_post_type_viewable( $post->post_type );

		$eligible = array(
			Storage::SCOPE_PUBLIC => $public_ok,
			Storage::SCOPE_ADMIN  => $admin_ok,
		);

		foreach ( $eligible as $scope => $ok ) {
			if ( ! $ok && Database::get( $scope )->delete_document( 'post', $post_id ) ) {
				++$result['removed'];
			}
		}

		if ( ! $admin_ok ) {
			return array();
		}

		$full = new Document();
		foreach ( $this->extractors as $extractor ) {
			if ( $extractor->is_available() ) {
				$extractor->extract( $post, $full );
			}
		}

		$writes = array();

		foreach ( array_keys( array_filter( $eligible ) ) as $scope ) {
			$doc    = $full->for_scope( Storage::SCOPE_PUBLIC === $scope );
			$chunks = array();

			foreach ( $this->chunker->chunk( $doc ) as $chunk ) {
				$text = 'Title: ' . $doc->title . "\n";
				if ( '' !== $chunk['heading'] ) {
					$text .= 'Section: ' . $chunk['heading'] . "\n";
				}
				$text .= "\n" . $chunk['content'];

				$chunks[] = array(
					'heading' => $chunk['heading'],
					'content' => $chunk['content'],
					'text'    => $text,
					'hash'    => md5( $text ),
				);
			}

			$hash = md5(
				serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					array(
						$doc->object_type,
						$doc->object_id,
						$doc->subtype,
						$doc->status,
						$doc->title,
						$doc->url,
						$doc->excerpt,
						$doc->author_id,
						$doc->author_name,
						$doc->published_at,
						$doc->modified_at,
						$doc->fields,
						array_column( $chunks, 'hash' ),
					)
				)
			);

			$database = Database::get( $scope );
			$existing = $database->find_document( $doc->object_type, $doc->object_id );

			if ( $existing && $existing['content_hash'] === $hash && ( ! $active || 0 === $database->count_missing_embeddings( $existing['id'], $signature ) ) ) {
				if ( null !== $run_id ) {
					$database->touch( $existing['id'], $run_id );
				}
				continue;
			}

			$writes[ $scope ] = array(
				'doc'    => $doc,
				'hash'   => $hash,
				'chunks' => $chunks,
			);
		}

		if ( ! $writes ) {
			++$result['skipped'];
		}

		return $writes;
	}

	/**
	 * Generates (or reuses) vectors for all chunks of the prepared writes in one provider call.
	 *
	 * @param array  $prepared  Writes by post and scope.
	 * @param string $signature Embedding signature.
	 * @param array  $result    Result counters (modified).
	 * @return array<string, string> Chunk hash => packed vector.
	 * @throws \Throwable When the provider fails.
	 */
	private function embed_chunks( array $prepared, string $signature, array &$result ): array {
		$texts = array();

		foreach ( $prepared as $writes ) {
			foreach ( $writes as $write ) {
				foreach ( $write['chunks'] as $chunk ) {
					$texts[ $chunk['hash'] ] = $chunk['text'];
				}
			}
		}

		$vectors = array();
		foreach ( Storage::SCOPES as $scope ) {
			$vectors += Database::get( $scope )->find_embeddings( array_keys( $texts ), $signature );
		}

		$missing = array_diff_key( $texts, $vectors );
		if ( ! $missing ) {
			return $vectors;
		}

		$hashes  = array_keys( $missing );
		$created = $this->embeddings->embed( array_values( $missing ) );

		foreach ( $hashes as $i => $hash ) {
			if ( isset( $created[ $i ] ) ) {
				$vectors[ $hash ] = VectorCodec::pack( $created[ $i ] );
				++$result['embedded_chunks'];
			}
		}

		return $vectors;
	}

	/**
	 * Removes a post from both scopes.
	 *
	 * @param int $post_id Post ID.
	 */
	public function remove_post( int $post_id ): void {
		foreach ( Storage::SCOPES as $scope ) {
			Database::get( $scope )->delete_document( 'post', $post_id );
		}
	}

	/**
	 * SQL condition (without WHERE) matching posts that belong in the admin index.
	 *
	 * @return string Empty string when no post type is selected.
	 */
	private static function eligible_post_where(): string {
		global $wpdb;

		$types    = Settings::post_types();
		$statuses = Settings::admin_statuses();

		if ( ! $types || ! $statuses ) {
			return '';
		}

		$type_sql   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$status_sql = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return $wpdb->prepare( "post_type IN ($type_sql) AND post_status IN ($status_sql)", array_merge( $types, $statuses ) );
	}
}
