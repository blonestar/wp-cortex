<?php
/**
 * Hybrid search over an index scope.
 *
 * @package WPCortex
 */

namespace WPCortex\Search;

use PDO;
use WPCortex\Embeddings\EmbeddingProvider;
use WPCortex\Embeddings\OpenAIEmbeddings;
use WPCortex\Embeddings\VectorCodec;
use WPCortex\Settings;
use WPCortex\Storage\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Keyword (FTS5 BM25), semantic (cosine similarity) and hybrid (Reciprocal Rank Fusion)
 * search over one scope's SQLite index, combined with structured filters on documents
 * and the `fields` table. Results are grouped per document.
 */
final class SearchService {

	private const RRF_K = 60;

	private const MODES = array( 'hybrid', 'keyword', 'semantic' );

	private const OPS = array( 'eq', 'neq', 'contains', 'not_contains', 'empty', 'not_empty', 'missing', 'exists' );

	private const SNIPPET_LENGTH = 300;

	private const MAX_TERMS = 32;

	/**
	 * Constructor.
	 *
	 * @param Database               $db         Index database.
	 * @param EmbeddingProvider|null $embeddings Embedding provider, or null for keyword-only search.
	 */
	public function __construct( private Database $db, private ?EmbeddingProvider $embeddings = null ) {}

	/**
	 * Service for a scope, wired with the OpenAI provider when embeddings are enabled and configured.
	 *
	 * @param string $scope 'public' or 'admin'.
	 */
	public static function for_scope( string $scope ): self {
		$provider = null;

		if ( Settings::get( 'embeddings_enabled' ) ) {
			$openai   = new OpenAIEmbeddings();
			$provider = $openai->is_configured() ? $openai : null;
		}

		return new self( Database::get( $scope ), $provider );
	}

	/**
	 * Searches the index.
	 *
	 * Scores: keyword = negated BM25, semantic = cosine similarity, hybrid = RRF score,
	 * structured-only queries = 0. Scores are only comparable within one result list.
	 *
	 * @param string $query Free-text query (may be empty for a structured-only query).
	 * @param array  $args  Optional: limit, mode, post_types, statuses, author, author_id, filters, modified_after, modified_before.
	 * @return array<int, array{id: int, object_type: string, post_type: string, status: string, title: string, url: string, author: string, modified_at: string, score: float, heading: string, snippet: string}>
	 */
	public function search( string $query, array $args = array() ): array {
		$limit = max( 1, min( 50, (int) ( $args['limit'] ?? 10 ) ) );
		$mode  = in_array( $args['mode'] ?? '', self::MODES, true ) ? $args['mode'] : 'hybrid';
		$query = trim( $query );
		$where = $this->build_where( $args );
		$terms = $this->terms( $query );

		if ( ! $terms ) {
			return $this->structured( $where, $limit );
		}

		$pool    = max( $limit * 5, 50 );
		$vector  = 'keyword' === $mode ? null : $this->embed_query( $query );
		$keyword = 'semantic' === $mode && null !== $vector ? array() : $this->keyword( $terms, $where, $pool );
		$ranked  = array();

		if ( null === $vector ) {
			foreach ( $keyword as $row ) {
				$ranked[] = $row + array( 'score' => -$row['bm25'] );
			}
		} else {
			$semantic = $this->semantic( $vector, $where, $pool );

			if ( 'semantic' === $mode ) {
				$ranked = $semantic;
			} else {
				$ranked = $this->fuse( $keyword, $semantic );
			}
		}

		return $this->group( $ranked, $terms, $limit );
	}

	/**
	 * Indexed document with its fields and chunks.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array<string, mixed>|null Null when the post is not indexed.
	 */
	public function get_document( int $post_id ): ?array {
		$pdo  = $this->db->pdo();
		$stmt = $pdo->prepare( "SELECT * FROM documents WHERE object_type = 'post' AND object_id = ?" );
		$stmt->execute( array( $post_id ) );
		$doc = $stmt->fetch();

		if ( ! $doc ) {
			return null;
		}

		$stmt = $pdo->prepare( 'SELECT source, name, value FROM fields WHERE document_id = ? ORDER BY source, name, rowid' );
		$stmt->execute( array( $doc['id'] ) );
		$fields = $stmt->fetchAll();

		$stmt = $pdo->prepare( 'SELECT position, heading, content FROM chunks WHERE document_id = ? ORDER BY position' );
		$stmt->execute( array( $doc['id'] ) );
		$chunks = $stmt->fetchAll();

		$doc['id']        = (int) $doc['id'];
		$doc['object_id'] = (int) $doc['object_id'];
		unset( $doc['content_hash'], $doc['run_id'] );

		foreach ( $chunks as &$chunk ) {
			$chunk['position'] = (int) $chunk['position'];
		}
		unset( $chunk );

		return $doc + array(
			'fields' => $fields,
			'chunks' => $chunks,
		);
	}

