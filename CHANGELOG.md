# Changelog

All notable changes to WP Cortex. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [semantic versioning](https://semver.org/) (see "Versioning" in `AGENTS.md`).

## [Unreleased]

### Changed

- Assets are versioned with the plugin version plus the file modification time, so changed JS/CSS reloads between releases.

### Fixed

- Chat: "Conversation not found" when the conversation remembered in the browser session had been deleted; the panel now starts a new conversation instead.

## [0.2.0] - 2026-10-03

### Added

- Admin chat assistant: floating panel on admin screens, backed by the WordPress AI Client with tool calling, per-user conversations and an `open_post` action.
- Hybrid search service (FTS5 keyword, semantic and Reciprocal Rank Fusion) with structured filters.
- Read-only abilities: `wp-cortex/search-content` (including an author filter), `wp-cortex/find-duplicates` (title, field value or content), `wp-cortex/get-document`, `wp-cortex/list-fields`.
- Chat settings (provider and model) with a cached model list.

### Fixed

- Parallel tool calls failed with OpenAI-compatible providers ("The API only allows a single function response…").
- Chat answers no longer narrow unrelated questions to the post being edited.
- Result cards are shown only for posts cited in the answer, below it, instead of for every intermediate search.

## [0.1.0]

### Added

- Initial plugin structure, settings and indexing into public and admin SQLite indexes.
