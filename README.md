# WP Cortex

WP Cortex is a memory layer for WordPress. It indexes site content into a local SQLite store (structured fields, FTS5 full-text search and vector embeddings) so that AI search and chat features can be built on top of it. Everything is stored locally; the only external call is the optional OpenAI embeddings request.

- Version: 0.12.0
- Author: Bojan
- License: GPL-2.0-or-later

> Status: the **indexing layer**, the **hybrid search service**, the **admin chat assistant** (in the admin and optionally on the front end) and the **visitor chat** are implemented. MCP access is on the [roadmap](#roadmap).

## Features (current)

- **Two isolated indexes**
  - `public.sqlite` holds only published, non-password-protected content of viewable post types, and only the fields chosen for the public index.
  - `admin.sqlite` holds the chosen statuses (drafts, pending, scheduled and private posts are configurable) and the fields chosen for the admin index.
  - Each index has its own post types (`admin_post_types`, `public_post_types`; media is the `attachment` type) and its own fields (`field_scopes`), configured under Settings > Indexing > Admin index and Public index. A post type or field can be in one index, both or neither; posts are extracted once and written to every index they belong to.
- **Extractors**: core post data, taxonomies, Yoast SEO, ACF, custom meta keys, media library files. Extensible through a filter.
- **Media indexing** (optional): attachments are indexed as post type `attachment` with title, caption, alt text, description, file URL and `media` fields (MIME type, file name and size, dimensions, audio/video duration, artist, album, EXIF credit, copyright and camera; by default only the file type, alt text and caption go into the public index). Media inherit the status and password protection of the post they are attached to; unattached media count as published. Image `alt_text` is stored even when empty. Yoast fields are not indexed for media.
- **Front-end-equivalent content**: blocks (including dynamic ones) are rendered as an anonymous visitor, so the index matches what the public sees and logged-in-only content never leaks into it. Forms, navigation, scripts and styles are stripped.
- **Heading-aware chunking**: HTML is split at headings; consecutive short sections are packed together and long ones split at paragraph/sentence boundaries into chunks of roughly N characters with configurable overlap.
- **OpenAI embeddings** with reuse by chunk hash: unchanged text is never re-embedded, even across documents and scopes.
- **Batch indexing in the background**: Sync and Rebuild run on the server, so they continue after the admin page is closed; the page (in any tab) only shows the live progress and can pause, resume or cancel the run. Stats for both indexes are shown next to it (Cortex > Settings > Indexing > Status & stats).
- **Incremental auto-sync** via WP-Cron when content, terms or relevant meta change.
- **Hybrid search service** (`WPCortex\Search\SearchService`): FTS5 BM25 keyword search, brute-force cosine semantic search, merged with Reciprocal Rank Fusion and grouped per document. Structured filters on post type, status, modified date and any indexed field (`eq`, `neq`, `contains`, `not_contains`, `empty`, `not_empty`, `missing`, `exists`). Degrades to keyword search when embeddings are unavailable. Also offers `get_document()` and `field_catalog()`.
- **Admin chat assistant**: a floating chat panel on every admin screen for administrators. An LLM (through the WordPress AI Client, any configured provider) answers questions about site content by calling tools over the admin index, shows the posts cited in its answer (as `#ID`) as cards below it and on request can open a post in the editor or go to any admin screen from the user's admin menu or any tab and section of Cortex > Settings (optionally straight to a named tab, including a nested tab given as a path such as "Visitor chat › Appearance") and switch tabs on the current screen. In the block editor it can change the open post on request: title, excerpt, slug and existing terms (`edit_post`), the Yoast SEO title, meta description and focus keyphrase when Yoast SEO is active (`edit_seo`), ACF fields of a simple type (`edit_fields`) and the content, by replacing the text of text blocks, inserting new content or removing blocks (`get_editor_content`, `edit_content`). It works with the values as they are in the editor, including unsaved changes. Changes are applied in the editor only, never saved, so the user reviews them and saves the post (or undoes them); asked for suggestions (for example titles), it only suggests them until the user picks one. The chat button can be dragged anywhere on the screen and the panel resized from its corner away from the button (double-click the handle to restore the default size); the size is kept in the browser's local storage. Conversations are stored per user in the `{prefix}wp_cortex_conversations` table. Needs an AI provider API key under Settings > Connectors.
- **Chat skills** (Cortex > Settings > Skills, can be turned off with `skills_enabled`): saved procedures the admin chat follows, for example how to reach a settings tab or run a recurring search. Active skills are listed by name and description in the chat system prompt; the assistant loads the steps of a matching skill with its `use_skill` tool. After a multi-step task the assistant can offer to save it, and its `propose_skill` tool shows a card in the chat where the user edits, saves or dismisses the proposal: the assistant never saves a skill by itself. Resolved proposals remain saved or dismissed in the conversation history, while refining a pending proposal updates its existing card. Skills can be added, edited, activated, deactivated and deleted under Settings > Skills > Saved skills, which also shows the source (user or assistant), the use count and the last use. Proposals are written in the `skills_language` (English by default) and follow the optional `skills_instructions`. Stored in the `{prefix}wp_cortex_skills` table; admin chat only, never used by the visitor chat.
- **Admin chat on the front end** (`chat_frontend`, off by default): administrators get the same chat panel on the public pages of the site. It uses the admin index and the same conversations, knows which post is being viewed and can open posts in the editor; admin screens and tabs can only be opened from the admin.
- **Visitor chat** (`public_chat_enabled`, off by default): a floating chat for visitors on the front end that answers only from the **public index**, through its own `search_site` (topic search, optionally filtered by author to list the posts someone wrote; author names match regardless of case, accents and inflected forms such as "Marka Davolija") and `get_page` tools (the abilities are not used, they read the admin index). Pages the answer is based on are linked inline and listed as sources. The conversation lives in the browser session and its previous text turns are sent with each message as the model context. Requests are anonymous (no cookies or nonce), so cached pages keep working, and are limited per client IP (`public_chat_rate_limit` messages per hour). On request the assistant opens a published page or the author page of an author with published posts, or reloads the page the visitor is on (`go_to_page`, only after the visitor asks or confirms; the chat stays open), reports problems visitors point out on the site (`report_issue`, see Issue reports) and collects the contact details of visitors who want to be contacted (`save_contact_details`, after the visitor confirms them; a confirmed name can be saved without an email or phone, and one or more website URLs can be included). With `public_chat_images` on, visitors can attach an image to a message, for example a screenshot of a problem: paste it into the message box, drop it on the chat or pick it with the attach button (shown only with `public_chat_attach_icon`). The browser scales it down; the server re-encodes it with GD (PNG stays PNG, other formats become JPEG, at most 1600 px, metadata stripped, anything that is not a real PNG, JPEG, WebP or GIF image is rejected) and sends it to the model with that message only. Later turns only note that the message had an image. Images are limited separately per client IP (`public_chat_image_limit` per hour); the visitor chat model must support image input. Title, welcome message, message box placeholder and custom instructions are configurable; colors can be changed through CSS custom properties on `#wp-cortex-public-chat-root` (`--wp-cortex-accent`, `--wp-cortex-accent-text`, ...). Administrators see the admin chat instead while it is shown on the front end.
- **Visitor chats** (Cortex > Visitor chats, `public_chat_log`, on by default): every visitor conversation is stored in the `{prefix}wp_cortex_visitor_chats` table, identified by a random token generated in the browser session (only its hash is stored; no cookie), with the visitor's IP address (`public_chat_store_ip`; see `public_chat_ip_header` for sites behind a proxy or CDN; addresses from other proxy headers are kept separately as unverified). The screen lists conversations with the visitor's contact details, IP, the number of messages and the last activity, with filters (unread, with contact details), search, pagination and bulk actions; the menu shows the unread count. A conversation opens with the full transcript (including the page each message was sent from, the images the visitor attached, sources and opened pages) and can be marked as read or unread (opening marks it as read, a new visitor message as unread), annotated with a note for the team or deleted. **Summarize** asks the chat model for a short summary in the visitor's language (what they wanted, asked and looked at, their contact details and the open points), stored with the conversation; when the conversation continues, the screen flags the summary as outdated and **Refresh summary** regenerates it. The pages the visitor was on, was taken to and was shown are listed below the summary. Each conversation shows whether the visitor is still in it: **Active** (the chat window is open, reported by the widget every minute while the window is open and the tab visible, or a recent message from a widget that does not report it), **Idle** (no activity for a while, or the window was closed, the tab hidden or the page left, reported by the widget at once; the visitor may come back; up to 30 minutes) or ended, shown in the list as a green, yellow or grey dot in front of each conversation; the open conversation and the list refresh every 15 and 30 seconds while the tab is visible, and new visitor messages in the open conversation are marked as read. **Forward by email** warns while the conversation may not be finished (and asks for confirmation while the visitor is active; the email notes it). After a conversation continues, the screen and the list flag it as changed since it was sent, with the number of new visitor messages, and **Send update** forwards the whole conversation again, by default to the same recipients, marked as an update. **Forward by email** sends the summary, contact details, note and full transcript as plain text, with the visitor's images attached (the latest 10), to up to 10 addresses; with "Include an up-to-date summary" (on by default) a missing or outdated summary is generated first, otherwise an outdated summary is sent marked as such (the form warns about it) through `wp_mail()` (so an SMTP plugin or mail connector delivers it), with Reply-To set to the visitor's email when known. Optional automatic deletion after `public_chat_retention` days (daily cron). A suggested privacy policy text is added under Settings > Privacy.
- **Leads and attribution** (Cortex > Leads, Settings > Leads; requires `public_chat_log`). Three levels: leads off (`leads_enabled` off: no Leads screen, lead status, AI rating or attribution; contact details are still stored with the conversation), leads without attribution (the default: the leads list, statuses, AI rating, CSV export and the figures of the chat itself, with nothing recorded about how visitors found the site), or leads with attribution (`leads_attribution`, off by default: adds channels, sources, campaigns, landing pages and days to lead). A visitor chat becomes a lead when the visitor leaves a way to reach them: an email address, phone number, postal address or website (a name, company or request alone is not enough). While attribution is on and the visitor browses, the visitor chat widget records how they arrived: the campaign parameters (`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `utm_id`, `utm_source_platform`), the ad click IDs (`gclid`, `gbraid`, `wbraid`, `msclkid`, `fbclid`, `li_fat_id`, `ttclid`, `twclid`), up to 10 extra parameters (`leads_extra_params`), the referring site (origin and path only) and the landing page (path only; query strings are never kept). It also records the pages of the visit (up to 25). A visit starts with the first page of a browser session or with a page opened from a campaign link or another site. The first visit and the visit count are kept in the browser's local storage for `leads_attribution_days` (90 by default; 0 means the session only). With a consent manager on the page (Osano, OneTrust, Cookiebot, or a banner reporting through the WP Consent API plugin such as Complianz or CookieYes), nothing is recorded, stored or sent until the visitor accepts marketing (`leads_consent`). Recording starts when they accept on the page, and everything stored in the browser is removed when they withdraw consent. The chat works either way. The rest lives in the session. All of it is sent with chat messages only. The server keeps only known keys and computes the channel of the first and of the latest visit, like Google Analytics' default channel grouping: paid search, paid social, display, organic search, organic social, **AI assistants** (ChatGPT, Perplexity, Gemini, Copilot, Claude and others, by referrer or `utm_source`), email, affiliate, referral, other campaigns or direct. It also adds the device type, browser and OS from the user agent, the browser language, time zone and screen size, and the country when a CDN or host header reports it. The touches no longer change once the conversation is a lead. **Cortex > Leads** shows, for 7, 30 or 90 days, 12 months or all time, and optionally one channel (with attribution), these figures compared with the previous period: leads, conversations, chat-to-lead rate, hot leads and, with attribution, average days from first visit to lead. It also shows conversations and leads over time (chart with tooltip and table view) and lead quality (hot, warm, cold, not rated) with what leads want; with attribution also leads and conversion rate by channel and the top source / medium / campaign combinations and landing pages. The leads list has status filters (new, contacted, qualified, won, lost, spam; the menu shows the number of new leads), a rating filter, search, sorting and inline status changes. **Rate with AI** (per lead, or **Rate unrated leads with AI** for all) asks the summary model for a rating (hot, warm or cold), a score from 0 to 100, the intent, interest, company, role, budget, timeline, next step and the reason; a rating is flagged when the conversation continued after it. **Export CSV** downloads the filtered leads (up to 5000) with contact details, status, rating and, for the latest and the first visit, the channel, source, medium, landing page, referrer and every campaign parameter, ad click ID and extra parameter as the visitor's link had them (one column each), ready to import into a CRM. **Integrations** (Settings > Leads > Integrations) send each new lead and its changes in the background to a CRM or another tool, with the same data and the lead's ID so the receiver updates one record. A failed delivery is retried after 5 and 25 minutes, and the latest deliveries are listed with their result. Built in: **Webhook** (JSON POST to an HTTPS URL, optionally signed with HMAC-SHA256, for Zapier, Make, n8n or a CRM's webhook) and **HubSpot** (creates or updates a contact through a private app token, matched by the lead ID; the status, AI rating and, for the first and the latest visit, the channel, landing page, referrer, every UTM parameter and every ad click ID go into "WP Cortex" contact properties that it creates). Each integration has a **Send test lead** button. Developers add their own integration as a class in the theme's `wp-cortex/integrations/` folder or through the `wp_cortex_lead_integrations` filter ([docs/integrations.md](docs/integrations.md)). While leads are on, each conversation under Visitor chats shows a Lead card (status, rating, details) and a "How the visitor found the site" card (latest and first visit, visits, device, pages viewed), and attribution is included in summaries and forwarded emails.
- **Issue reports** (Cortex > Issue reports, `public_chat_reports`, on by default): visitors can point out problems on the site in the visitor chat (a typo, a broken or missing image, a broken link, wrong or outdated information, a display problem or something that does not work). The assistant reports each one with `report_issue`, without asking for confirmation or contact details: category, description, the affected text quoted exactly, the page (the page the visitor is viewing, as sent by the browser and limited to this site's host, or another public page from the search results) and the conversation. While the conversation log is on, the assistant knows the open reports of the conversation: when the visitor follows up on a reported problem (more details, a correction or a screenshot), it updates that report instead of adding a new one. Images the visitor attaches to the message in which a problem is reported or updated are attached to the report and shown on the screen (they are stored and deleted with the conversation). Reports are stored in the `{prefix}wp_cortex_issue_reports` table, also while the conversation log is off. The screen lists them with filters (open, resolved, dismissed, all), search, pagination and bulk actions, links to the page, its editor and the conversation, and lets administrators resolve, dismiss or reopen a report and add a note; the menu shows the open count. The conversation transcript links each report (`#report=ID` opens a single report). New reports can be emailed to up to 10 addresses (`public_chat_report_email`).
- **Dashboard widget** (administrators only): a Cortex widget on the WordPress dashboard with visitor chat counts (unread highlighted, total, started in the last 7 days, with contact details) linking to the filtered Visitor chats list, the latest unread conversations, issue report counts (open, resolved, dismissed, total) with the latest open reports, where each chat is on (admin chat in the admin and on the front end, visitor chat), and the size, embedding coverage and sync state of both indexes, with a warning when the index is publicly downloadable. The Visitor chats screen accepts `&filter=unread` or `&filter=contact` in the URL, the Issue reports screen `&status=open|resolved|dismissed` (empty for all).
- **Extensible chat tools**: each tool of the admin and visitor chats is its own class (`src/Chat/Tools/Admin/`, `src/Chat/Tools/Public/`) with its description, arguments, system prompt lines and availability. A theme can add tools, change parts of a built-in tool (description, arguments, prompt lines, behavior) or remove one with PHP files in `wp-cortex/tools/admin/` and `wp-cortex/tools/public/` (a child theme file replaces the parent theme file with the same name); plugins can do the same with the `wp_cortex_admin_chat_tools` and `wp_cortex_public_chat_tools` filters. Cortex > Settings > Chat tools lists every tool of each chat with its source (Cortex, theme or plugin), its kind (ability or chat tool), a switch and a per-message call limit (`chat_tools`). The visitor chat never gets abilities or admin tools. See [docs/chat-tools.md](docs/chat-tools.md).
- **Abilities of other plugins in the admin chat** (`chat_abilities`, none by default): Settings > Chat tools > WordPress abilities lists every ability registered by WordPress and other plugins with its category and kind (read-only, changes the site, destructive); checked abilities are offered to the admin chat and still check the user's permissions. Abilities that are not annotated as read-only never run on the assistant's call: the chat shows an action card with the arguments, and the ability runs only after the user clicks Run (once, within an hour, with the arguments stored with the card); the assistant then reports the outcome.
- **Abilities** (WordPress Abilities API, category `wp-cortex`, read-only, `manage_options`, admin index): `wp-cortex/search-content` (hybrid search with field, status, type, author and date filters), `wp-cortex/find-duplicates` (posts sharing a title, field value or text content), `wp-cortex/get-document`, `wp-cortex/list-fields`. Used by the chat agent as tools; not exposed over REST or MCP yet.
- **WP-CLI** commands (`wp cortex index`, `wp cortex status`, `wp cortex search`).

## Requirements

- WordPress 7.0+
- PHP 8.1+
- PHP `pdo_sqlite` extension compiled with FTS5 (the plugin shows an admin notice and stays inactive if `pdo_sqlite` is missing)
- Optional: an OpenAI API key. Without one, indexing still works but no vectors are created. The key is read, in order, from the `OPENAI_API_KEY` environment variable, the `OPENAI_API_KEY` constant, or the key configured under **Settings > Connectors**.

## Installation and quick start

1. Copy the plugin into `wp-content/plugins/wp-cortex` and activate it.
2. Check **Cortex > Settings > Advanced > Storage**: the plugin stores its databases outside the web root when it can (see [Data directory](#data-directory)); if it falls back to uploads, define `WP_CORTEX_DATA_DIR` in `wp-config.php` with a path outside the web root.
3. Add an OpenAI API key under Settings > Connectors (or via env/constant).
4. Open **Cortex > Settings** (the last item of the Cortex menu), choose post types and sources on the Indexing tab, and save.
5. Open **Indexing > Status & stats** on the same screen and click Sync (or run `wp cortex index`).

## Releases and updates

Releases are prepared and published through the GitHub Actions workflows described in [RELEASE.md](RELEASE.md). `Prepare release` creates a reviewed version-bump pull request from `main`; after it is merged, `Publish release` creates the version tag, the release ZIP and the GitHub release.

WP Cortex uses WordPress's native plugin updater with the GitHub `Update URI`. When a newer stable release contains the expected `wp-cortex-vX.Y.Z.zip` asset, it appears in the normal **Dashboard > Updates** and **Plugins** screens. The standard WordPress Update button installs that release; no GitHub credentials are needed. The release metadata is normally cached for 12 hours, while **Dashboard > Updates > Check Again** forces a fresh release check.

## Configuration

Settings are stored in the `wp_cortex_settings` option and edited under **Cortex > Settings**, grouped into the Indexing, Admin chat, Visitor chat, Skills and Advanced tabs. Indexing > Status & stats syncs or rebuilds the index and shows the stats of both indexes. The last tab, Changelog, shows `CHANGELOG.md` with one collapsible panel per version (pending changes and the latest release expanded, the installed version marked).

| Setting | Default | Description |
| --- | --- | --- |
| `admin_post_types` | `post`, `page` | Post types of the admin index (internal types such as templates are not offered; `attachment` indexes media). |
| `public_post_types` | `post`, `page` | Post types of the public index. Only viewable post types are used, and only their published, non-password-protected posts. |
| `admin_statuses` | publish, future, draft, pending, private | Statuses included in the admin index. `publish` is always included. The public index only ever holds `publish`. |
| `auto_sync` | on | Queue changed posts and index them in the background via WP-Cron. |
| `index_yoast` | on | Index Yoast SEO fields (admin index only by default). Requires Yoast SEO. |
| `index_acf` | on | Index ACF fields. Requires ACF. The admin index gets every field; the public index by default gets the content fields of its post types (text, textarea, WYSIWYG, dates, relationship, post object, page link, taxonomy, repeater, group, flexible content) except fields whose name marks them as internal (`id`, `code`, `key`, `note`, `email`, `phone`, `embed`...); switches, numbers, choices, colors, URLs, images, files and users are admin only by default. Values are indexed by field type, whatever the return format: related posts become their titles, terms their names, users their display name (never the email or other account data), images and files their title, alt text and caption, dates ISO dates (`2024-04-29`); repeaters, groups and flexible content are indexed per sub field and password fields never. In the admin index, related posts that are not published are marked with their status (`Title (draft)`); the public index only names published, non-password-protected posts and terms of viewable taxonomies. |
| `meta_keys` | none | Extra post meta keys to index (one per line; admin index only by default). |
| `field_scopes` | none | Index of each field: `"source:name" => both\|admin\|public\|none`. A key ending in `*` matches every name with that prefix (`acf:*`, `yoast:primary_*`); an ACF field's rule also covers its nested values (`acf:team` covers `acf:team.0.name`). Fields without a rule use the extractor's default: always in the admin index, and in the public index only the terms of public taxonomies, the Yoast SEO title, meta description and social (Open Graph, X) titles and descriptions, and the media file type, alt text and caption. ACF content fields of the public post types are public by default as well (see `index_acf`). Everything else (other ACF fields, custom meta, other Yoast fields, file details, parent, template, featured image) is admin only until chosen for the public index. Long ACF text and media alt text and captions are also chunked; these sections follow the rule of their field. Edited as checkboxes under Admin index and Public index, with a filter box; only choices that differ from the default are stored. ACF lists only the field groups stored on posts of that index's post types (not block, options page, term or user groups; block fields are part of the rendered content). Fields with the same name in several groups share one stored value and one rule; they are listed in every group, with a note, and their checkboxes change together. Older settings are migrated: `post_types` (plus `attachment` with `index_media`) fills both post type lists; `acf_public` is dropped. |
| `chunk_size` | 1200 | Approximate characters per chunk (300-6000). |
| `chunk_overlap` | 150 | Characters shared between chunks (0 to half of chunk size). |
| `batch_size` | 10 | Posts per indexing request (1-100). |
| `uninstall_delete_index` | `true` | Delete the data directory with the index databases when the plugin is deleted (Settings > Advanced > Uninstall). When off, the directory and the `wp_cortex_data_dir_name` and `wp_cortex_data_location` options are kept, so a new installation reuses the index; settings, conversations and visitor images are always deleted. |
| `embeddings_enabled` | on | Generate vector embeddings. |
| `embedding_model` | `text-embedding-3-small` | Or `text-embedding-3-large`. |
| `embedding_dimensions` | 1536 | small: 512/1024/1536; large: 256/1024/3072. |
| `chat_enabled` | on | Show the admin chat assistant. |
| `chat_provider` | automatic | Registered AI provider ID (for example `openai`, `anthropic`), or empty to let the AI Client choose a configured one. |
| `chat_model` | provider default | Model ID used with the selected provider (for example `gpt-5.4-mini` or `anthropic/claude-sonnet-4.5`). Chosen from a list loaded from the provider (cached 12 h in the `wp_cortex_models_<provider>` transient, "Refresh models" button reloads it); models without tool calling are listed but disabled. Ignored when the provider is automatic. An explicit model is used as is, without the AI Client's capability matching (some providers, such as OpenRouter, do not declare tool support in their metadata), so pick one that supports tool calling. With a provider but no model, the AI Client must find a tool-capable model in the provider metadata. |
| `chat_instructions` | empty | Custom instructions (up to 4000 characters) appended to the chat system prompt on every message, for example a preferred answer language or tone. They take precedence over the default instructions (such as "answer in the language the user writes in"). |
| `chat_frontend` | off | Also show the admin chat on the front end to administrators (admin index). |
| `chat_tools` | all on, no limits | Per chat (`admin`, `public`): `off` (function names of tools switched off) and `limits` (function name => calls per message, 1-20). Settings > Chat tools. |
| `chat_abilities` | none | Abilities of other plugins (and WordPress) the admin chat may use. Abilities not annotated as read-only run only after the user confirms them in the chat. |
| `public_chat_enabled` | off | Show the visitor chat on the front end (public index only). |
| `public_chat_title` | "Ask a question" | Visitor chat window title (up to 60 characters). |
| `public_chat_welcome` | "Hi! Ask me anything about this website." | First message shown to visitors (up to 500 characters). |
| `public_chat_placeholder` | "Type your question…" | Hint shown in the empty visitor chat message box (up to 80 characters). |
| `public_chat_instructions` | empty | Custom instructions for the visitor chat (up to 4000 characters), separate from `chat_instructions`. |
| `public_chat_provider` | same as admin chat | AI provider of the visitor chat (Settings > Visitor chat > AI assistant). Empty follows `chat_provider`, `chat_model` and `chat_reasoning`; `auto` lets the AI Client choose a configured provider with its default model; a provider ID uses `public_chat_model` and `public_chat_reasoning`. |
| `public_chat_model` / `public_chat_reasoning` | provider / model default | Model and reasoning level of the visitor chat, chosen like `chat_model` and `chat_reasoning`; only used when `public_chat_provider` is a provider ID. Visitor chat summaries have their own `summary_*` settings. |
| `public_chat_rate_limit` | 20 | Visitor chat messages allowed per client IP per hour (1-1000). Administrators are not limited. Counted in `wp_cortex_rate_<hash>` transients. |
| `public_chat_images` | off | Let visitors attach images (for example screenshots) to visitor chat messages. Requires the GD extension and a model with image input. Stored with the conversation while `public_chat_log` is on. |
| `public_chat_attach_icon` | off | Show the attach button (paperclip) next to the visitor chat message box while `public_chat_images` is on. Without it, visitors can still paste or drop images. |
| `public_chat_image_limit` | 10 | Visitor chat images allowed per client IP per hour (1-1000), counted separately from `public_chat_rate_limit` (`wp_cortex_rate_<hash>` transients). Administrators are not limited. |
| `public_chat_log` | on | Store visitor conversations and contact details (Cortex > Visitor chats). When off, conversations are not stored and contact details are not collected (issue reports are still saved). |
| `public_chat_retention` | 0 | Delete visitor conversations without activity for this many days (0-3650, 0 keeps them). |
| `public_chat_navigation` | on | Let the visitor chat open a published page, or reload the current one, after the visitor asks or confirms. |
| `public_chat_store_ip` | on | Store the visitor's IP address with the conversation (and in each visitor message). |
| `public_chat_ip_header` | empty | Trusted proxy header for the visitor IP: `cf-connecting-ip`, `true-client-ip`, `x-real-ip` or `x-forwarded-for` (first address). Empty uses `REMOTE_ADDR`. Used for the message limit and the stored IP; pick one only when a proxy or CDN sets it, otherwise visitors could fake it. The settings screen shows the headers present on the current request. |
| `public_chat_contact` | on | Let the visitor chat collect contact details (requires `public_chat_log`). |
| `public_chat_reports` | on | Let visitors report problems on the site through the visitor chat (Cortex > Issue reports). Works without `public_chat_log`. |
| `public_chat_report_email` | empty | Comma-separated addresses (max 10) notified of each new issue report through `wp_mail()`. Empty sends nothing. |
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
| `summary_provider` | same as admin chat | AI provider of visitor chat summaries (Settings > Visitor chat > Conversation summaries). Empty follows `chat_provider`, `chat_model` and `chat_reasoning`; `auto` lets the AI Client choose a configured provider with its default model; a provider ID uses `summary_model` and `summary_reasoning`. |
| `summary_model` / `summary_reasoning` | provider / model default | Model and reasoning level of summaries, chosen like `chat_model` and `chat_reasoning`, but any model can be picked (summaries make no tool calls); only used when `summary_provider` is a provider ID. |
| `summary_language` | `site` | Language of summaries: `site` (the site language from Settings > General, whatever language the visitor wrote in) or `visitor` (the visitor's language). |
| `summary_instructions` | empty | Custom instructions (up to 4000 characters) added to the summary prompt, for example what to highlight or extra sections. They take precedence over the default summary instructions. |
| `leads_enabled` | on | Track visitors who leave contact details as leads: Cortex > Leads, lead status, AI rating and the Lead card of conversations. Requires `public_chat_log`. |
| `leads_attribution` | off | Record how visitors who chat arrived (campaign parameters, ad click IDs, referrer, landing page, pages of the visit, device) with their conversation, for Cortex > Leads. Requires `leads_enabled` and `public_chat_log`. |
| `leads_attribution_days` | 90 | Days the visitor's browser keeps the first visit and the visit count in local storage (0-730; 0 keeps them for the session only). With the WP Consent API, only after marketing consent. |
| `leads_consent` | `auto` | Marketing consent for attribution: `auto` respects Osano, OneTrust, Cookiebot or the WP Consent API when one is on the page and records when there is none; `require` records only with marketing consent from one of them; `ignore` always records. The chat works without consent. |
| `leads_onetrust_group` | `C0004` | OneTrust cookie category that stands for marketing consent ("Targeting cookies" by default). |
| `integrations` | empty | Settings of each lead integration, keyed by its ID: `enabled`, `events` (`created`, `updated`) and its own fields (for the webhook: `url`, `secret`; for HubSpot: `token`). |
| `leads_extra_params` | empty | Up to 10 more URL parameters to record (lowercase letters, digits, `_` and `-`), for example `ref` or `aff_id`. |
| `skills_enabled` | `true` | Chat skills (Settings > Skills > Settings). When off, the saved skills list is hidden, the `/skills` REST routes return 403 and the admin chat neither uses nor proposes skills; saved skills are kept. |
| `skills_language` | `English` | Language the admin chat writes proposed skills in (name, description and instructions), whatever language the conversation is in. Empty means English. |
| `skills_instructions` | empty | Custom instructions (up to 4000 characters) added to the admin chat system prompt while skills are on, for example how skills should be written or when to offer one. |
| `chat_reasoning` | model default | Reasoning (thinking) effort, chosen next to the model and only applied when a model is selected. Levels depend on the provider: OpenAI `none`–`xhigh` (sent as `reasoning.effort`), Anthropic `low`–`max` (`output_config.effort`; the thinking mode stays at the model default), OpenRouter `none`–`xhigh` (`reasoning.effort`). Other providers show no selector unless added through the reasoning filters. A level the model does not support makes the request fail. |

### Data directory

The databases live in a hidden directory with a random name, `.wp-cortex-<random>/`, generated once and stored in the `wp_cortex_data_dir_name` option. On first use the plugin picks its parent from these locations, the first one PHP can actually write to (tested by writing a file), and stores the choice in the `wp_cortex_data_location` option:

1. **WP Engine:** `_wpeprivate/` in the site root, which WP Engine never serves.
2. **Pantheon:** `wp-content/uploads/private/`, which Pantheon never serves (created when missing).
3. **Outside the web root:** the directory above the one that serves the site (for example `/home/user/` above `public_html/`), unless it is inside the server's document root.
4. **Uploads (fallback):** `wp-content/uploads/`.

The location is stored as a key, not a path, so a copy of the site on another environment resolves it again (its index starts empty there; re-run an index). `WP_CORTEX_DATA_DIR` overrides the automatic choice. Data that earlier versions stored in uploads is moved to the first writable private location once, the first time an administrator opens the admin while no index run is running (renamed, or copied and deleted across file systems); if no private location is writable, it stays in uploads. Settings > Advanced > Storage shows the location next to the data directory.

In uploads, the directory receives `index.php`, `.htaccess` and `web.config` deny rules, but **Nginx ignores `.htaccess`**. The name starts with a dot because most Nginx configurations for WordPress (Local, for example) refuse hidden paths; where the server has no such rule, the fallback relies only on the unguessable name. Directories created by earlier versions (`wp-cortex-<random>`) are renamed to the hidden name automatically. Settings > Advanced > Storage warns when the fallback is used and shows a public access check: the server requests its own data directory URL without cookies and reports whether the directory lists its files and whether `public.sqlite` and `admin.sqlite` can be downloaded (a response only counts when it starts with the SQLite header). The check also runs for private locations inside the WordPress directory (WP Engine, Pantheon), so it confirms that the host really blocks them. The result is green when nothing is reachable, red when something is and yellow when the server could not reach itself; it is cached for 12 hours (or until a database is created or deleted) and the Test again button runs it immediately. While the last check found a file downloadable, Settings > Indexing > Status & stats and the dashboard widget show an alert that links to it. A location outside the WordPress directory is not tested. If a file is downloadable, add a rule like this to the Nginx server block (or ask the host to):

```nginx
location ~ /\. { deny all; }
```

For real protection, put the data outside the web root:

```php
define( 'WP_CORTEX_DATA_DIR', '/var/lib/wp-cortex' );
```

The directory must be writable by the web server user. Changing `WP_CORTEX_DATA_DIR` does not move existing databases; move them by hand or re-run an index.

Images visitors attach in the visitor chat are stored in the same directory under `visitor-images/<conversation ID>/` with random file names, served to administrators only through the REST API and deleted with their conversation (manually, by the retention cron or on uninstall).

### Filters

- `wp_cortex_extractors` (`Extractor[] $extractors`): add, remove or reorder extractors. Non-`Extractor` entries are discarded. Extractors that also implement `DescribesFields` list their fields under Admin index and Public index, so each field's index can be chosen.
- `wp_cortex_openai_api_key` (`string $key`): override the OpenAI API key.
- `wp_cortex_chat_system_instruction` (`string $instruction`, `array $context`): modify the chat assistant's system instruction. `$context` holds `screen` and `post_id`.
- `wp_cortex_chat_reasoning_levels` (`string[] $levels`, `string $provider`): reasoning levels offered for a provider (lowest first).
- `wp_cortex_public_chat_system_instruction` (`string $instruction`, `array $context`): modify the visitor chat's system instruction. `$context` holds `post_id` (the page being viewed, 0 for none).
- `wp_cortex_admin_chat_tools` (`array $tools`, `AdminContext $context`): add, change or remove tools of the admin chat. Keys are tool names, values `Tool` objects or definition arrays (an array changes only the keys it gives of the tool with the same name). Runs after the theme's `wp-cortex/tools/admin/` files. See [docs/chat-tools.md](docs/chat-tools.md).
- `wp_cortex_public_chat_tools` (`array $tools`, `PublicContext $context`): the same for the visitor chat, after the theme's `wp-cortex/tools/public/` files. Abilities and admin tools are refused.
- `wp_cortex_visitor_chat_summary_system_instruction` (`string $instruction`, `array $chat`): modify the system instruction of visitor chat summaries (after `summary_language` and `summary_instructions` are applied). `$chat` is the conversation from `VisitorChatStore::get()`.
- `wp_cortex_lead_rating_system_instruction` (`string $instruction`): modify the system instruction of the AI lead rating (Cortex > Leads). The model must still answer with the JSON object the instruction describes.
- `wp_cortex_public_chat_client_ip` (`string $ip`): client IP used for the visitor chat message limit and stored with visitor conversations. Defaults to `REMOTE_ADDR`, or the address from the header chosen in `public_chat_ip_header`; use the filter for other proxy setups.
- `wp_cortex_chat_reasoning_options` (`array $options`, `string $provider`, `string $level`): custom request options that apply a reasoning level, for providers without a built-in mapping.
- `wp_cortex_lead_payload` (`array $payload`, `array $chat`): modify the lead payload given to the lead actions, the integrations and the CSV export.
- `wp_cortex_lead_integrations` (`Integration[] $integrations`): add or remove lead integrations (after the theme's `wp-cortex/integrations/` files). See [docs/integrations.md](docs/integrations.md).

### Actions

For CRM integrations (the built-in ones under Settings > Leads > Integrations use them too; see [docs/integrations.md](docs/integrations.md)). They fire only while `leads_enabled` is on, once per lead at the end of the request that changed it, and only when something really changed. `$payload` is the whole lead (`Leads\LeadPayload::build()`, `version` 1): `id`, `site`, `lead_at`, `status`, `contact` (`first_name`, `last_name`, `email`, `phone`, `address`, `company`, `website`, `request`), `rating` (`rating`, `score`, `intent`, `interest`, `company`, `role`, `budget`, `timeline`, `next_step`, `reason`, `rated_at`; `null` until rated), `attribution` (`null` without attribution or consent; otherwise `first` and `last` visit, each with `at`, `channel`, `source`, `medium`, `landing` and `referrer` URLs, `utm` with every UTM parameter, `click_ids` with every ad click ID, empty when not recorded, and `extra` with the extra parameters, plus `visits`, `consent` and `device`), `note`, `started_at`, `updated_at`, `message_count`, `start_page` and `conversation_url`. Use `id` as the external ID to update the CRM record. Send slow requests from a scheduled event, as the built-in integrations do.

- `wp_cortex_lead_created` (`array $payload`, `int $id`): a visitor conversation became a lead (changes made in the same request are included; no separate update fires).
- `wp_cortex_lead_updated` (`array $payload`, `string[] $changes`, `int $id`): a lead changed; `$changes` lists `contact` (the visitor added or corrected contact details), `status` (an administrator changed it) or `rating` (rated with AI).

## Indexing

- **Sync** walks all eligible posts, skips unchanged ones and removes documents that are no longer eligible.
- **Rebuild** deletes both database files first and indexes everything from scratch. Use it after changing chunk settings or when the schema is damaged.
- **Skipping**: for each scope a content hash is computed over the document columns, fields and chunk hashes. If it matches the stored hash (and, when embeddings are active, no chunk lacks a vector for the current embedding signature), the post is skipped.
- **Embedding reuse**: each chunk is hashed (title, section heading, text). Existing vectors with the same hash and signature are reused; only missing ones are sent to OpenAI, in one pass per batch (96 inputs per request, up to 3 attempts on 429/5xx).
- **Changing post types or fields**: run Sync. Documents whose fields changed get a new content hash and are rewritten; posts of deselected post types are removed.
- **Stale removal**: documents not seen by a run (deleted posts, changed status, deselected post types) are deleted when the run finishes. Posts that become ineligible are also removed immediately when processed.
- **Auto-sync**: on save, trash/untrash/delete, status transition, term changes and changes of `_yoast_wpseo_*` or configured meta keys (with media indexing also alt text, attachment metadata, attach/detach, and the media attached to a post whose status or password changes), post IDs are queued (option `wp_cortex_sync_queue`) and processed by a single WP-Cron event ~15 seconds later, 50 posts at a time. It pauses while a full run is active.
- Run state is kept in the `wp_cortex_index_run` option and shared by the admin UI, REST and WP-CLI; a lock option prevents concurrent batches (stale after 300 s).
- **Background worker**: a run started from the admin is processed by worker requests to `admin-ajax.php` (action `wp_cortex_index_worker`, authorized by a token derived from the run ID and the site salts). Each one processes batches for about 20 seconds and then starts the next one with a non-blocking loopback request. If no batch finishes for 10 seconds, the run is restarted by the `wp_cortex_index_watchdog` WP-Cron event (checked every minute while a run is active; it processes batches in the cron request itself) and by the status requests of the admin page, which also process one batch each when loopback requests are blocked. The admin page polls `GET /index` every 3 seconds while a run is active (every 15 seconds otherwise, paused while the tab is hidden).

## WP-CLI

```
wp cortex index [--rebuild]
wp cortex status
wp cortex search <query> [--scope=<admin|public>] [--mode=<hybrid|keyword|semantic>] [--limit=<n>] [--type=<post_type>]
```

`index` runs a full Sync (or Rebuild with `--rebuild`) with a progress bar and prints a summary. `status` shows per-scope statistics and the last run. `search` prints matching documents (id, type, status, score, title, best heading); scope defaults to `admin`, mode to `hybrid`.

## REST API

Namespace `wp-cortex/v1`. All routes require the `manage_options` capability (and the usual REST nonce/auth), except `POST /public-chat/message` (which also stores the optional `attribution` object the widget sends while `leads_enabled` and `leads_attribution` are on). The `/leads` routes also refuse requests while `leads_enabled` is off, which is open while the visitor chat is enabled.

| Method | Route | Description |
| --- | --- | --- |
| GET | `/index` | Run state, per-scope stats, eligible post count. Restarts a stalled background run. |
| POST | `/index/start` | Start a run in the background. Param `mode`: `sync` (default) or `rebuild`. |
| POST | `/index/batch` | Process one batch of the running run in this request (not needed with the background worker). Response includes `locked: true` if another request holds the lock. |
| POST | `/index/pause` | Pause the running run (a batch in progress is discarded and processed again on resume). |
| POST | `/index/resume` | Resume the paused run in the background. |
| POST | `/index/cancel` | Cancel the running or paused run. |
| GET | `/index/storage-check` | Public access check of the data directory (cached for 12 hours, run when nothing is cached). |
| POST | `/index/storage-check` | Run the public access check again. |
| GET | `/chat/conversations` | The current user's conversations: `{ conversations: [ { id, title, updated_at } ] }`. |
| GET | `/chat/conversations/<id>` | `{ id, title, transcript: [ items ] }`; 404 when not owned. |
| DELETE | `/chat/conversations/<id>` | `{ deleted: true }`. |
| GET | `/chat/models` | Params `provider` (registered provider ID), `refresh` (0/1). Returns `{ provider, models: [ { id, name, tools } ], cached }` for text-generation models, tool-capable first. 400 for unknown or unconfigured provider, 502 when the provider request fails. |
| POST | `/public-chat/message` | Visitor chat. Params `message` (max 2000 chars; may be empty when `image` is sent), `image` (optional `data:image/png\|jpeg\|webp\|gif;base64,…` URL, at most 4 MB decoded, only while `public_chat_images` is on), `history` (previous turns `[ { role: user\|assistant, text, image: bool } ]`, the last 12 are used), `post_id` (page being viewed), `session` (32 hex chars, identifies the stored conversation), `page_url` (URL of the page being viewed, stored with issue reports when it is on this site's host). Returns `{ items }` with `assistant`, `sources` (`[ { id, title, url, snippet } ]`), `navigate` (`{ id, title, url }`, the browser opens the page; with `reload: true` it reloads the current page instead, and `url` is the page being viewed) or `error` items. Public index only. 400 for an empty message or an invalid image, 403 when the visitor chat is disabled, 429 over the message or image limit. |
| GET | `/skills` | All chat skills: `{ skills: [ { id, name, description, instructions, active, source, use_count, last_used_at, created_by, created_at, updated_at } ] }`, most used first. |
| POST | `/skills` | Create a skill. Params `name` (normalized to a lowercase hyphenated slug, unique), `description`, `instructions`, `active` (default true), `source` (`user` or `agent`). 201 with the skill; 409 when the name is taken. |
| GET | `/skills/<id>` | One skill; 404 when missing. |
| PUT/PATCH | `/skills/<id>` | Update the fields sent (`name`, `description`, `instructions`, `active`). |
| DELETE | `/skills/<id>` | `{ deleted: true }`. |
| GET | `/visitor-chats` | Params `filter` (`unread`, `contact` or empty), `search`, `page`, `per_page` (max 100). `{ chats: [ { id, preview, message_count, contact, has_contact, is_read, admin_note, ip, ip_forwarded, summary, summary_at, summary_stale, forwarded_to, forwarded_at, page, created_at, updated_at } ], total, pages, counts: { all, unread, contact } }`; `search` also matches IP addresses, latest activity first. |
| GET | `/visitor-chats/<id>` | One conversation with its `transcript` (visitor messages with an image carry its file name in `image`); 404 when missing. |
| GET | `/visitor-chats/<id>/image?name=<name>` | An attached image as `{ name, mime, data }` (base64); 404 when missing. |
| PUT/PATCH | `/visitor-chats/<id>` | Update `is_read`, `admin_note` and/or `lead_status` (`new`, `contacted`, `qualified`, `won`, `lost`, `spam`; only changes leads). |
| GET | `/leads/report` | Params `days` (7, 30, 90, 365 or 0 for all time; default 30), `channel` (a channel, `unknown` or empty). `{ from, to, kpis: { leads, conversations, rate, hot, days_to_lead: { value, previous } }, trend: { unit: day\|week\|month, points: [ { date, conversations, leads } ] }, channels, ratings, intents, statuses, campaigns, landing }`. |
| GET | `/leads` | Leads (conversations with `lead_at`). Params `days`, `channel`, `status`, `rating` (`hot`, `warm`, `cold`, `unrated`), `search`, `orderby` (`lead_at`, `score`, `channel`, `campaign`, `status`), `order`, `page`, `per_page` (max 100). `{ leads, total, pages, counts }` (`counts`: leads per status, all time). |
| GET | `/leads/export` | The same filters, every match up to 5000: `{ leads, total, truncated }`. |
| POST | `/leads/<id>/rate` | Rates the lead with the summary model; returns the conversation with `lead_rating`, `lead_score`, `lead_intent`, `qualification`, `qualified_at`. |
| POST | `/leads/rate-next` | Rates the newest lead without a rating: `{ rated, remaining }`. |
| DELETE | `/visitor-chats/<id>` | `{ deleted: true }`. |
| POST | `/visitor-chats/<id>/summary` | Generates (or regenerates) the AI summary; returns the conversation with `summary`, `summary_at`, `summary_stale`. 502 when the AI request fails. |
| POST | `/visitor-chats/<id>/forward` | Params `to` (comma, semicolon or space separated, max 10 addresses), `message` (optional), `summarize` (default true: generate the summary first when it is missing or outdated; 502 and nothing sent when that fails). Emails the conversation; returns it with `forwarded_to`, `forwarded_at`. 400 for invalid addresses, 502 when `wp_mail()` fails (the message includes the mailer error). |
| POST | `/visitor-chats/bulk` | Params `action` (`read`, `unread`, `delete`), `ids` (max 100). `{ updated }`. |
| GET | `/issue-reports` | Params `status` (`open`, `resolved`, `dismissed` or empty for all), `search` (description, quoted text, page URL, note), `page`, `per_page` (max 100). `{ reports: [ { id, chat_id, category, category_label, description, excerpt, page_url, page: { id, title, url, edit_url }, status, admin_note, created_at, updated_at, resolved_at, resolved_by } ], total, pages, counts: { all, open, resolved, dismissed } }`, newest first. `chat_id` is 0 when the conversation no longer exists. |
| GET | `/issue-reports/<id>` | One report; 404 when missing. |
| PUT/PATCH | `/issue-reports/<id>` | Update `status` and/or `admin_note`. Resolving or dismissing records the time and user. |
| DELETE | `/issue-reports/<id>` | `{ deleted: true }`. |
| POST | `/issue-reports/bulk` | Params `action` (`open`, `resolved`, `dismissed`, `delete`), `ids` (max 100). `{ updated }`. |
| POST | `/chat/message` | Params `conversation_id` (0 = new), `message` (max 4000 chars), `context` (`{ screen, post_id }`). Returns `{ conversation_id, title, items, actions }`. AI failures are returned as an `error` item with status 200. |
| POST | `/chat/conversations/<id>/actions/<action>` | Runs or cancels an action card (`ability_action` item) of the user's conversation. Params `decision` (`run` or `cancel`), `context` (as for `/chat/message`). Runs the ability with the input stored with the card, then returns the assistant's answer like `/chat/message` (the updated card first). 404 when the action is missing, 409 when it was already run or cancelled. |

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
    Document.php         Normalized object; for_scope() keeps a scope's fields
    FieldPolicy.php      field_scopes rules and the field catalog of the settings screen
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
    Tools/                 Chat tools: Tool interface, ToolRegistry (built-in, theme and filter tools), AgentLoop
      Admin/               Admin chat tools and AdminContext
      Public/              Visitor chat tools and PublicContext (public index only)
    PromptFactory.php      Shared provider/model/reasoning prompt setup
    RateLimiter.php        Visitor chat message and image limits per IP
    VisitorImages.php      Visitor chat images: validation, re-encoding, storage
    ModelCatalog.php       Provider model list (cached)
    ConversationStore.php  MySQL conversation table
    SkillStore.php         MySQL chat skills table
    VisitorChatStore.php   MySQL visitor chats table (transcripts, contact details, IP, attribution, summary, lead status and rating, admin notes)
    VisitorChatReport.php  Plain-text rendering of a visitor chat (summary input, email body)
    VisitorChatSummarizer.php  AI summary of a visitor chat
    VisitorChatMailer.php  Forwards a visitor chat through wp_mail()
    IssueReportStore.php   MySQL issue reports table (problems visitors report in the visitor chat)
    IssueReportMailer.php  Emails new issue reports through wp_mail()
    ClientIp.php           Visitor IP (REMOTE_ADDR or a trusted proxy header)
  Leads/
    Attribution.php        Cleans the widget's attribution, channel grouping, device and country
    LeadQualifier.php      AI rating of a lead
    LeadReport.php         Report of the Leads screen
  Rest/                    IndexController, ChatController, PublicChatController, SkillController, VisitorChatController, IssueReportController, LeadController
  Cli/Command.php          WP-CLI commands
  Admin/                   Menu, Settings page (with the Changelog tab), Index status section, Skills page, Visitor chats page, Leads page, Issue reports page, Chat panel, Dashboard widget
  Frontend/FrontendChat.php  Admin or visitor chat on the front end
  Frontend/ChatAppearance.php  Visitor chat appearance settings as CSS custom properties and classes
assets/                  JS (no build step) and CSS for the admin, the chat panels and the visitor chat
```

## Roadmap

None of the following is implemented yet.

- **Visitor chat placement**: a block or shortcode to embed the visitor chat in a page instead of the floating button.
- **MCP access for external agents** via the WordPress Abilities API and MCP Adapter: public tools use the public index; admin tools use the admin index and are capability-gated.
- **Skill maintenance**: counting failed skill runs and deactivating skills that keep failing, picking relevant skills by embedding similarity when there are many, and detecting near-duplicate proposals.
- **Issue reports in the admin chat**: a read-only ability to list open issue reports, so the admin chat can summarize them or open the affected pages.
- **Visitor chat leads**: automatic email notification (with the summary) when a visitor leaves contact details, and personal data export/erasure (Tools > Export/Erase Personal Data) by the visitor's email.
- **More CRM integrations** next to the webhook and HubSpot: Salesforce, passing the campaign parameters and ad click IDs through (for example `gclid` for offline conversion import into Google Ads).
- **Optional sqlite-vec acceleration** for vector search.
- **Media file contents**: extracting text from PDFs and other documents in the media library.

## License

GPL-2.0-or-later.
