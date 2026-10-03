# WP Cortex

WP Cortex is a memory layer for WordPress. It indexes site content into a local SQLite store (structured fields, FTS5 full-text search and vector embeddings) so that AI search and chat features can be built on top of it. Everything is stored locally; the only external call is the optional OpenAI embeddings request.

- Version: 0.2.0
- Author: Bojan
- License: GPL-2.0-or-later

> Status: the **indexing layer**, the **hybrid search service** and the **admin chat assistant** are implemented. Frontend chat and MCP access are on the [roadmap](#roadmap).

## Features (current)

- **Two isolated indexes**
  - `public.sqlite` holds only published, publicly viewable, non-password-protected content, and only fields/sections flagged as public.
  - `admin.sqlite` holds everything eligible: drafts, pending, scheduled and private posts (configurable), plus Yoast SEO, ACF and custom meta data.
- **Extractors**: core post data, taxonomies, Yoast SEO, ACF, custom meta keys. Extensible through a filter.
- **Front-end-equivalent content**: blocks (including dynamic ones) are rendered as an anonymous visitor, so the index matches what the public sees and logged-in-only content never leaks into it. Forms, navigation, scripts and styles are stripped.
- **Heading-aware chunking**: HTML is split at headings; consecutive short sections are packed together and long ones split at paragraph/sentence boundaries into chunks of roughly N characters with configurable overlap.
- **OpenAI embeddings** with reuse by chunk hash: unchanged text is never re-embedded, even across documents and scopes.
- **Batch indexing with progress UI**: resumable and cancellable (Cortex > Indexing).
- **Incremental auto-sync** via WP-Cron when content, terms or relevant meta change.
- **Hybrid search service** (`WPCortex\Search\SearchService`): FTS5 BM25 keyword search, brute-force cosine semantic search, merged with Reciprocal Rank Fusion and grouped per document. Structured filters on post type, status, modified date and any indexed field (`eq`, `neq`, `contains`, `not_contains`, `empty`, `not_empty`, `missing`, `exists`). Degrades to keyword search when embeddings are unavailable. Also offers `get_document()` and `field_catalog()`.
- **Admin chat assistant**: a floating chat panel on every admin screen for administrators. An LLM (through the WordPress AI Client, any configured provider) answers questions about site content by calling tools over the admin index, shows the posts cited in its answer (as `#ID`) as cards below it and can open a post in the editor on request. Conversations are stored per user in the `{prefix}wp_cortex_conversations` table. Needs an AI provider API key under Settings > Connectors.
- **Abilities** (WordPress Abilities API, category `wp-cortex`, read-only, `manage_options`, admin index): `wp-cortex/search-content` (hybrid search with field, status, type, author and date filters), `wp-cortex/find-duplicates` (posts sharing a title, field value or text content), `wp-cortex/get-document`, `wp-cortex/list-fields`. Used by the chat agent as tools; not exposed over REST or MCP yet.
- **WP-CLI** commands (`wp cortex index`, `wp cortex status`, `wp cortex search`).

## Requirements

- WordPress 7.0+
- PHP 8.1+
- PHP `pdo_sqlite` extension compiled with FTS5 (the plugin shows an admin notice and stays inactive if `pdo_sqlite` is missing)
- Optional: an OpenAI API key. Without one, indexing still works but no vectors are created. The key is read, in order, from the `OPENAI_API_KEY` environment variable, the `OPENAI_API_KEY` constant, or the key configured under **Settings > Connectors**.

## Installation and quick start

1. Copy the plugin into `wp-content/plugins/wp-cortex` and activate it.
2. (Recommended) Define `WP_CORTEX_DATA_DIR` in `wp-config.php` with a path outside the web root.
3. Add an OpenAI API key under Settings > Connectors (or via env/constant).
4. Open **Cortex > Settings**, choose post types and sources, and save.
5. Open **Cortex > Indexing** and click Sync (or run `wp cortex index`).

## Configuration

Settings are stored in the `wp_cortex_settings` option and edited under **Cortex > Settings**.

| Setting | Default | Description |
| --- | --- | --- |
| `post_types` | `post`, `page` | Post types to index (internal types such as attachments and templates are not offered). |
| `admin_statuses` | publish, future, draft, pending, private | Statuses included in the admin index. `publish` is always included. The public index only ever holds `publish`. |
| `auto_sync` | on | Queue changed posts and index them in the background via WP-Cron. |
| `index_yoast` | on | Index Yoast SEO fields (admin index only). Requires Yoast SEO. |
| `index_acf` | on | Index ACF fields. Requires ACF. |
| `acf_public` | off | Also put ACF text into the public index. |
| `meta_keys` | none | Extra post meta keys to index (one per line; admin index only). |
| `chunk_size` | 1200 | Approximate characters per chunk (300-6000). |
| `chunk_overlap` | 150 | Characters shared between chunks (0 to half of chunk size). |
| `batch_size` | 10 | Posts per indexing request (1-100). |
| `embeddings_enabled` | on | Generate vector embeddings. |
| `embedding_model` | `text-embedding-3-small` | Or `text-embedding-3-large`. |
| `embedding_dimensions` | 1536 | small: 512/1024/1536; large: 256/1024/3072. |
| `chat_enabled` | on | Show the admin chat assistant. |
| `chat_provider` | automatic | Registered AI provider ID (for example `openai`, `anthropic`), or empty to let the AI Client choose a configured one. |
| `chat_model` | provider default | Model ID used with the selected provider (for example `gpt-5.4-mini` or `anthropic/claude-sonnet-4.5`). Chosen from a list loaded from the provider (cached 12 h in the `wp_cortex_models_<provider>` transient, "Refresh models" button reloads it); models without tool calling are listed but disabled. Ignored when the provider is automatic. An explicit model is used as is, without the AI Client's capability matching (some providers, such as OpenRouter, do not declare tool support in their metadata), so pick one that supports tool calling. With a provider but no model, the AI Client must find a tool-capable model in the provider metadata. |
| `chat_instructions` | empty | Custom instructions (up to 4000 characters) appended to the chat system prompt on every message, for example a preferred answer language or tone. They take precedence over the default instructions (such as "answer in the language the user writes in"). |

### Data directory

By default the databases live in `wp-content/uploads/wp-cortex-<random>/`. The random directory name is generated once and stored in the `wp_cortex_data_dir_name` option. The directory receives `index.php`, `.htaccess` and `web.config` deny rules, but **Nginx ignores `.htaccess`**, so on Nginx the fallback relies only on the unguessable name. The Indexing screen warns when the fallback is used and checks over HTTP whether `admin.sqlite` is downloadable.

For real protection, put the data outside the web root:

```php
define( 'WP_CORTEX_DATA_DIR', '/var/lib/wp-cortex' );
```

The directory must be writable by the web server user. Changing the location does not move existing databases; re-run an index.

### Filters

- `wp_cortex_extractors` (`Extractor[] $extractors`): add, remove or reorder extractors. Non-`Extractor` entries are discarded.
- `wp_cortex_openai_api_key` (`string $key`): override the OpenAI API key.
- `wp_cortex_chat_system_instruction` (`string $instruction`, `array $context`): modify the chat assistant's system instruction. `$context` holds `screen` and `post_id`.

## Indexing

- **Sync** walks all eligible posts, skips unchanged ones and removes documents that are no longer eligible.
- **Rebuild** deletes both database files first and indexes everything from scratch. Use it after changing chunk settings or when the schema is damaged.
- **Skipping**: for each scope a content hash is computed over the document columns, fields and chunk hashes. If it matches the stored hash (and, when embeddings are active, no chunk lacks a vector for the current embedding signature), the post is skipped.
- **Embedding reuse**: each chunk is hashed (title, section heading, text). Existing vectors with the same hash and signature are reused; only missing ones are sent to OpenAI, in one pass per batch (96 inputs per request, up to 3 attempts on 429/5xx).
- **Stale removal**: documents not seen by a run (deleted posts, changed status, deselected post types) are deleted when the run finishes. Posts that become ineligible are also removed immediately when processed.
- **Auto-sync**: on save, trash/untrash/delete, status transition, term changes and changes of `_yoast_wpseo_*` or configured meta keys, post IDs are queued (option `wp_cortex_sync_queue`) and processed by a single WP-Cron event ~15 seconds later, 50 posts at a time. It pauses while a full run is active.
- Run state is kept in the `wp_cortex_index_run` option and shared by the admin UI, REST and WP-CLI; a lock option prevents concurrent batches (stale after 300 s).

## WP-CLI

```
wp cortex index [--rebuild]
wp cortex status
wp cortex search <query> [--scope=<admin|public>] [--mode=<hybrid|keyword|semantic>] [--limit=<n>] [--type=<post_type>]
```

`index` runs a full Sync (or Rebuild with `--rebuild`) with a progress bar and prints a summary. `status` shows per-scope statistics and the last run. `search` prints matching documents (id, type, status, score, title, best heading); scope defaults to `admin`, mode to `hybrid`.

## REST API

Namespace `wp-cortex/v1`. All routes require the `manage_options` capability (and the usual REST nonce/auth).

| Method | Route | Description |
| --- | --- | --- |
| GET | `/index` | Run state, per-scope stats, eligible post count. |
| POST | `/index/start` | Start a run. Param `mode`: `sync` (default) or `rebuild`. |
| POST | `/index/batch` | Process one batch of the running run. Response includes `locked: true` if another request holds the lock. |
| POST | `/index/cancel` | Cancel the running run. |
| GET | `/chat/conversations` | The current user's conversations: `{ conversations: [ { id, title, updated_at } ] }`. |
| GET | `/chat/conversations/<id>` | `{ id, title, transcript: [ items ] }`; 404 when not owned. |
| DELETE | `/chat/conversations/<id>` | `{ deleted: true }`. |
| GET | `/chat/models` | Params `provider` (registered provider ID), `refresh` (0/1). Returns `{ provider, models: [ { id, name, tools } ], cached }` for text-generation models, tool-capable first. 400 for unknown or unconfigured provider, 502 when the provider request fails. |
| POST | `/chat/message` | Params `conversation_id` (0 = new), `message` (max 4000 chars), `context` (`{ screen, post_id }`). Returns `{ conversation_id, title, items, actions }`. AI failures are returned as an `error` item with status 200. |

## Data model

Both databases share one schema (`PRAGMA user_version` = 1; WAL mode, foreign keys on):

- `documents`: one row per object (`object_type`, `object_id`, `subtype`, `status`, `title`, `url`, `excerpt`, author, `published_at`, `modified_at`, `content_hash`, `indexed_at`, `run_id`), unique on `(object_type, object_id)`.
- `fields`: structured key/value rows (`document_id`, `source`, `name`, `value`). Sources: `core`, `taxonomy`, `yoast`, `acf`, `meta`.
- `chunks`: `document_id`, `position`, `heading`, `content`, `content_hash`, `embedding` BLOB, `embedding_model`.
- `chunks_fts`: FTS5 virtual table (`title`, `heading`, `content`; tokenizer `unicode61 remove_diacritics 2`) with `rowid = chunks.id`.

**Vectors** are little-endian float32 BLOBs (PHP `pack( 'g*' )`), the same layout sqlite-vec uses.

**Embedding signature**: `<model>:<dimensions>` (for example `text-embedding-3-small:1536`) is stored in `chunks.embedding_model`. Changing the model or dimensions makes existing vectors stale and they are regenerated on the next run.

Yoast fields are stored even when empty, so questions such as "no meta description" are simple equality checks.

## Architecture

```
wp-cortex.php            Bootstrap, autoloader, activation hooks
uninstall.php            Removes the data directory
src/
  Plugin.php             Wires services together
  Settings.php           Typed option access, sanitizing, embedding signature
  Storage/
    Storage.php          Data directory location and protection
    Database.php         SQLite connection, schema, CRUD, stats
  Indexing/
    Document.php         Normalized object with public/admin flags
    Chunker.php          Heading-aware chunking
    Indexer.php          Pipeline: extract, chunk, embed, write
    IndexRun.php         Resumable batch run state machine
    PostSync.php         Auto-sync queue and WP-Cron worker
    Extractors/          Core, Taxonomy, Yoast, Acf, Meta extractors
  Embeddings/
    EmbeddingProvider.php  Provider interface
    OpenAIEmbeddings.php   OpenAI client
    VectorCodec.php        float32 BLOB pack/unpack
  Search/SearchService.php Hybrid search, document lookup, field catalog
  Abilities/Abilities.php  Abilities API category and read-only abilities
  Chat/
    ChatAgent.php          LLM tool-calling loop (WordPress AI Client)
    ModelCatalog.php       Provider model list (cached)
    ConversationStore.php  MySQL conversation table
  Rest/                    IndexController, ChatController
  Cli/Command.php          WP-CLI commands
  Admin/                   Menu, Settings page, Indexing page, Chat panel
assets/                  Admin JS (no build step) and CSS
```

## Roadmap

None of the following is implemented yet.

- **Frontend chat with content**: block/widget with citations, backed by the public index.
- **MCP access for external agents** via the WordPress Abilities API and MCP Adapter: public tools use the public index; admin tools use the admin index and are capability-gated.
- **Optional sqlite-vec acceleration** for vector search.

## License

GPL-2.0-or-later.
