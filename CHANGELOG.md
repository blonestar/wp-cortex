# Changelog

All notable changes to WP Cortex. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [semantic versioning](https://semver.org/) (see "Versioning" in `AGENTS.md`).

## [Unreleased]

### Fixed

- Visitor chat could not list the posts written by an author (for example "find all posts by Mark Davoli"): it only had a text search, which finds pages that mention the name, and its results did not show the author. `search_site` now has an `author` filter (the query may be empty to list everything by that author, up to 30 results), and search results and pages include the author and publication date. Author names match regardless of case, accents and short inflected endings ("Marka Davolija" finds "Mark Davoli"), also in the admin `search-content` author filter. `go_to_page` can open the author page (author archive) of an author with published content.

## [0.3.0] - 2026-10-04

### Added

- Native WordPress update support from stable GitHub Releases, with the standard plugin update row and button backed by the release ZIP asset.
- A PR-first GitHub Actions release process: `Prepare release` validates and bumps metadata, while `Publish release` builds the plugin ZIP, checksum and GitHub Release.
- Chat skills: saved procedures the admin chat follows when a request matches them (`use_skill` tool). After a multi-step task the assistant can propose a skill (`propose_skill` tool), shown as a card in the chat to edit, save or dismiss; nothing is saved without the user's confirmation. New Cortex > Skills screen to add, edit, activate, deactivate and delete skills, with source and usage stats. New table `{prefix}wp_cortex_skills` and REST routes under `/wp-cortex/v1/skills`.
- Visitor chat (`public_chat_enabled`, off by default): a floating chat on the front end that answers visitor questions only from the public index, links the pages it used and lists them as sources. Configurable title, welcome message, custom instructions and a per-visitor hourly message limit. Conversations are kept in the browser session, not on the server. New public endpoint `POST /wp-cortex/v1/public-chat/message`.
- Admin chat on the front end (`chat_frontend`, off by default): administrators can use the admin chat (admin index) while browsing the site; it knows which post is being viewed.
- Filters `wp_cortex_public_chat_system_instruction` and `wp_cortex_public_chat_client_ip`.
- Visitor chat page navigation (`public_chat_navigation`, on by default): the assistant can offer to open a published page (for example the contact page) and opens it in the visitor's browser after the visitor asks or confirms (`go_to_page` tool). The chat stays open on the new page.
- Visitor chat contact details (`public_chat_contact`, on by default): visitors who want to be contacted can leave their name, email, phone, address, company and request in the chat; the assistant repeats them for confirmation before saving (`save_contact_details` tool). Requires the conversation log.
- Visitor chat log (`public_chat_log`, on by default) with optional automatic deletion after N days (`public_chat_retention`): visitor conversations are stored in the new `{prefix}wp_cortex_visitor_chats` table, identified by a random token from the browser session (no cookie is stored). Suggested privacy policy text is added under Settings > Privacy.
- New Cortex > Visitor chats screen: lists visitor conversations with their contact details, unread count in the menu, filters (unread, with contact details), search and bulk actions. A conversation opens with its full transcript (including the pages the visitor was on) and can be marked as read or unread, annotated with a note for the team or deleted. New REST routes under `/wp-cortex/v1/visitor-chats`.
- Visitor IP address stored with visitor conversations (`public_chat_store_ip`, on by default), searchable on the Visitor chats screen. Sites behind a proxy or CDN can choose the trusted header with the real visitor IP (`public_chat_ip_header`: CF-Connecting-IP, True-Client-IP, X-Real-IP or X-Forwarded-For); it is also used for the message limit. Addresses from other proxy headers are stored separately as unverified.
- Visitor chat summary: a Summarize button on a conversation asks the chat model for a short summary of what the visitor wanted, asked and looked at, their contact details and the open points, in the visitor's language. The summary is stored, flagged as outdated when the conversation continues and regenerated with Refresh summary. The pages visited, opened and suggested are listed below it.
- Forward a visitor chat by email: sends the summary, contact details, note and transcript to up to 10 addresses. "Include an up-to-date summary" (on by default) generates a missing or outdated summary before sending; without it, an outdated summary is marked as outdated in the email and the form warns about it through `wp_mail()` (works with SMTP plugins and mail connectors); replies go to the visitor's email when known.

- Chat `open_admin_page` tool: asking the assistant to go to an admin screen (for example "open the permalink settings" or "go to plugins") navigates there automatically. Only screens from the current user's admin menu can be opened.
- Chat `select_tab` tool: asking the assistant to open a tab on the current screen (for example the "Common" tab of an ACF options page) clicks it. `open_admin_page` accepts an optional `tab`, opened after the screen loads. Only tabs can be clicked.
- Chat "Custom instructions" setting: text added to the system prompt of every chat message (for example a preferred answer language or tone).
- Chat reasoning level: a "Reasoning" selector next to the chat model sets how much the model thinks (OpenAI, Anthropic and OpenRouter; other providers through the `wp_cortex_chat_reasoning_levels` and `wp_cortex_chat_reasoning_options` filters).
- Optional media library indexing (`index_media` setting, off by default): attachments are indexed with title, caption, alt text, description, file URL and `media` fields (MIME type, file name and size, dimensions, audio/video metadata). Media inherit the status and password protection of their parent post and are kept in sync automatically.
- Visitor chat appearance (Settings > Visitor chat > Appearance): accent color (with automatic readable text color), light, dark or automatic color scheme, bottom right or bottom left position and distance from the edge, chat button size and optional label, window width and height, corner radius, system or theme font and font size. A live preview shows the changes before saving. New settings `public_chat_accent`, `public_chat_scheme`, `public_chat_position`, `public_chat_offset`, `public_chat_launcher_size`, `public_chat_launcher_label`, `public_chat_width`, `public_chat_height`, `public_chat_radius`, `public_chat_font` and `public_chat_font_size`.

### Changed

- Admin chat: the floating toggle and panel can be dragged together, with the position remembered for the current browser session only.
- Visitor chat contact details: a confirmed first or last name can now be saved without requiring an email address or phone number, and visitors may optionally leave one or more website URLs.
- Redesigned Cortex > Settings: settings are grouped into tabs (Content, Indexing, Admin chat, Visitor chat); a tab with several sections lists them as vertical tabs on the left (for example Visitor chat: General, Conversations & privacy, Assistant actions), with a sticky save bar. The active tab and section are kept in the URL (`&tab=`, `&section=`) and after saving. Settings is now the last item of the Cortex menu; the Cortex menu opens the Indexing screen.
- Assets are versioned with the plugin version plus the file modification time, so changed JS/CSS reloads between releases.

### Fixed

- Chat: "Conversation not found" when the conversation remembered in the browser session had been deleted; the panel now starts a new conversation instead.
- Chat skills: saved or dismissed proposal cards now keep their resolved state across page navigation and conversation reloads; refining a pending proposal updates its existing card instead of adding a duplicate.
- Visitor chat navigation no longer treats indexed media attachments, such as SVG logos, as pages that can be opened.

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
