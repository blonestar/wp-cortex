# Changelog

All notable changes to WP Cortex. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [semantic versioning](https://semver.org/) (see "Versioning" in `AGENTS.md`).

## [Unreleased]

### Added

- Chat tools can be added, changed or removed from a theme: PHP files in `wp-cortex/tools/admin/` (admin chat) and `wp-cortex/tools/public/` (visitor chat) return a new tool, the parts of a built-in tool to change (description, arguments, system prompt lines, availability or behavior) or `false` to remove it; a child theme file replaces the parent theme file with the same name. Plugins can do the same with the new `wp_cortex_admin_chat_tools` and `wp_cortex_public_chat_tools` filters. The visitor chat never accepts abilities or admin tools. See `docs/chat-tools.md`.

- Settings > Chat tools: every tool of the admin and visitor chats with its source (Cortex, theme or plugin), its kind (an ability, also usable outside the chat, or a chat tool), a switch and a per-message call limit, and what else decides whether it is offered (for example the skills or issue report setting).
- The admin chat can use abilities registered by WordPress and other plugins: Settings > Chat tools > WordPress abilities lists them with their category and kind (read-only, changes the site, destructive), and only checked abilities are offered. Abilities that are not read-only never run on the assistant's call: the chat shows them as an action card with the arguments, and they run only after you click Run (once, within an hour); the assistant then reports the outcome.

### Changed

- Every chat tool is now its own class (`src/Chat/Tools/Admin/`, `src/Chat/Tools/Public/`), and both chats share one model/tool loop. The system prompt mentions a tool only while the tool is offered (for example no `open_admin_page` rules on the front end).

## [0.9.0] - 2026-10-06

### Added

- Issue reports show the images the visitor attached while reporting the problem (for example a screenshot), and the notification email says how many there are. Images are kept with the conversation and disappear when it is deleted.

### Fixed

- A visitor who followed up on a problem they had already reported (with more details or a screenshot) created a second report for the same problem. While the conversation log is on, the visitor chat now updates the existing open report of the conversation instead.

## [0.8.0] - 2026-10-06

### Added

- Separate configuration of the admin and public indexes under Settings > Indexing > Admin index and Public index, shown as two clearly marked sections. Each index has its own post types (`admin_post_types`, `public_post_types`), including media, and its own fields: every field of the core data, taxonomies, Yoast SEO, ACF (grouped by field group), custom meta keys and media can go into the admin index, the public index, both or neither (`field_scopes`). ACF content fields (text, dates, links to other posts and terms, repeaters...) of the public post types are chosen for the public index by default; settings-like fields (switches, numbers, choices, URLs, images) and fields named like internal data (`id`, `code`, `note`, `email`...) are not. For example, a post type can be indexed only for visitors. The field lists have a filter box; ACF fields are grouped by field group and only groups stored on posts of the index's post types are listed. A field name used in several field groups is listed in each of them with one shared choice.
- Extractors can implement `DescribesFields` so their fields can be assigned to an index on the settings screen.
- Pause and Resume buttons for a running index run (Settings > Indexing > Status & stats, `POST /index/pause` and `/index/resume`). A paused run continues where it stopped; the time spent paused is not counted in the elapsed time.

### Changed

- Fewer fields go into the public index by default: parent post ID, featured image URL and the media MIME type, file name and size, dimensions and audio/video metadata are now admin only. The Yoast SEO title, meta description and social titles and descriptions, which are printed in the page head anyway, now go into the public index as well. Run Sync to apply.
- Settings > Indexing: "What gets indexed" and "Data sources" are replaced by Admin index, Public index and Data sources & sync (Auto sync moved there).
- Sync and Rebuild (Settings > Indexing > Status & stats) run in the background on the server and continue after the page is closed. The page only shows the progress, refreshes it every few seconds (also in other tabs and when you come back) and can cancel the run. A WP-Cron watchdog restarts a run that stopped; where the host blocks loopback requests, an open status page keeps the run going as before.

### Fixed

- ACF relationship, post object, taxonomy, user, image, file and gallery fields were indexed as bare IDs (or, for user arrays, with the user's email and account data). They are now indexed as post titles, term names, user display names and image or file titles, alt texts and captions, whatever the field's return format. The admin index names every related post (unpublished ones with their status, for example "Title (draft)") and every term; the public index only published, non-password-protected posts and terms of viewable taxonomies. ACF dates are indexed as ISO dates (`2024-04-29`) and password fields are never indexed. Run Sync to apply.

### Removed

- The `post_types`, `index_media` and `acf_public` settings, replaced by the per-index post types and fields. Saved settings are migrated: the selected post types (and media) go into both indexes. "Include ACF text in the public index" is not carried over: the ACF content fields of the public post types are chosen by default instead (adjust them under Public index).
- The warning when leaving the page during indexing, which is no longer needed. The old Resume button (which continued a run in the browser) is replaced by Pause and Resume.

## [0.7.0] - 2026-10-06

### Added

- Settings > Visitor chat > General: a message box placeholder (`public_chat_placeholder`, empty by default, which shows "Type your question…").
- Settings > Visitor chat > General > Images: a switch to show the attach button next to the message box (`public_chat_attach_icon`, off by default). Visitors can still paste or drop images without it.

### Changed

- The visitor chat no longer shows the attach button by default, and the message box placeholder no longer mentions screenshots when images are allowed.

## [0.6.0] - 2026-10-06

### Added

- The index databases and visitor images are stored outside the web root by default when the site allows it: in WP Engine's `_wpeprivate/` directory, in Pantheon's `wp-content/uploads/private/`, or in the directory above the web root. The plugin uses the first location it can actually write to and falls back to uploads otherwise. The choice is stored in the new `wp_cortex_data_location` option, and Settings > Advanced > Storage shows it next to the data directory. `WP_CORTEX_DATA_DIR` still takes precedence.
- Settings > Advanced > Uninstall: a switch to keep the index databases when the plugin is deleted (`uninstall_delete_index`, on by default, which deletes them as before). When off, the data directory is kept and a new installation reuses the index without re-indexing; settings, conversations and visitor images are still deleted.

### Changed

- The Storage card (data directory and public access check) moved from Cortex > Indexing to a new Settings > Advanced tab. Cortex > Indexing (now Settings > Indexing > Status & stats) only shows an alert, with a link to it, while the index is publicly downloadable; the dashboard widget alert links there too.
- Existing data in uploads is moved to the first writable private location once, the first time an administrator opens the admin while no index run is running. No re-indexing is needed. On hosts such as WP Engine, where Nginx served the databases from uploads, this makes them private.
- Chat skills moved from the Cortex > Skills screen to a new Skills tab under Cortex > Settings, with a Saved skills section (the skills list and editor) and a Settings section (the skills settings formerly under Settings > Admin chat > Skills). The Cortex > Skills menu item is removed, and the chat's "Manage skills" link opens the new tab.
- Cortex > Settings: the Content tab is merged into the Indexing tab (What gets indexed and Data sources are now its first sections), and the Skills tab moved after Visitor chat, next to Advanced.
- The Cortex > Indexing screen (sync, rebuild, progress and index stats) moved to a new Status & stats section, the first one of Settings > Indexing. The Cortex menu now opens on Visitor chats, and old links to the Indexing screen redirect to the new section.

## [0.5.0] - 2026-10-05

### Added

- Cortex > Indexing: a public access check in the Storage card, below the data directory. It reports whether the data directory lists its files and whether `public.sqlite` and `admin.sqlite` can be downloaded from the site's URL, in green when everything is blocked and red when something is reachable, with the time of the check and a Test again button. The result is cached for 12 hours.

- Skills settings (Settings > Admin chat > Skills): a switch that turns chat skills on or off (`skills_enabled`, on by default; when off, Cortex > Skills is hidden, the skills REST routes are refused and the admin chat neither uses nor proposes skills, while saved skills are kept), the language skills are written in (`skills_language`, English by default) and custom skill instructions added to the admin chat prompt (`skills_instructions`).

### Changed

- The public access warning (Indexing screen and dashboard widget) now covers both databases and the directory listing, not only `admin.sqlite`, and a check only counts a file as downloadable when the response is an actual SQLite file.
- Admin chat: skills proposed by the assistant (`propose_skill`) are written in the skill language (English by default: name, description and instructions), whatever language the conversation is in. The assistant still replies in the user's language.
- The data directory in uploads is now hidden (`.wp-cortex-<random>` instead of `wp-cortex-<random>`), so Nginx servers that refuse paths starting with a dot (common in WordPress setups, Local for example) no longer serve the index databases or visitor images, even though they ignore the `.htaccess` deny rules. Existing directories are renamed automatically; no re-indexing is needed. When a database is still downloadable, the Indexing screen shows the Nginx rule that blocks it.

## [0.4.1] - 2026-10-05

### Fixed

- Admin chat: asking to open a sub-tab, for example the Appearance section of Settings > Visitor chat, opened only the outer tab and the assistant said it could not go further. Every tab and section of Cortex > Settings is now an admin screen the assistant can open directly, and the tab of `open_admin_page` accepts a nested path such as "Visitor chat › Appearance", opened level by level on any screen. A `select_tab` call after `open_admin_page` in the same reply (as in skills saved before this fix) is opened on the new screen instead of being lost.
- Admin chat: when the assistant opened two screens in one reply (for example Cortex > Settings, then the exact Visitor chat > Appearance section), the browser went to the first one. The last screen now wins.
- Visitor chat: saving contact details (`save_contact_details`) failed with a fatal error, so the visitor got "Sorry, the assistant is not available right now" or a generic error after giving their name, email address or website. Two helper methods lost in a merge are restored.

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
