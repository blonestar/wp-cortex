# Changelog

All notable changes to WP Cortex. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [semantic versioning](https://semver.org/) (see "Versioning" in `AGENTS.md`).

## [Unreleased]

## [0.4.0] - 2026-10-05

### Added

- Visitor chat AI assistant settings (Settings > Visitor chat > AI assistant): the visitor chat can use its own AI provider, model and reasoning level (`public_chat_provider`, `public_chat_model`, `public_chat_reasoning`) instead of the admin chat's, for example a cheaper or faster model. By default it keeps following the admin chat. The visitor chat custom instructions moved to this section.
- Visitor chat summary settings (Settings > Visitor chat > Conversation summaries): summaries can use their own AI provider, model and reasoning level (`summary_provider`, `summary_model`, `summary_reasoning`; any model, tool calling is not needed), by default the admin chat's; custom instructions added to every summary (`summary_instructions`); and the summary language (`summary_language`). New filter `wp_cortex_visitor_chat_summary_system_instruction`.
- Cortex dashboard widget for administrators: visitor chat counts (unread highlighted, total, last 7 days, with contact details) and the latest unread conversations, where each chat is turned on (admin chat in the admin and on the front end, visitor chat), and an overview of both indexes (documents, chunks, embedding coverage, size, last update, pending automatic sync, run status, public exposure warning).
- The Visitor chats screen opens with a filter from the URL (`&filter=unread` or `&filter=contact`).
- Changelog tab on Cortex > Settings (last tab): the changes of each version, collapsible per version, with the change counts by type, release dates and the installed version marked. Pending changes and the latest release start expanded; Expand all and Collapse all toggle every version.
- Visitor chat activity: the Visitor chats screen shows whether the visitor is still in a conversation (Active, Idle, ended; a green, yellow or grey dot in front of each conversation in the list). The visitor chat reports every minute that its window is open (while the tab is visible) and at once when it is closed, the tab is hidden or the visitor leaves the page (closes the tab or navigates), through the new public endpoint `POST /wp-cortex/v1/public-chat/presence` (only while the conversation log is on); new columns `seen_at` and `chat_open` in `{prefix}wp_cortex_visitor_chats`. The open conversation and the list refresh automatically.
- Safer forwarding of visitor chats: the form warns, and asks for confirmation, while the conversation may not be finished. A conversation that continued after it was sent is flagged ("Changed since sent", with the number of new visitor messages) and **Send update** forwards it again to the same recipients, with "(update)" in the subject.
- Visitor chat images (`public_chat_images`, off by default): visitors can attach an image to a message, for example a screenshot of a problem, by pasting it into the message box, dropping it on the chat or using the new attach button. The image is scaled down in the browser, re-encoded on the server (at most 1600 px, metadata stripped, only real PNG, JPEG, WebP or GIF images accepted) and sent to the visitor chat model with that message; the model must support image input. Images have their own hourly limit per visitor (`public_chat_image_limit`, default 10). While the conversation log is on, they are stored with the conversation in the protected data directory, shown in the transcript under Cortex > Visitor chats (click to open full size), attached to forwarded emails and deleted with the conversation. New admin route `GET /wp-cortex/v1/visitor-chats/<id>/images/<name>`.
- Issue reports (`public_chat_reports`, on by default): visitors can point out problems on the site in the visitor chat, such as a typo, a broken or missing image, a broken link, outdated information or something that does not work, and the assistant reports them to the site team (`report_issue` tool) with the page, the quoted text and the conversation. New Cortex > Issue reports screen to resolve, dismiss, reopen, annotate and delete reports, with the open count in the menu, links to the page editor and the conversation, and the reports linked from the conversation transcript. Optional email notification of new reports (`public_chat_report_email`). The dashboard widget shows open, resolved and dismissed reports and the latest open ones. New table `{prefix}wp_cortex_issue_reports` and REST routes under `/wp-cortex/v1/issue-reports`.

### Changed

- Cortex > Indexing: the data directory path in the Storage card is hidden by default; the eye button shows or hides it. Storage warnings stay visible.
- Visitor chat summaries are written in the site language (Settings > General > Site Language) by default, so the team gets them in one language whatever language the visitor used. The previous behavior (the visitor's language) can be chosen under Conversation summaries.

### Fixed

- Visitor chat contact details silently dropped website URLs whose domain did not resolve from the server (for example `www.entity.rs` on a local site): URLs were checked with `wp_http_validate_url()`, which does a DNS lookup and rejects private addresses. They are now checked by syntax only, and `save_contact_details` returns an error (so the assistant asks again) instead of reporting success when the website URL is not valid.
- Visitor chat could not list the posts written by an author (for example "find all posts by Mark Davoli"): it only had a text search, which finds pages that mention the name, and its results did not show the author. `search_site` now has an `author` filter (the query may be empty to list everything by that author, up to 30 results), and search results and pages include the author and publication date. Author names match regardless of case, accents and short inflected endings ("Marka Davolija" finds "Mark Davoli"), also in the admin `search-content` author filter. `go_to_page` can open the author page (author archive) of an author with published content.

- Visitor chat did not save contact details the visitor gave (for example a name and email address): the assistant kept asking what the visitor wanted to be contacted about and waited for a confirmation, so nothing was saved if the visitor did not answer. Contact details are now saved as soon as the visitor gives them, also a name mentioned in a greeting (without starting the contact flow); the assistant repeats an email address or phone number so the visitor can correct it, and corrections are saved too. When the reason for the contact is not known yet, the assistant asks for it once an email or phone number is saved and adds it to the saved details; it no longer fills in a generic request on its own. When the visitor wants to be contacted and it is clear what about (for example SEO services), the request is saved right away, even before the name and email; the conversation shows it in the Contact column but counts as "with contact details" only once a name, email, phone or other detail is saved. Spelled-out email addresses ("ana at example dot com") are saved in their standard form. Website URLs such as `www.example.com` or `https://www.example.com` were silently dropped (they were checked like outgoing requests, with a DNS lookup) while the assistant said they were saved; they are now saved (with `https://` added when missing), and an invalid URL is reported back to the assistant.

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