	/**
	 * Groups of documents sharing the same title, field value or content.
	 *
	 * Values are compared case-insensitively with collapsed whitespace; empty values
	 * are ignored. Multi-valued fields are compared as their sorted value set.
	 *
	 * @param string $by   'title', 'field' or 'content'.
	 * @param array  $args Optional: source and name (required for 'field'), post_types, statuses, author, author_id, limit (max groups).
	 * @return array{groups: array<int, array{value: string, count: int, documents: array}>, total_groups: int}
	 */
	public function find_duplicates( string $by, array $args = array() ): array {
		$limit  = max( 1, min( 100, (int) ( $args['limit'] ?? 25 ) ) );
		$where  = $this->build_where( array_intersect_key( $args, array_flip( array( 'post_types', 'statuses', 'author', 'author_id' ) ) ) );
		$pdo    = $this->db->pdo();
		$values = array(); // document_id => display value.

		if ( 'content' === $by ) {
			$stmt = $pdo->prepare( 'SELECT c.document_id, c.content FROM chunks c JOIN documents d ON d.id = c.document_id WHERE 1 = 1' . $where['sql'] . ' ORDER BY c.document_id, c.position' );
			$stmt->execute( $where['params'] );

			$current = 0;
			$context = null;
			$hashes  = array();

			// Chunks arrive ordered per document; hash them incrementally to keep memory flat.
			while ( $row = $stmt->fetch() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( (int) $row['document_id'] !== $current ) {
					if ( $context ) {
						$hashes[ $current ] = hash_final( $context );
					}
					$current = (int) $row['document_id'];
					$context = hash_init( 'md5' );
				}
				hash_update( $context, $this->normalize( (string) $row['content'] ) . "\n" );
			}
			if ( $context ) {
				$hashes[ $current ] = hash_final( $context );
			}

			$keys = $hashes;
		} else {
			if ( 'field' === $by ) {
				$source = (string) ( $args['source'] ?? '' );
				$name   = (string) ( $args['name'] ?? '' );

				if ( '' === $source || '' === $name ) {
					return array(
						'groups'       => array(),
						'total_groups' => 0,
					);
				}

				$stmt = $pdo->prepare( 'SELECT f.document_id AS id, f.value FROM fields f JOIN documents d ON d.id = f.document_id WHERE f.source = ? AND f.name = ?' . $where['sql'] );
				$stmt->execute( array_merge( array( $source, $name ), $where['params'] ) );
			} else {
				$stmt = $pdo->prepare( 'SELECT d.id, d.title AS value FROM documents d WHERE 1 = 1' . $where['sql'] );
				$stmt->execute( $where['params'] );
			}

			$sets = array();
			foreach ( $stmt->fetchAll() as $row ) {
				$value = trim( (string) preg_replace( '/\s+/u', ' ', (string) $row['value'] ) );
				if ( '' !== $value ) {
					$sets[ (int) $row['id'] ][] = $value;
				}
			}

			$keys = array();
			foreach ( $sets as $id => $set ) {
				$set = array_unique( $set );
				sort( $set );
				$values[ $id ] = implode( ', ', $set );
				$keys[ $id ]   = $this->normalize( $values[ $id ] );
			}
		}

		$groups = array();
		foreach ( $keys as $id => $key ) {
			$groups[ $key ][] = $id;
		}

		$groups = array_values( array_filter( $groups, static fn( array $ids ) => count( $ids ) > 1 ) );
		usort( $groups, static fn( array $a, array $b ) => count( $b ) <=> count( $a ) ?: $a[0] <=> $b[0] );
		$total  = count( $groups );
		$groups = array_slice( $groups, 0, $limit );

		$docs = array();
		$ids  = array_merge( array(), ...$groups );
		if ( $ids ) {
			$placeholder = implode( ',', array_fill( 0, count( $ids ), '?' ) );
			$stmt        = $pdo->prepare( "SELECT * FROM documents WHERE id IN ($placeholder) ORDER BY modified_at DESC, id DESC" );
			$stmt->execute( $ids );
			foreach ( $stmt->fetchAll() as $row ) {
				$docs[ (int) $row['id'] ] = $row;
			}
		}

		$result = array();
		foreach ( $groups as $group ) {
			$documents = array();
			foreach ( array_keys( $docs ) as $id ) {
				if ( in_array( $id, $group, true ) ) {
					$documents[] = $this->result( $docs[ $id ], 0.0, '', '' );
				}
			}

			$first    = $group[0];
			$result[] = array(
				'value'     => 'content' === $by ? '' : (string) ( $values[ $first ] ?? '' ),
				'count'     => count( $group ),
				'documents' => $documents,
			);
		}

		return array(
			'groups'       => $result,
			'total_groups' => $total,
		);
	}

