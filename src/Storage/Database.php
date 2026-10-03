<?php
/**
 * SQLite index store.
 *
 * @package WPCortex
 */

namespace WPCortex\Storage;

use PDO;
use WPCortex\Indexing\Document;

defined( 'ABSPATH' ) || exit;

/**
 * One SQLite database per scope (public / admin), with the same schema:
 *
 * - documents:  one row per indexed object (core columns).
 * - fields:     structured key/value data (taxonomies, Yoast, ACF, meta) for exact filtering.
 * - chunks:     text chunks with their embedding vectors (float32 BLOB).
 * - chunks_fts: FTS5 full-text index over chunks (rowid = chunks.id).
 */
final class Database {

	private const SCHEMA_VERSION = 1;

	/**
	 * Open connections, keyed by scope.
	 *
	 * @var array<string, self>
	 */
	private static array $instances = array();

	private PDO $pdo;

	/**
	 * Opens a connection.
	 *
	 * @param string $scope One of Storage::SCOPES.
	 */
	private function __construct( private string $scope ) {
		Storage::ensure_data_dir();

		$this->pdo = new PDO(
			'sqlite:' . Storage::db_path( $scope ),
			null,
			null,
			array(
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			)
		);

		$this->pdo->exec( 'PRAGMA journal_mode = WAL' );
		$this->pdo->exec( 'PRAGMA synchronous = NORMAL' );
		$this->pdo->exec( 'PRAGMA busy_timeout = 5000' );
		$this->pdo->exec( 'PRAGMA foreign_keys = ON' );

		$this->migrate();
	}

	/**
	 * Shared connection for a scope.
	 *
	 * @param string $scope One of Storage::SCOPES.
	 */
	public static function get( string $scope ): self {
		if ( ! in_array( $scope, Storage::SCOPES, true ) ) {
			throw new \InvalidArgumentException( "Unknown index scope: $scope" );
		}

		return self::$instances[ $scope ] ??= new self( $scope );
	}

	/**
	 * Closes a scope's connection (needed before deleting the file).
	 *
	 * @param string $scope One of Storage::SCOPES.
	 */
	public static function close( string $scope ): void {
		unset( self::$instances[ $scope ] );
	}

	public function scope(): string {
		return $this->scope;
	}

	public function pdo(): PDO {
		return $this->pdo;
	}

	/**
	 * Existing document row (id + hash) or null.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return array{id: int, content_hash: string}|null
	 */
	public function find_document( string $object_type, int $object_id ): ?array {
		$stmt = $this->pdo->prepare( 'SELECT id, content_hash FROM documents WHERE object_type = ? AND object_id = ?' );
		$stmt->execute( array( $object_type, $object_id ) );
		$row = $stmt->fetch();

		return $row ? array(
			'id'           => (int) $row['id'],
			'content_hash' => (string) $row['content_hash'],
		) : null;
	}

	/**
	 * Number of a document's chunks lacking a vector for the current embedding configuration.
	 *
	 * @param int    $document_id Document row ID.
	 * @param string $signature   Embedding signature.
	 */
	public function count_missing_embeddings( int $document_id, string $signature ): int {
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM chunks WHERE document_id = ? AND (embedding IS NULL OR embedding_model IS NOT ?)' );
		$stmt->execute( array( $document_id, $signature ) );

		return (int) $stmt->fetchColumn();
	}

	/**
	 * Marks an unchanged document as seen by the given run.
	 *
	 * @param int    $document_id Document row ID.
	 * @param string $run_id      Index run ID.
	 */
	public function touch( int $document_id, string $run_id ): void {
		$this->pdo->prepare( 'UPDATE documents SET run_id = ? WHERE id = ?' )->execute( array( $run_id, $document_id ) );
	}

	/**
	 * Stored vectors by chunk hash, so unchanged text is never sent for embedding twice.
	 *
	 * @param string[] $hashes    Chunk content hashes.
	 * @param string   $signature Embedding signature.
	 * @return array<string, string> Hash => packed vector.
	 */
	public function find_embeddings( array $hashes, string $signature ): array {
		$found = array();

		foreach ( array_chunk( array_values( array_unique( $hashes ) ), 500 ) as $batch ) {
			$placeholders = implode( ',', array_fill( 0, count( $batch ), '?' ) );
			$stmt         = $this->pdo->prepare( "SELECT content_hash, embedding FROM chunks WHERE embedding_model = ? AND embedding IS NOT NULL AND content_hash IN ($placeholders) GROUP BY content_hash" );
			$stmt->execute( array_merge( array( $signature ), $batch ) );

			foreach ( $stmt->fetchAll() as $row ) {
				$found[ $row['content_hash'] ] = $row['embedding'];
			}
		}

		return $found;
	}

