# AGENTS.md

WP Cortex is a WordPress plugin that indexes site content into local SQLite databases (structured fields, FTS5 full-text, vector embeddings) as a memory layer for AI search and chat. See `README.md` for features, data model and roadmap.

## Language

All code, comments, UI strings, docs and commit messages must be in English. (Conversation with the user may be Serbian or English; match the user.)

## Code conventions

- PSR-4: `WPCortex\` maps to `src/` (custom autoloader in `wp-cortex.php`, no Composer).
- WordPress coding standards formatting: tabs, spaces inside parentheses, `array()` (not `[]`), snake_case methods, Yoda conditions.
- Classes are `final`; docblocks on every method (the short interface-implementing methods are the only exception).
- Every PHP file starts with the file docblock and has `defined( 'ABSPATH' ) || exit;` (uninstall.php uses `WP_UNINSTALL_PLUGIN`).
- Text domain `wp-cortex` for all UI strings.
- No build step: plain JS using `wp.apiFetch` / `wp.i18n`; CSS classes are prefixed `wp-cortex-`.

## Architecture rules and invariants

- **The public index must only receive data the admin chose for it.** Each index has its own post types (`admin_post_types`, `public_post_types`) and its own fields (`field_scopes`, resolved by `Indexing\FieldPolicy`). Extractors flag fields/sections with a `public` default (default `false`, `true` only for data already visible on the site) and tie sections to their field key (`add_section( ..., $field )`); a value that must differ between the indexes (related posts: every title in admin, only published ones in public) uses `add_scoped_field()` with a public value or `null`; `Document::for_scope( $scope, $policy )` is the only place that decides what goes into an index. Never bypass `for_scope()`. The public index only gets `publish` status, non-password-protected posts of viewable post types, whatever the settings say.
- All database access goes through `Storage\Database`; do not open PDO elsewhere.
- Schema changes: bump `Database::SCHEMA_VERSION` and add a migration step guarded by the stored `user_version` in `migrate()`.
- The embedding signature (`Settings::embedding_signature()`, `model:dimensions`) must change whenever the model or dimensions change, so stale vectors are regenerated.
- New data sources are new `Extractor` classes, registered in `Indexer::extractors()` or through the `wp_cortex_extractors` filter.
- Full index runs started from the admin are processed by `Indexing\IndexWorker` through the admin-ajax action `wp_cortex_index_worker` (also `nopriv`, because loopback and cron requests have no session). It only accepts the HMAC token of the current run (`hash_equals`), only advances the run that is already running, and never returns data.
- REST endpoints require `manage_options`. The only exceptions are the visitor chat endpoints `POST /wp-cortex/v1/public-chat/message` and `POST /wp-cortex/v1/public-chat/presence`: they are open only while `public_chat_enabled` is on. The message endpoint is rate limited per IP and must only ever read the public index (`PublicChatAgent`, never the abilities or the admin scope). Their only writes are the visitor's own conversation (by session token, while `public_chat_log` is on), the contact details the visitor confirms (`save_contact_details`) and, from the presence endpoint, whether that conversation's chat window is open (`VisitorChatStore::touch()`: `seen_at` and `chat_open` of an existing row only, never `updated_at` or the read state), all in `Chat\VisitorChatStore`, issue reports (`report_issue`, while `public_chat_reports` is on, at most 3 per message) in `Chat\IssueReportStore`, which may also update an open report of the visitor's own conversation (`report_id`, `IssueReportStore::amend()` checks `chat_id` and status; the agent lists those reports with `open_for_chat()`: ID, category, description and quoted text only) and attach the image of the current message to it, and, from the message endpoint, the image attached to the visitor's message (`public_chat_images`, stored by `Chat\VisitorImages` under `visitor-images/<conversation ID>/` in the data directory, only after GD re-encoding); apart from those open reports of the visitor's own conversation they never read stored conversations, reports or images, and the presence response is the same whether or not the conversation exists. Stored images are served only through the admin `GET /visitor-chats/<id>/images/<name>` route and must be deleted with their conversation (`VisitorChatStore::delete()`, which `purge()` uses). A report's page URL comes from the browser and is kept only when it is on the site's own host; its post ID only when the page is in the public index. `go_to_page` may only open pages from the public index, or the author archive of an author who has content in the public index (not when Yoast SEO disables author archives).
- Options used: `wp_cortex_settings`, `wp_cortex_data_dir_name`, `wp_cortex_data_location` (data directory location key chosen by `Storage::location()`: `wpengine`, `pantheon`, `outside` or `uploads`; removed with the data directory). On uninstall the data directory, `wp_cortex_data_dir_name` and `wp_cortex_data_location` are deleted unless the `uninstall_delete_index` setting is off; visitor images are deleted either way, `wp_cortex_index_run`, `wp_cortex_index_lock`, `wp_cortex_sync_queue`, `wp_cortex_last_sync`, `wp_cortex_db_version` (version of the MySQL conversations table `{prefix}wp_cortex_conversations`, dropped on uninstall), `wp_cortex_skills_db_version` (version of the MySQL chat skills table `{prefix}wp_cortex_skills`, `Chat\SkillStore`, dropped on uninstall), `wp_cortex_visitor_chats_db_version` (version of the MySQL visitor chats table `{prefix}wp_cortex_visitor_chats`, `Chat\VisitorChatStore`, dropped on uninstall), `wp_cortex_issue_reports_db_version` (version of the MySQL issue reports table `{prefix}wp_cortex_issue_reports`, `Chat\IssueReportStore`, dropped on uninstall); transients `wp_cortex_storage_exposed`, `wp_cortex_models_<provider>` (12 h cache of a provider's model list, `Chat\ModelCatalog`; removed on uninstall), `wp_cortex_rate_<hash>` (visitor chat message and image counters per IP, `Chat\RateLimiter`; removed on uninstall) and `wp_cortex_update_release` (12 h cache of the latest stable GitHub release, `Update\PluginUpdater`; removed on uninstall); cron hooks `wp_cortex_process_queue`, `wp_cortex_index_watchdog` (single event every minute while a full index run is active, restarts a stalled background run, `Indexing\IndexWorker`; cleared on deactivation and uninstall) and `wp_cortex_purge_visitor_chats` (daily, deletes visitor chats older than `public_chat_retention` days; cleared on deactivation and uninstall); filters `wp_cortex_chat_system_instruction`, `wp_cortex_admin_chat_tools`, `wp_cortex_public_chat_tools` (tools of the admin and visitor chats, after the theme's `wp-cortex/tools/admin/` and `wp-cortex/tools/public/` files; `Chat\Tools\ToolRegistry`), `wp_cortex_chat_reasoning_levels`, `wp_cortex_chat_reasoning_options`, `wp_cortex_public_chat_system_instruction`, `wp_cortex_public_chat_client_ip` (visitor IP from `Chat\ClientIp`: `REMOTE_ADDR` or the trusted header in `public_chat_ip_header`; never trust other proxy headers for the rate limit). The `chat_reasoning`, `chat_frontend`, `chat_tools` (per chat: tools switched off and per-message call limits, Settings > Chat tools), `chat_abilities` (abilities of other plugins allowed in the admin chat), `skills_*` and `public_chat_*` settings live in `wp_cortex_settings`. Any new option must be cleaned up in uninstall.
- Chat tools are `Chat\Tools\Tool` classes: admin tools in `src/Chat/Tools/Admin/` (get an `AdminContext`), visitor tools in `src/Chat/Tools/Public/` (get a `PublicContext`, whose `search()` is the public index). A tool's description, arguments, system prompt lines (`instructions()`) and availability (`is_available()`, for example its setting) live in its class, not in the agent; the agents list their built-in tools in `builtin_tools()` and run them through `ToolRegistry` and `AgentLoop`. `ToolRegistry` applies the theme files and the `wp_cortex_*_chat_tools` filters, leaves out tools switched off in `chat_tools` and enforces their per-message limits, and must keep refusing abilities (`wpab__*`) and `Tools\Admin\` classes in the visitor chat; a theme override may only narrow a built-in tool's availability. Abilities of other plugins reach the admin chat only when listed in `chat_abilities`. The editor tools (`edit_post`, `edit_seo`, `edit_fields`, `get_editor_content`, `edit_content`) work only on the post open in the block editor (`Chat\Tools\Admin\EditorState`, dropped when the user cannot edit the post) and never write to the database: they return a UI action that `chat.js` applies in the editor, and the user saves the post. An ability not annotated as read-only must never run on the model's call: `AbilityTool` only adds an `ability_action` card, and `ChatAgent::resolve_action()` (REST `POST /chat/conversations/<id>/actions/<action>`) runs it after the user confirms, with the input stored in the card, once, within an hour, while it is still allowed. Visitor tools must keep every visitor endpoint rule above (public index only, only the listed writes).
- Chat skills are admin-only and exist only while `skills_enabled` is on (otherwise no saved skills list on the Settings > Skills tab, the `/skills` routes refuse requests and `ChatAgent` offers neither `use_skill` nor `propose_skill`): only `ChatAgent` reads them, never `PublicChatAgent`. The assistant may only *propose* a skill (`propose_skill` returns a `skill_proposal` transcript item); skills are saved only through the `/skills` REST routes, after the user confirms. Skills are plain-text instructions, never executable code.
- Content is rendered as an anonymous visitor (`CoreExtractor::render_content()`). Keep extraction deterministic: anything that changes between renders (random IDs, honeypots, shuffled lists) changes the content hash and causes needless re-indexing and re-embedding.

## Adding an extractor

1. Create `src/Indexing/Extractors/FooExtractor.php`, `final class` implementing `Extractor`.
2. `is_available()`: check the setting and that the data source exists.
3. `extract( WP_Post $post, Document $doc )`: call `$doc->add_field( $source, $name, $value, $public )` and/or `$doc->add_section( $heading, $text, $public, "$source:$name" )`. `$public` is only the default; use `true` only for data already visible on the front end.
4. Implement `DescribesFields` (`fields_label()`, `fields()`) so the fields can be assigned to an index under Settings > Indexing > Admin index and Public index; keep the defaults in `fields()` equal to the `$public` values passed in `extract()`.
5. Register it in `Indexer::extractors()` (or via `wp_cortex_extractors`).
6. If it depends on meta changes, update `PostSync::on_meta_change()` so auto-sync notices them.
7. Add a setting in `Settings` (defaults + `sanitize()`) and `Admin\SettingsPage` if it is user-toggleable.

## Adding a chat tool

1. Create `src/Chat/Tools/Admin/FooTool.php` (extends `AdminTool`) or `src/Chat/Tools/Public/FooTool.php` (extends `PublicTool`), `final class`.
2. Implement `name()`, `label()`, `description()`, `parameters()`, `execute()`; add `is_available()` (setting, context) and `instructions()` (system prompt lines that only make sense while the tool is offered).
3. Keep per-turn state in the tool's properties; show notices with `$context->set_item()`, navigation with `$context->navigate()`.
4. Add it to `builtin_tools()` of `ChatAgent` or `PublicChatAgent`.
5. A visitor tool that writes anything must be added to the visitor endpoint rules above (and to the privacy text if it stores personal data).
6. Document it in `docs/chat-tools.md` and `README.md`.

## Local environment

Local (by Flywheel) site "amsive". PHP is not on PATH. Lint PHP with:

```
LD_LIBRARY_PATH=$HOME/.config/Local/lightning-services/php-8.4.18+1/bin/linux/shared-libs $HOME/.config/Local/lightning-services/php-8.4.18+1/bin/linux/bin/php -l <file>
```

Lint JS with `node --check <file>`. WP-CLI phar: `/opt/Local/resources/extraResources/bin/wp-cli/wp-cli.phar` (the site must be running in Local).

## Versioning

Semantic versioning `A.B.C`, bumped only when a release is made (not on every commit):

- `C` (patch, the last decimal): always use it for bug fixes and small adjustments since the last release (for example, `0.4.0` -> `0.4.1`).
- `B` (minor, reset `C` to 0): new features, settings, abilities, endpoints or schema changes.
- `A` (major, reset `B` and `C`): breaking changes (removed features or options, incompatible data or API changes).

Between releases, record every user-visible change in `CHANGELOG.md` under `## [Unreleased]` (`### Added`, `### Changed`, `### Fixed`, `### Removed`). On release: pick the bump from the Unreleased entries, rename that section to `## [A.B.C] - YYYY-MM-DD`, update the version in all three places (the `Version:` header and the `WP_CORTEX_VERSION` constant in `wp-cortex.php`, the `Version:` line in `README.md`) and tag the commit `vA.B.C`.

Assets are enqueued with `Plugin::asset_version( $path )` (plugin version plus file modification time), so changed JS/CSS reloads without a version bump. Use it for every new asset.

## Before finishing a task

- [ ] `php -l` passes on every changed PHP file; `node --check` on changed JS.
- [ ] Formatting follows WordPress standards; docblocks and `ABSPATH` guard present.
- [ ] Public/admin separation intact (no new non-public data reaches `public.sqlite`).
- [ ] Schema or embedding changes handled (`SCHEMA_VERSION`, migration, signature).
- [ ] New options/hooks documented here and cleaned up on uninstall.
- [ ] If features changed, `README.md` updated (Current vs Roadmap kept separate).
- [ ] Sanity check with `wp cortex index` / `wp cortex status` when indexing code changed.
- [ ] `CHANGELOG.md` Unreleased section updated for user-visible changes (no version bump outside a release).
- [ ] Do not commit unless asked.
