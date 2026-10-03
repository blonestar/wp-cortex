# Changelog

All notable changes to WP Cortex. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [semantic versioning](https://semver.org/) (see "Versioning" in `AGENTS.md`).

## [Unreleased]

### Added

- Chat skills: saved procedures the admin chat follows when a request matches them (`use_skill` tool). After a multi-step task the assistant can propose a skill (`propose_skill` tool), shown as a card in the chat to edit, save or dismiss; nothing is saved without the user's confirmation. New Cortex > Skills screen to add, edit, activate, deactivate and delete skills, with source and usage stats. New table `{prefix}wp_cortex_skills` and REST routes under `/wp-cortex/v1/skills`.
- Visitor chat (`public_chat_enabled`, off by default): a floating chat on the front end that answers visitor questions only from the public index, links the pages it used and lists them as sources. Configurable title, welcome message, custom instructions and a per-visitor hourly message limit. Conversations are kept in the browser session, not on the server. New public endpoint `POST /wp-cortex/v1/public-chat/message`.
- Admin chat on the front end (`chat_frontend`, off by default): administrators can use the admin chat (admin index) while browsing the site; it knows which post is being viewed.
- Filters `wp_cortex_public_chat_system_instruction` and `wp_cortex_public_chat_client_ip`.

- Chat `open_admin_page` tool: asking the assistant to go to an admin screen (for example "open the permalink settings" or "go to plugins") navigates there automatically. Only screens from the current user's admin menu can be opened.
- Chat `select_tab` tool: asking the assistant to open a tab on the current screen (for example the "Common" tab of an ACF options page) clicks it. `open_admin_page` accepts an optional `tab`, opened after the screen loads. Only tabs can be clicked.
- Chat "Custom instructions" setting: text added to the system prompt of every chat message (for example a preferred answer language or tone).
- Chat reasoning level: a "Reasoning" selector next to the chat model sets how much the model thinks (OpenAI, Anthropic and OpenRouter; other providers through the `wp_cortex_chat_reasoning_levels` and `wp_cortex_chat_reasoning_options` filters).
- Optional media library indexing (`index_media` setting, off by default): attachments are indexed with title, caption, alt text, description, file URL and `media` fields (MIME type, file name and size, dimensions, audio/video metadata). Media inherit the status and password protection of their parent post and are kept in sync automatically.

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
