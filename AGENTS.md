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

- **The public index must never receive non-public data.** Extractors flag fields/sections with `public` (default `false`); `Document::for_scope( true )` strips everything else. The public index only gets `publish` status, non-password-protected, viewable post types. Never bypass `for_scope()`.
- All database access goes through `Storage\Database`; do not open PDO elsewhere.
- Schema changes: bump `Database::SCHEMA_VERSION` and add a migration step guarded by the stored `user_version` in `migrate()`.
- The embedding signature (`Settings::embedding_signature()`, `model:dimensions`) must change whenever the model or dimensions change, so stale vectors are regenerated.
- New data sources are new `Extractor` classes, registered in `Indexer::__construct()` or through the `wp_cortex_extractors` filter.
- REST endpoints require `manage_options`.
- Options used: `wp_cortex_settings`, `wp_cortex_data_dir_name`, `wp_cortex_index_run`, `wp_cortex_index_lock`, `wp_cortex_sync_queue`, `wp_cortex_last_sync`, `wp_cortex_db_version` (version of the MySQL conversations table `{prefix}wp_cortex_conversations`, dropped on uninstall); transients `wp_cortex_storage_exposed` and `wp_cortex_models_<provider>` (12 h cache of a provider's model list, `Chat\ModelCatalog`; removed on uninstall); cron hook `wp_cortex_process_queue`; filter `wp_cortex_chat_system_instruction`. Any new option must be cleaned up in uninstall.
- Content is rendered as an anonymous visitor (`CoreExtractor::render_content()`). Keep extraction deterministic: anything that changes between renders (random IDs, honeypots, shuffled lists) changes the content hash and causes needless re-indexing and re-embedding.

## Adding an extractor

1. Create `src/Indexing/Extractors/FooExtractor.php`, `final class` implementing `Extractor`.
2. `is_available()`: check the setting and that the data source exists.
3. `extract( WP_Post $post, Document $doc )`: call `$doc->add_field( $source, $name, $value, $public )` and/or `$doc->add_section( $heading, $text, $public )`. Use `$public = true` only for data safe to expose on the front end.
4. Register it in `Indexer::__construct()` (or via `wp_cortex_extractors`).
5. If it depends on meta changes, update `PostSync::on_meta_change()` so auto-sync notices them.
6. Add a setting in `Settings` (defaults + `sanitize()`) and `Admin\SettingsPage` if it is user-toggleable.

## Local environment

Local (by Flywheel) site "amsive". PHP is not on PATH. Lint PHP with:

```
LD_LIBRARY_PATH=$HOME/.config/Local/lightning-services/php-8.4.18+1/bin/linux/shared-libs $HOME/.config/Local/lightning-services/php-8.4.18+1/bin/linux/bin/php -l <file>
```

Lint JS with `node --check <file>`. WP-CLI phar: `/opt/Local/resources/extraResources/bin/wp-cli/wp-cli.phar` (the site must be running in Local).

## Versioning

Semantic versioning `A.B.C`, bumped only when a release is made (not on every commit):

- `C` (patch): only bug fixes and small adjustments since the last release.
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
