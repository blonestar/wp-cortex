# WP Cortex

WP Cortex is a memory layer for WordPress. It indexes site content into a local SQLite store (structured fields, FTS5 full-text search and vector embeddings) so that AI search and chat features can be built on top of it. Everything is stored locally; the only external call is the optional OpenAI embeddings request.

- Version: 0.3.0
- Author: Bojan
- License: GPL-2.0-or-later

> Status: the **indexing layer**, the **hybrid search service**, the **admin chat assistant** (in the admin and optionally on the front end) and the **visitor chat** are implemented. MCP access is on the [roadmap](#roadmap).

## Features (current)

- **Two isolated indexes**
  - `public.sqlite` holds only published, publicly viewable, non-password-protected content, and only fields/sections flagged as public.
  - `admin.sqlite` holds everything eligible: drafts, pending, scheduled and private posts (configurable), plus Yoast SEO, ACF and custom meta data.
- **Extractors**: core post data, taxonomies, Yoast SEO, ACF, custom meta keys, media library files. Extensible through a filter.
- **Media indexing** (optional): attachments are indexed as post type `attachment` with title, caption, alt text, description, file URL and `media` fields (MIME type, file name and size, dimensions, audio/video duration, artist, album; EXIF credit, copyright and camera in the admin index only). Media inherit the status and password protection of the post they are attached to; unattached media count as published. Image `alt_text` is stored even when empty. Yoast fields are not indexed for media.
- **Front-end-equivalent content**: blocks (including dynamic ones) are rendered as an anonymous visitor, so the index matches what the public sees and logged-in-only content never leaks into it. Forms, navigation, scripts and styles are stripped.
- **Heading-aware chunking**: HTML is split at headings; consecutive short sections are packed together and long ones split at paragraph/sentence boundaries into chunks of roughly N characters with configurable overlap.
- **OpenAI embeddings** with reuse by chunk hash: unchanged text is never re-embedded, even across documents and scopes.
- **Batch indexing with progress UI**: resumable and cancellable (Cortex > Indexing).
- **Incremental auto-sync** via WP-Cron when content, terms or relevant meta change.
- **Hybrid search service** (`WPCortex\Search\SearchService`): FTS5 BM25 keyword search, brute-force cosine semantic search, merged with Reciprocal Rank Fusion and grouped per document. Structured filters on post type, status, modified date and any indexed field (`eq`, `neq`, `contains`, `not_contains`, `empty`, `not_empty`, `missing`, `exists`). Degrades to keyword search when embeddings are unavailable. Also offers `get_document()` and `field_catalog()`.
- **Admin chat assistant**: a floating chat panel on every admin screen for administrators. An LLM (through the WordPress AI Client, any configured provider) answers questions about site content by calling tools over the admin index, shows the posts cited in its answer (as `#ID`) as cards below it and on request can open a post in the editor or go to any admin screen from the user's admin menu (optionally straight to a named tab) and switch tabs on the current screen. Conversations are stored per user in the `{prefix}wp_cortex_conversations` table. Needs an AI provider API key under Settings > Connectors.
- **Chat skills** (Cortex > Skills): saved procedures the admin chat follows, for example how to reach a settings tab or run a recurring search. Active skills are listed by name and description in the chat system prompt; the assistant loads the steps of a matching skill with its `use_skill` tool. After a multi-step task the assistant can offer to save it, and its `propose_skill` tool shows a card in the chat where the user edits, saves or dismisses the proposal: the assistant never saves a skill by itself. Resolved proposals remain saved or dismissed in the conversation history, while refining a pending proposal updates its existing card. Skills can be added, edited, activated, deactivated and deleted on the Skills screen, which also shows the source (user or assistant), the use count and the last use. Stored in the `{prefix}wp_cortex_skills` table; admin chat only, never used by the visitor chat.
- **Admin chat on the front end** (`chat_frontend`, off by default): administrators get the same chat panel on the public pages of the site. It uses the admin index and the same conversations, knows which post is being viewed and can open posts in the editor; admin screens and tabs can only be opened from the admin.
- **Visitor chat** (`public_chat_enabled`, off by default): a floating chat for visitors on the front end that answers only from the **public index**, through its own `search_site` (topic search, optionally filtered by author to list the posts someone wrote; author names match regardless of case, accents and inflected forms such as "Marka Davolija") and `get_page` tools (the abilities are not used, they read the admin index). Pages the answer is based on are linked inline and listed as sources. The conversation lives in the browser session and its previous text turns are sent with each message as the model context. Requests are anonymous (no cookies or nonce), so cached pages keep working, and are limited per client IP (`public_chat_rate_limit` messages per hour). On request the assistant opens a published page or the author page of an author with published posts (`go_to_page`, only after the visitor asks or confirms; the chat stays open) and collects the contact details of visitors who want to be contacted (`save_contact_details`, after the visitor confirms them; a confirmed name can be saved without an email or phone, and one or more website URLs can be included). Title, welcome message and custom instructions are configurable; colors can be changed through CSS custom properties on `#wp-cortex-public-chat-root` (`--wp-cortex-accent`, `--wp-cortex-accent-text`, ...). Administrators see the admin chat instead while it is shown on the front end.
- **Visitor chats** (Cortex > Visitor chats, `public_chat_log`, on by default): every visitor conversation is stored in the `{prefix}wp_cortex_visitor_chats` table, identified by a random token generated in the browser session (only its hash is stored; no cookie), with the visitor's IP address (`public_chat_store_ip`; see `public_chat_ip_header` for sites behind a proxy or CDN; addresses from other proxy headers are kept separately as unverified). The screen lists conversations with the visitor's contact details, IP, the number of messages and the last activity, with filters (unread, with contact details), search, pagination and bulk actions; the menu shows the unread count. A conversation opens with the full transcript (including the page each message was sent from, sources and opened pages) and can be marked as read or unread (opening marks it as read, a new visitor message as unread), annotated with a note for the team or deleted. **Summarize** asks the chat model for a short summary in the visitor's language (what they wanted, asked and looked at, their contact details and the open points), stored with the conversation; when the conversation continues, the screen flags the summary as outdated and **Refresh summary** regenerates it. The pages the visitor was on, was taken to and was shown are listed below the summary. **Forward by email** sends the summary, contact details, note and full transcript as plain text to up to 10 addresses; with "Include an up-to-date summary" (on by default) a missing or outdated summary is generated first, otherwise an outdated summary is sent marked as such (the form warns about it) through `wp_mail()` (so an SMTP plugin or mail connector delivers it), with Reply-To set to the visitor's email when known. Optional automatic deletion after `public_chat_retention` days (daily cron). A suggested privacy policy text is added under Settings > Privacy.
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
4. Open **Cortex > Settings** (the last item of the Cortex menu), choose post types and sources on the Content tab, and save.
5. Open **Cortex > Indexing** and click Sync (or run `wp cortex index`).

## Releases and updates

Releases are prepared and published through the GitHub Actions workflows described in [RELEASE.md](RELEASE.md). `Prepare release` creates a reviewed version-bump pull request from `main`; after it is merged, `Publish release` creates the version tag, the release ZIP and the GitHub release.

WP Cortex uses WordPress's native plugin updater with the GitHub `Update URI`. When a newer stable release contains the expected `wp-cortex-vX.Y.Z.zip` asset, it appears in the normal **Dashboard > Updates** and **Plugins** screens. The standard WordPress Update button installs that release; no GitHub credentials are needed. The release metadata is normally cached for 12 hours, while **Dashboard > Updates > Check Again** forces a fresh release check.

## Configuration

Settings are stored in the `wp_cortex_settings` option and edited under **Cortex > Settings**, grouped into the Content, Indexing, Admin chat and Visitor chat tabs.

| Setting | Default | Description |
| --- | --- | --- |
| `post_types` | `post`, `page` | Post types to index (internal types such as templates are not offered; attachments are controlled by `index_media`). |
| `admin_statuses` | publish, future, draft, pending, private | Statuses included in the admin index. `publish` is always included. The public index only ever holds `publish`. |
| `auto_sync` | on | Queue changed posts and index them in the background via WP-Cron. |
| `index_yoast` | on | Index Yoast SEO fields (admin index only). Requires Yoast SEO. |
| `index_acf` | on | Index ACF fields. Requires ACF. |
| `acf_public` | off | Also put ACF text into the public index. |
| `meta_keys` | none | Extra post meta keys to index (one per line; admin index only). |
| `index_media` | off | Index media library attachments (see Features). File contents such as PDF text are not extracted. |
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
| `chat_frontend` | off | Also show the admin chat on the front end to administrators (admin index). |
| `public_chat_enabled` | off | Show the visitor chat on the front end (public index only). |
| `public_chat_title` | "Ask a question" | Visitor chat window title (up to 60 characters). |
| `public_chat_welcome` | "Hi! Ask me anything about this website." | First message shown to visitors (up to 500 characters). |
| `public_chat_instructions` | empty | Custom instructions for the visitor chat (up to 4000 characters), separate from `chat_instructions`. |
| `public_chat_provider` | same as admin chat | AI provider of the visitor chat (Settings > Visitor chat > AI assistant). Empty follows `chat_provider`, `chat_model` and `chat_reasoning`; `auto` lets the AI Client choose a configured provider with its default model; a provider ID uses `public_chat_model` and `public_chat_reasoning`. |
| `public_chat_model` / `public_chat_reasoning` | provider / model default | Model and reasoning level of the visitor chat, chosen like `chat_model` and `chat_reasoning`; only used when `public_chat_provider` is a provider ID. Visitor chat summaries keep using the admin chat model. |
| `public_chat_rate_limit` | 20 | Visitor chat messages allowed per client IP per hour (1-1000). Administrators are not limited. Counted in `wp_cortex_rate_<hash>` transients. |
| `public_chat_log` | on | Store visitor conversations and contact details (Cortex > Visitor chats). When off, nothing is stored and contact details are not collected. |
| `public_chat_retention` | 0 | Delete visitor conversations without activity for this many days (0-3650, 0 keeps them). |
| `public_chat_navigation` | on | Let the visitor chat open a published page after the visitor asks or confirms. |
| `public_chat_store_ip` | on | Store the visitor's IP address with the conversation (and in each visitor message). |
| `public_chat_ip_header` | empty | Trusted proxy header for the visitor IP: `cf-connecting-ip`, `true-client-ip`, `x-real-ip` or `x-forwarded-for` (first address). Empty uses `REMOTE_ADDR`. Used for the message limit and the stored IP; pick one only when a proxy or CDN sets it, otherwise visitors could fake it. The settings screen shows the headers present on the current request. |
| `public_chat_contact` | on | Let the visitor chat collect contact details (requires `public_chat_log`). |
| `public_chat_accent` | `#2271b1` | Visitor chat accent color (button, header, visitor messages, links). The text on it is white or dark, whichever contrasts more. |
| `public_chat_scheme` | light | Visitor chat color scheme: `light`, `dark` or `auto` (follows the visitor's `prefers-color-scheme`). |
| `public_chat_position` | right | Corner of the visitor chat: `right` or `left` (bottom). |
| `public_chat_offset` | 20 | Distance of the visitor chat from the screen edge, in px (0-80). |
| `public_chat_launcher_size` | 56 | Size of the visitor chat button, in px (40-80). |
| `public_chat_launcher_label` | empty | Optional text next to the chat button icon (up to 30 characters); turns the button into a pill. |
| `public_chat_width` / `public_chat_height` | 380 / 560 | Visitor chat window size in px (300-600 / 400-800), capped to the screen; full width on phones. |
| `public_chat_radius` | 14 | Corner radius of the visitor chat window and messages, in px (0-28); inputs and buttons use 70% of it. |
| `public_chat_font` | system | Visitor chat font: `system` (system font stack) or `theme` (inherits the theme font). |
| `public_chat_font_size` | 14 | Visitor chat base font size, in px (12-18). |
| `chat_reasoning` | model default | Reasoning (thinking) effort, chosen next to the model and only applied when a model is selected. Levels depend on the provider: OpenAI `none`–`xhigh` (sent as `reasoning.effort`), Anthropic `low`–`max` (`output_config.effort`; the thinking mode stays at the model default), OpenRouter `none`–`xhigh` (`reasoning.effort`). Other providers show no selector unless added through the reasoning filters. A level the model does not support makes the request fail. |

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
- `wp_cortex_chat_reasoning_levels` (`string[] $levels`, `string $provider`): reasoning levels offered for a provider (lowest first).
- `wp_cortex_public_chat_system_instruction` (`string $instruction`, `array $context`): modify the visitor chat's system instruction. `$context` holds `post_id` (the page being viewed, 0 for none).
- `wp_cortex_public_chat_client_ip` (`string $ip`): client IP used for the visitor chat message limit and stored with visitor conversations. Defaults to `REMOTE_ADDR`, or the address from the header chosen in `public_chat_ip_header`; use the filter for other proxy setups.
- `wp_cortex_chat_reasoning_options` (`array $options`, `string $provider`, `string $level`): custom request options that apply a reasoning level, for providers without a built-in mapping.

## Indexing

- **Sync** walks all eligible posts, skips unchanged ones and removes documents that are no longer eligible.
- **Rebuild** deletes both database files first and indexes everything from scratch. Use it after changing chunk settings or when the schema is damaged.
- **Skipping**: for each scope a content hash is computed over the document columns, fields and chunk hashes. If it matches the stored hash (and, when embeddings are active, no chunk lacks a vector for the current embedding signature), the post is skipped.
- **Embedding reuse**: each chunk is hashed (title, section heading, text). Existing vectors with the same hash and signature are reused; only missing ones are sent to OpenAI, in one pass per batch (96 inputs per request, up to 3 attempts on 429/5xx).
- **Stale removal**: documents not seen by a run (deleted posts, changed status, deselected post types) are deleted when the run finishes. Posts that become ineligible are also removed immediately when processed.
- **Auto-sync**: on save, trash/untrash/delete, status transition, term changes and changes of `_yoast_wpseo_*` or configured meta keys (with media indexing also alt text, attachment metadata, attach/detach, and the media attached to a post whose status or password changes), post IDs are queued (option `wp_cortex_sync_queue`) and processed by a single WP-Cron event ~15 seconds later, 50 posts at a time. It pauses while a full run is active.
- Run state is kept in the `wp_cortex_index_run` option and shared by the admin UI, REST and WP-CLI; a lock option prevents concurrent batches (stale after 300 s).

## WP-CLI

```
wp cortex index [--rebuild]
wp cortex status
wp cortex search <query> [--scope=<admin|public>] [--mode=<hybrid|keyword|semantic>] [--limit=<n>] [--type=<post_type>]
```

`index` runs a full Sync (or Rebuild with `--rebuild`) with a progress bar and prints a summary. `status` shows per-scope statistics and the last run. `search` prints matching documents (id, type, status, score, title, best heading); scope defaults to `admin`, mode to `hybrid`.

## REST API

Namespace `wp-cortex/v1`. All routes require the `manage_options` capability (and the usual REST nonce/auth), except `POST /public-chat/message`, which is open while the visitor chat is enabled.

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
| POST | `/public-chat/message` | Visitor chat. Params `message` (max 2000 chars), `history` (previous turns `[ { role: user\|assistant, text } ]`, the last 12 are used), `post_id` (page being viewed), `session` (32 hex chars, identifies the stored conversation). Returns `{ items }` with `assistant`, `sources` (`[ { id, title, url, snippet } ]`), `navigate` (`{ id, title, url }`, the browser opens the page) or `error` items. Public index only. 403 when the visitor chat is disabled, 429 over the message limit. |
| GET | `/skills` | All chat skills: `{ skills: [ { id, name, description, instructions, active, source, use_count, last_used_at, created_by, created_at, updated_at } ] }`, most used first. |
| POST | `/skills` | Create a skill. Params `name` (normalized to a lowercase hyphenated slug, unique), `description`, `instructions`, `active` (default true), `source` (`user` or `agent`). 201 with the skill; 409 when the name is taken. |
| GET | `/skills/<id>` | One skill; 404 when missing. |
| PUT/PATCH | `/skills/<id>` | Update the fields sent (`name`, `description`, `instructions`, `active`). |
| DELETE | `/skills/<id>` | `{ deleted: true }`. |
| GET | `/visitor-chats` | Params `filter` (`unread`, `contact` or empty), `search`, `page`, `per_page` (max 100). `{ chats: [ { id, preview, message_count, contact, has_contact, is_read, admin_note, ip, ip_forwarded, summary, summary_at, summary_stale, forwarded_to, forwarded_at, page, created_at, updated_at } ], total, pages, counts: { all, unread, contact } }`; `search` also matches IP addresses, latest activity first. |
| GET | `/visitor-chats/<id>` | One conversation with its `transcript`; 404 when missing. |
| PUT/PATCH | `/visitor-chats/<id>` | Update `is_read` and/or `admin_note`. |
| DELETE | `/visitor-chats/<id>` | `{ deleted: true }`. |
| POST | `/visitor-chats/<id>/summary` | Generates (or regenerates) the AI summary; returns the conversation with `summary`, `summary_at`, `summary_stale`. 502 when the AI request fails. |
| POST | `/visitor-chats/<id>/forward` | Params `to` (comma, semicolon or space separated, max 10 addresses), `message` (optional), `summarize` (default true: generate the summary first when it is missing or outdated; 502 and nothing sent when that fails). Emails the conversation; returns it with `forwarded_to`, `forwarded_at`. 400 for invalid addresses, 502 when `wp_mail()` fails (the message includes the mailer error). |
| POST | `/visitor-chats/bulk` | Params `action` (`read`, `unread`, `delete`), `ids` (max 100). `{ updated }`. |
| POST | `/chat/message` | Params `conversation_id` (0 = new), `message` (max 4000 chars), `context` (`{ screen, post_id }`). Returns `{ conversation_id, title, items, actions }`. AI failures are returned as an `error` item with status 200. |

## Data model

Both databases share one schema (`PRAGMA user_version` = 1; WAL mode, foreign keys on):

- `documents`: one row per object (`object_type`, `object_id`, `subtype`, `status`, `title`, `url`, `excerpt`, author, `published_at`, `modified_at`, `content_hash`, `indexed_at`, `run_id`), unique on `(object_type, object_id)`.
- `fields`: structured key/value rows (`document_id`, `source`, `name`, `value`). Sources: `core`, `taxonomy`, `yoast`, `acf`, `meta`, `media`.
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
    Extractors/          Core, Taxonomy, Yoast, Acf, Meta, Media extractors
  Embeddings/
    EmbeddingProvider.php  Provider interface
    OpenAIEmbeddings.php   OpenAI client
    VectorCodec.php        float32 BLOB pack/unpack
  Search/SearchService.php Hybrid search, document lookup, field catalog
  Abilities/Abilities.php  Abilities API category and read-only abilities
  Chat/
    ChatAgent.php          Admin LLM tool-calling loop (WordPress AI Client)
    PublicChatAgent.php    Visitor chat loop over the public index
    PromptFactory.php      Shared provider/model/reasoning prompt setup
    RateLimiter.php        Visitor chat message limit per IP
    ModelCatalog.php       Provider model list (cached)
    ConversationStore.php  MySQL conversation table
    SkillStore.php         MySQL chat skills table
    VisitorChatStore.php   MySQL visitor chats table (transcripts, contact details, IP, summary, admin notes)
    VisitorChatReport.php  Plain-text rendering of a visitor chat (summary input, email body)
    VisitorChatSummarizer.php  AI summary of a visitor chat
    VisitorChatMailer.php  Forwards a visitor chat through wp_mail()
    ClientIp.php           Visitor IP (REMOTE_ADDR or a trusted proxy header)
  Rest/                    IndexController, ChatController, PublicChatController, SkillController, VisitorChatController
  Cli/Command.php          WP-CLI commands
  Admin/                   Menu, Settings page, Indexing page, Skills page, Visitor chats page, Chat panel
  Frontend/FrontendChat.php  Admin or visitor chat on the front end
  Frontend/ChatAppearance.php  Visitor chat appearance settings as CSS custom properties and classes
assets/                  JS (no build step) and CSS for the admin, the chat panels and the visitor chat
```

## Roadmap

None of the following is implemented yet.

- **Visitor chat placement**: a block or shortcode to embed the visitor chat in a page instead of the floating button.
- **MCP access for external agents** via the WordPress Abilities API and MCP Adapter: public tools use the public index; admin tools use the admin index and are capability-gated.
- **Skill maintenance**: counting failed skill runs and deactivating skills that keep failing, picking relevant skills by embedding similarity when there are many, and detecting near-duplicate proposals.
- **Visitor chat leads**: automatic email notification (with the summary) when a visitor leaves contact details, and personal data export/erasure (Tools > Export/Erase Personal Data) by the visitor's email.
- **Optional sqlite-vec acceleration** for vector search.
- **Media file contents**: extracting text from PDFs and other documents in the media library.

## License

GPL-2.0-or-later.