	/**
	 * Inserts or replaces a document with its fields and chunks.
	 *
	 * @param Document                                                                                   $doc       Document.
	 * @param array<int, array{heading: string, content: string, hash: string, embedding: string|null}> $chunks    Chunks.
	 * @param string                                                                                     $hash      Document content hash.
	 * @param string                                                                                     $signature Embedding signature.
	 * @param string|null                                                                                $run_id    Index run ID.
	 */
	public function save_document( Document $doc, array $chunks, string $hash, string $signature, ?string $run_id ): void {
		$this->pdo->beginTransaction();

		try {
			$existing = $this->find_document( $doc->object_type, $doc->object_id );
			if ( $existing ) {
				$this->delete_rows( $existing['id'] );
			}

			$this->pdo->prepare(
				'INSERT INTO documents (object_type, object_id, subtype, status, title, url, excerpt, author_id, author_name, published_at, modified_at, content_hash, indexed_at, run_id)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
			)->execute(
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
					$hash,
					gmdate( 'Y-m-d H:i:s' ),
					$run_id,
				)
			);
			$document_id = (int) $this->pdo->lastInsertId();

			$field_stmt = $this->pdo->prepare( 'INSERT INTO fields (document_id, source, name, value) VALUES (?, ?, ?, ?)' );
			foreach ( $doc->fields as $field ) {
				$field_stmt->execute( array( $document_id, $field['source'], $field['name'], $field['value'] ) );
			}

			$chunk_stmt = $this->pdo->prepare( 'INSERT INTO chunks (document_id, position, heading, content, content_hash, embedding, embedding_model) VALUES (?, ?, ?, ?, ?, ?, ?)' );
			$fts_stmt   = $this->pdo->prepare( 'INSERT INTO chunks_fts (rowid, title, heading, content) VALUES (?, ?, ?, ?)' );

			foreach ( $chunks as $position => $chunk ) {
				$chunk_stmt->bindValue( 1, $document_id, PDO::PARAM_INT );
				$chunk_stmt->bindValue( 2, $position, PDO::PARAM_INT );
				$chunk_stmt->bindValue( 3, $chunk['heading'] );
				$chunk_stmt->bindValue( 4, $chunk['content'] );
				$chunk_stmt->bindValue( 5, $chunk['hash'] );
				$chunk_stmt->bindValue( 6, $chunk['embedding'], null === $chunk['embedding'] ? PDO::PARAM_NULL : PDO::PARAM_LOB );
				$chunk_stmt->bindValue( 7, null === $chunk['embedding'] ? null : $signature );
				$chunk_stmt->execute();

				$fts_stmt->execute( array( (int) $this->pdo->lastInsertId(), $doc->title, $chunk['heading'], $chunk['content'] ) );
			}

			$this->pdo->commit();
		} catch ( \Throwable $e ) {
			$this->pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * Removes a document.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return bool Whether a document was removed.
	 */
	public function delete_document( string $object_type, int $object_id ): bool {
		$existing = $this->find_document( $object_type, $object_id );
		if ( ! $existing ) {
			return false;
		}

		$this->pdo->beginTransaction();
		$this->delete_rows( $existing['id'] );
		$this->pdo->commit();

		return true;
	}

	/**
	 * Removes documents not seen by the given run (deleted or no longer eligible objects).
	 *
	 * @param string $run_id Index run ID.
	 * @return int Number of removed documents.
	 */
	public function delete_stale( string $run_id ): int {
		$stmt = $this->pdo->prepare( 'SELECT id FROM documents WHERE run_id IS NOT ?' );
		$stmt->execute( array( $run_id ) );
		$ids = array_map( 'intval', $stmt->fetchAll( PDO::FETCH_COLUMN ) );

		if ( $ids ) {
			$this->pdo->beginTransaction();
			foreach ( $ids as $id ) {
				$this->delete_rows( $id );
			}
			$this->pdo->commit();
		}

		return count( $ids );
	}

	/**
	 * Index statistics.
	 *
	 * @return array<string, mixed>
	 */
	public function stats(): array {
		$q = fn( string $sql ) => $this->pdo->query( $sql );

		$by_type = array();
		foreach ( $q( 'SELECT subtype, COUNT(*) AS total FROM documents GROUP BY subtype ORDER BY total DESC' )->fetchAll() as $row ) {
			$by_type[ $row['subtype'] ] = (int) $row['total'];
		}

		$size = 0;
		foreach ( array( '', '-wal' ) as $suffix ) {
			$file  = Storage::db_path( $this->scope ) . $suffix;
			$size += file_exists( $file ) ? (int) filesize( $file ) : 0;
		}

		return array(
			'documents'   => (int) $q( 'SELECT COUNT(*) FROM documents' )->fetchColumn(),
			'chunks'      => (int) $q( 'SELECT COUNT(*) FROM chunks' )->fetchColumn(),
			'embedded'    => (int) $q( 'SELECT COUNT(*) FROM chunks WHERE embedding IS NOT NULL' )->fetchColumn(),
			'fields'      => (int) $q( 'SELECT COUNT(*) FROM fields' )->fetchColumn(),
			'by_type'     => $by_type,
			'last_update' => (string) $q( 'SELECT MAX(indexed_at) FROM documents' )->fetchColumn(),
			'size'        => $size,
		);
	}

	/**
	 * Deletes a document row with its chunks, FTS rows and fields. Caller manages the transaction.
	 *
	 * @param int $document_id Document row ID.
	 */
	private function delete_rows( int $document_id ): void {
		$this->pdo->prepare( 'DELETE FROM chunks_fts WHERE rowid IN (SELECT id FROM chunks WHERE document_id = ?)' )->execute( array( $document_id ) );
		$this->pdo->prepare( 'DELETE FROM chunks WHERE document_id = ?' )->execute( array( $document_id ) );
		$this->pdo->prepare( 'DELETE FROM fields WHERE document_id = ?' )->execute( array( $document_id ) );
		$this->pdo->prepare( 'DELETE FROM documents WHERE id = ?' )->execute( array( $document_id ) );
	}

	/**
	 * Creates or upgrades the schema, tracked with PRAGMA user_version.
	 */
	private function migrate(): void {
		$version = (int) $this->pdo->query( 'PRAGMA user_version' )->fetchColumn();

		if ( $version >= self::SCHEMA_VERSION ) {
			return;
		}

		$this->pdo->exec(
			"CREATE TABLE IF NOT EXISTS documents (
				id INTEGER PRIMARY KEY,
				object_type TEXT NOT NULL,
				object_id INTEGER NOT NULL,
				subtype TEXT NOT NULL DEFAULT '',
				status TEXT NOT NULL DEFAULT '',
				title TEXT NOT NULL DEFAULT '',
				url TEXT NOT NULL DEFAULT '',
				excerpt TEXT NOT NULL DEFAULT '',
				author_id INTEGER NOT NULL DEFAULT 0,
				author_name TEXT NOT NULL DEFAULT '',
				published_at TEXT NOT NULL DEFAULT '',
				modified_at TEXT NOT NULL DEFAULT '',
				content_hash TEXT NOT NULL,
				indexed_at TEXT NOT NULL,
				run_id TEXT,
				UNIQUE (object_type, object_id)
			);
			CREATE INDEX IF NOT EXISTS documents_subtype ON documents (subtype, status);

			CREATE TABLE IF NOT EXISTS fields (
				document_id INTEGER NOT NULL REFERENCES documents (id) ON DELETE CASCADE,
				source TEXT NOT NULL,
				name TEXT NOT NULL,
				value TEXT NOT NULL DEFAULT ''
			);
			CREATE INDEX IF NOT EXISTS fields_lookup ON fields (source, name, value);
			CREATE INDEX IF NOT EXISTS fields_document ON fields (document_id);

			CREATE TABLE IF NOT EXISTS chunks (
				id INTEGER PRIMARY KEY,
				document_id INTEGER NOT NULL REFERENCES documents (id) ON DELETE CASCADE,
				position INTEGER NOT NULL,
				heading TEXT NOT NULL DEFAULT '',
				content TEXT NOT NULL,
				content_hash TEXT NOT NULL,
				embedding BLOB,
				embedding_model TEXT
			);
			CREATE INDEX IF NOT EXISTS chunks_document ON chunks (document_id);
			CREATE INDEX IF NOT EXISTS chunks_hash ON chunks (content_hash, embedding_model);

			CREATE VIRTUAL TABLE IF NOT EXISTS chunks_fts USING fts5 (
				title, heading, content,
				tokenize = 'unicode61 remove_diacritics 2'
			);"
		);

		$this->pdo->exec( 'PRAGMA user_version = ' . self::SCHEMA_VERSION );
	}
}