	/**
	 * Lowercased text with collapsed whitespace, for duplicate comparison.
	 *
	 * @param string $text Text.
	 */
	private function normalize( string $text ): string {
		return mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ) );
	}

	/**
	 * Distinct field names so a caller can discover what can be filtered on.
	 *
	 * @return array<int, array{source: string, name: string, documents: int, rows: int}>
	 */
	public function field_catalog(): array {
		$rows = $this->db->pdo()->query( 'SELECT source, name, COUNT(DISTINCT document_id) AS documents, COUNT(*) AS rows FROM fields GROUP BY source, name ORDER BY source, name' )->fetchAll();

		return array_map(
			static fn( array $row ) => array(
				'source'    => (string) $row['source'],
				'name'      => (string) $row['name'],
				'documents' => (int) $row['documents'],
				'rows'      => (int) $row['rows'],
			),
			$rows
		);
	}

	/**
	 * Structured-only query, newest first.
	 *
	 * @param array{sql: string, params: array} $where Filter clause.
	 * @param int                               $limit Max results.
	 * @return array<int, array<string, mixed>>
	 */
	private function structured( array $where, int $limit ): array {
		$stmt = $this->db->pdo()->prepare( 'SELECT d.* FROM documents d WHERE 1 = 1' . $where['sql'] . ' ORDER BY d.modified_at DESC, d.id DESC LIMIT ' . (int) $limit );
		$stmt->execute( $where['params'] );

		$results = array();
		foreach ( $stmt->fetchAll() as $row ) {
			$results[] = $this->result( $row, 0.0, '', $this->excerpt( (string) $row['excerpt'], array() ) );
		}

		return $results;
	}

	/**
	 * FTS5 chunk search with BM25 ranking. Tries AND of all terms, then OR.
	 *
	 * @param string[]                          $terms Query terms.
	 * @param array{sql: string, params: array} $where Filter clause.
	 * @param int                               $pool  Max chunks.
	 * @return array<int, array{chunk_id: int, document_id: int, heading: string, snippet: string, bm25: float}>
	 */
	private function keyword( array $terms, array $where, int $pool ): array {
		$quoted = array_map( static fn( string $t ) => '"' . str_replace( '"', '""', $t ) . '"', $terms );
		$sql    = "SELECT c.id AS chunk_id, c.document_id, c.heading, snippet(chunks_fts, 2, '', '', '...', 48) AS snippet, bm25(chunks_fts, 5.0, 3.0, 1.0) AS bm25
			FROM chunks_fts
			JOIN chunks c ON c.id = chunks_fts.rowid
			JOIN documents d ON d.id = c.document_id
			WHERE chunks_fts MATCH ?" . $where['sql'] . '
			ORDER BY bm25 LIMIT ' . (int) $pool;
		$stmt   = $this->db->pdo()->prepare( $sql );

		foreach ( array( ' AND ', ' OR ' ) as $i => $glue ) {
			if ( 1 === $i && count( $quoted ) < 2 ) {
				break;
			}

			$stmt->execute( array_merge( array( implode( $glue, $quoted ) ), $where['params'] ) );
			$rows = $stmt->fetchAll();

			if ( $rows ) {
				return array_map(
					static fn( array $r ) => array(
						'chunk_id'    => (int) $r['chunk_id'],
						'document_id' => (int) $r['document_id'],
						'heading'     => (string) $r['heading'],
						'snippet'     => trim( (string) $r['snippet'] ),
						'bm25'        => (float) $r['bm25'],
					),
					$rows
				);
			}
		}

		return array();
	}

	/**
	 * Brute-force cosine similarity over chunks with a current-signature embedding.
	 *
	 * @param float[]                           $query_vector Query embedding.
	 * @param array{sql: string, params: array} $where        Filter clause.
	 * @param int                               $pool         Max chunks.
	 * @return array<int, array{chunk_id: int, document_id: int, heading: string, snippet: string, score: float}>
	 */
	private function semantic( array $query_vector, array $where, int $pool ): array {
		$norm = sqrt( array_sum( array_map( static fn( $v ) => $v * $v, $query_vector ) ) );
		if ( $norm <= 0 ) {
			return array();
		}

		$dims = count( $query_vector );
		$stmt = $this->db->pdo()->prepare(
			'SELECT c.id, c.document_id, c.embedding FROM chunks c JOIN documents d ON d.id = c.document_id
			WHERE c.embedding IS NOT NULL AND c.embedding_model = ?' . $where['sql']
		);
		$stmt->execute( array_merge( array( Settings::embedding_signature() ), $where['params'] ) );
		$stmt->bindColumn( 1, $chunk_id, PDO::PARAM_INT );
		$stmt->bindColumn( 2, $document_id, PDO::PARAM_INT );
		$stmt->bindColumn( 3, $blob, PDO::PARAM_LOB );

		$top = array(); // chunk_id => array( score, document_id ).
		while ( $stmt->fetch( PDO::FETCH_BOUND ) ) {
			if ( is_resource( $blob ) ) {
				$blob = stream_get_contents( $blob );
			}
			if ( ! is_string( $blob ) || strlen( $blob ) !== $dims * 4 ) {
				continue;
			}

			$vector = VectorCodec::unpack( $blob );
			$dot    = 0.0;
			$sum    = 0.0;
			for ( $i = 0; $i < $dims; $i++ ) {
				$dot += $query_vector[ $i ] * $vector[ $i ];
				$sum += $vector[ $i ] * $vector[ $i ];
			}
			if ( $sum <= 0 ) {
				continue;
			}

			$top[ $chunk_id ] = array( $dot / ( $norm * sqrt( $sum ) ), $document_id );

			if ( count( $top ) > $pool * 4 ) {
				uasort( $top, static fn( $a, $b ) => $b[0] <=> $a[0] );
				$top = array_slice( $top, 0, $pool, true );
			}
		}

		uasort( $top, static fn( $a, $b ) => $b[0] <=> $a[0] );
		$top = array_slice( $top, 0, $pool, true );

		if ( ! $top ) {
			return array();
		}

		$headings    = array();
		$placeholder = implode( ',', array_fill( 0, count( $top ), '?' ) );
		$h_stmt      = $this->db->pdo()->prepare( "SELECT id, heading FROM chunks WHERE id IN ($placeholder)" );
		$h_stmt->execute( array_keys( $top ) );
		foreach ( $h_stmt->fetchAll() as $row ) {
			$headings[ (int) $row['id'] ] = (string) $row['heading'];
		}

		$rows = array();
		foreach ( $top as $id => $item ) {
			$rows[] = array(
				'chunk_id'    => (int) $id,
				'document_id' => (int) $item[1],
				'heading'     => $headings[ $id ] ?? '',
				'snippet'     => '',
				'score'       => (float) $item[0],
			);
		}

		return $rows;
	}

	/**
	 * Reciprocal Rank Fusion of two ranked chunk lists.
	 *
	 * @param array $keyword  Keyword rows, best first.
	 * @param array $semantic Semantic rows, best first.
	 * @return array<int, array<string, mixed>> Fused rows, best first.
	 */
	private function fuse( array $keyword, array $semantic ): array {
		$fused = array();

		foreach ( array( $keyword, $semantic ) as $list ) {
			foreach ( array_values( $list ) as $rank => $row ) {
				$id = $row['chunk_id'];

				if ( ! isset( $fused[ $id ] ) ) {
					$fused[ $id ] = array( 'score' => 0.0 ) + $row;
				}

				$fused[ $id ]['score'] += 1 / ( self::RRF_K + $rank + 1 );
				if ( '' === $fused[ $id ]['snippet'] && '' !== $row['snippet'] ) {
					$fused[ $id ]['snippet'] = $row['snippet'];
				}
			}
		}

		uasort( $fused, static fn( $a, $b ) => $b['score'] <=> $a['score'] );

		return array_values( $fused );
	}

	/**
	 * Groups ranked chunks per document (best chunk wins) and builds results.
	 *
	 * @param array    $ranked Chunk rows with a score, best first.
	 * @param string[] $terms  Query terms (for excerpts).
	 * @param int      $limit  Max results.
	 * @return array<int, array<string, mixed>>
	 */
	private function group( array $ranked, array $terms, int $limit ): array {
		$best = array();
		foreach ( $ranked as $row ) {
			if ( ! isset( $best[ $row['document_id'] ] ) ) {
				$best[ $row['document_id'] ] = $row;
				if ( count( $best ) >= $limit ) {
					break;
				}
			}
		}

		if ( ! $best ) {
			return array();
		}

		$pdo = $this->db->pdo();

		$placeholder = implode( ',', array_fill( 0, count( $best ), '?' ) );
		$stmt        = $pdo->prepare( "SELECT * FROM documents WHERE id IN ($placeholder)" );
		$stmt->execute( array_keys( $best ) );
		$docs = array();
		foreach ( $stmt->fetchAll() as $row ) {
			$docs[ (int) $row['id'] ] = $row;
		}

		$contents    = array();
		$chunk_ids   = array_column( array_filter( $best, static fn( $r ) => '' === $r['snippet'] ), 'chunk_id' );
		if ( $chunk_ids ) {
			$placeholder = implode( ',', array_fill( 0, count( $chunk_ids ), '?' ) );
			$stmt        = $pdo->prepare( "SELECT id, content FROM chunks WHERE id IN ($placeholder)" );
			$stmt->execute( $chunk_ids );
			foreach ( $stmt->fetchAll() as $row ) {
				$contents[ (int) $row['id'] ] = (string) $row['content'];
			}
		}

		$results = array();
		foreach ( $best as $document_id => $row ) {
			if ( ! isset( $docs[ $document_id ] ) ) {
				continue;
			}

			$snippet = '' !== $row['snippet'] ? $row['snippet'] : $this->excerpt( $contents[ $row['chunk_id'] ] ?? '', $terms );
			$results[] = $this->result( $docs[ $document_id ], (float) $row['score'], $row['heading'], $snippet );
		}

		return $results;
	}

	/**
	 * Builds one result entry.
	 *
	 * @param array  $doc     Documents row.
	 * @param float  $score   Score.
	 * @param string $heading Best chunk heading.
	 * @param string $snippet Snippet.
	 * @return array<string, mixed>
	 */
	private function result( array $doc, float $score, string $heading, string $snippet ): array {
		return array(
			'id'          => (int) $doc['object_id'],
			'object_type' => (string) $doc['object_type'],
			'post_type'   => (string) $doc['subtype'],
			'status'      => (string) $doc['status'],
			'title'       => (string) $doc['title'],
			'url'         => (string) $doc['url'],
			'author'      => (string) $doc['author_name'],
			'modified_at' => (string) $doc['modified_at'],
			'score'       => $score,
			'heading'     => $heading,
			'snippet'     => $snippet,
		);
	}

	/**
	 * Text excerpt of roughly SNIPPET_LENGTH characters around the first matched term.
	 *
	 * @param string   $text  Source text.
	 * @param string[] $terms Query terms.
	 */
	private function excerpt( string $text, array $terms ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( mb_strlen( $text ) <= self::SNIPPET_LENGTH ) {
			return $text;
		}

		$start = 0;
		foreach ( $terms as $term ) {
			$pos = mb_stripos( $text, $term );
			if ( false !== $pos ) {
				$start = max( 0, $pos - 80 );
				break;
			}
		}

		return ( $start > 0 ? '...' : '' ) . trim( mb_substr( $text, $start, self::SNIPPET_LENGTH ) ) . '...';
	}

	/**
	 * Query terms: whitespace-separated tokens containing at least one letter or digit.
	 *
	 * @param string $query Raw query.
	 * @return string[]
	 */
	private function terms( string $query ): array {
		$terms = array();
		foreach ( preg_split( '/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $token ) {
			if ( preg_match( '/[\p{L}\p{N}]/u', $token ) ) {
				$terms[] = $token;
			}
		}

		return array_slice( array_values( array_unique( $terms ) ), 0, self::MAX_TERMS );
	}

	/**
	 * Embeds the query. Null when embeddings are unavailable or the request fails.
	 *
	 * @param string $query Query text.
	 * @return float[]|null
	 */
	private function embed_query( string $query ): ?array {
		if ( ! $this->embeddings || ! $this->embeddings->is_configured() ) {
			return null;
		}

		try {
			$vectors = $this->embeddings->embed( array( $query ) );
		} catch ( \Throwable $e ) {
			return null;
		}

		return ! empty( $vectors[0] ) ? array_values( $vectors[0] ) : null;
	}

	/**
	 * Builds the structured filter clause on the documents alias `d`. All values are bound.
	 *
	 * @param array $args Search arguments.
	 * @return array{sql: string, params: array}
	 */
	private function build_where( array $args ): array {
		$sql    = '';
		$params = array();

		foreach ( array(
			'post_types' => 'subtype',
			'statuses'   => 'status',
		) as $key => $column ) {
			$values = array_values( array_filter( array_map( 'strval', (array) ( $args[ $key ] ?? array() ) ), static fn( $v ) => '' !== $v ) );
			if ( $values ) {
				$sql   .= " AND d.$column IN (" . implode( ',', array_fill( 0, count( $values ), '?' ) ) . ')';
				$params = array_merge( $params, $values );
			}
		}

		$author = trim( (string) ( $args['author'] ?? '' ) );
		if ( '' !== $author ) {
			// Case-insensitive substring match on the author display name.
			$sql     .= " AND d.author_name LIKE ? ESCAPE '\\'";
			$params[] = '%' . addcslashes( $author, '\\%_' ) . '%';
		}

		$author_id = (int) ( $args['author_id'] ?? 0 );
		if ( $author_id > 0 ) {
			$sql     .= ' AND d.author_id = ?';
			$params[] = $author_id;
		}

		foreach ( array(
			'modified_after'  => array( '>=', ' 00:00:00' ),
			'modified_before' => array( '<=', ' 23:59:59' ),
		) as $key => $def ) {
			$date = (string) ( $args[ $key ] ?? '' );
			if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				$sql     .= " AND d.modified_at {$def[0]} ?";
				$params[] = $date . $def[1];
			}
		}

		foreach ( (array) ( $args['filters'] ?? array() ) as $filter ) {
			if ( ! is_array( $filter ) || ! in_array( $filter['op'] ?? '', self::OPS, true ) || '' === (string) ( $filter['name'] ?? '' ) ) {
				continue;
			}

			$op     = $filter['op'];
			$value  = (string) ( $filter['value'] ?? '' );
			$scope  = ' f.document_id = d.id AND f.name = ?';
			$fparam = array( (string) $filter['name'] );

			if ( '' !== (string) ( $filter['source'] ?? '' ) ) {
				$scope   .= ' AND f.source = ?';
				$fparam[] = (string) $filter['source'];
			}

			$like = '%' . addcslashes( $value, '\\%_' ) . '%';

			switch ( $op ) {
				case 'eq':
					$sql   .= " AND EXISTS (SELECT 1 FROM fields f WHERE $scope AND f.value = ?)";
					$params = array_merge( $params, $fparam, array( $value ) );
					break;
				case 'neq':
					$sql   .= " AND NOT EXISTS (SELECT 1 FROM fields f WHERE $scope AND f.value = ?)";
					$params = array_merge( $params, $fparam, array( $value ) );
					break;
				case 'contains':
					$sql   .= " AND EXISTS (SELECT 1 FROM fields f WHERE $scope AND f.value LIKE ? ESCAPE '\\')";
					$params = array_merge( $params, $fparam, array( $like ) );
					break;
				case 'not_contains':
					$sql   .= " AND NOT EXISTS (SELECT 1 FROM fields f WHERE $scope AND f.value LIKE ? ESCAPE '\\')";
					$params = array_merge( $params, $fparam, array( $like ) );
					break;
				case 'empty':
					// A field row exists and none of its values has content.
					$sql   .= " AND EXISTS (SELECT 1 FROM fields f WHERE $scope) AND NOT EXISTS (SELECT 1 FROM fields f WHERE $scope AND TRIM(f.value) <> '')";
					$params = array_merge( $params, $fparam, $fparam );
					break;
				case 'not_empty':
					$sql   .= " AND EXISTS (SELECT 1 FROM fields f WHERE $scope AND TRIM(f.value) <> '')";
					$params = array_merge( $params, $fparam );
					break;
				case 'missing':
					$sql   .= " AND NOT EXISTS (SELECT 1 FROM fields f WHERE $scope)";
					$params = array_merge( $params, $fparam );
					break;
				case 'exists':
					$sql   .= " AND EXISTS (SELECT 1 FROM fields f WHERE $scope)";
					$params = array_merge( $params, $fparam );
					break;
			}
		}

		return array(
			'sql'    => $sql,
			'params' => $params,
		);
	}
}
