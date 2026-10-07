# Chat tools

The admin chat and the visitor chat answer by calling **tools** (functions the model can call). Each chat has its own set:

| Chat | Built-in tools | Classes | Theme folder | Filter |
|---|---|---|---|---|
| Admin | the `wp-cortex/*` abilities, `open_post`, `open_admin_page`, `select_tab`, `propose_skill`, `use_skill` | `src/Chat/Tools/Admin/` | `wp-cortex/tools/admin/` | `wp_cortex_admin_chat_tools` |
| Visitor | `search_site`, `get_page`, `go_to_page`, `save_contact_details`, `report_issue` | `src/Chat/Tools/Public/` | `wp-cortex/tools/public/` | `wp_cortex_public_chat_tools` |

A theme or a plugin can add tools, change parts of a built-in tool (for example its description or its system prompt lines) or remove a tool.

## How a turn uses tools

1. The agent creates the turn context: `AdminContext` (screen, current post, posts seen, UI actions, skills) or `PublicContext` (the visitor's conversation, current page, pages seen, navigation). `$context->search()` reads the admin index in the admin chat and **only the public index** in the visitor chat.
2. `ToolRegistry::build()` takes the built-in tools, applies the theme files, then the filter, and keeps the tools whose `is_available()` returns true.
3. The model gets each tool's name, `description()` and `parameters()` (JSON schema); the system prompt gets each tool's `instructions()` lines.
4. `AgentLoop` runs the model and calls `execute( $args, $context )` for every function call until the model answers.

Tools are created for each turn, so a tool can keep state of that turn in its properties.

## Theme files

Put one PHP file per tool in the theme:

```
wp-content/themes/my-theme/wp-cortex/tools/
├── admin/                       Admin chat (administrators)
│   └── open_crm_record.php
└── public/                      Visitor chat (anyone on the site)
    ├── opening_hours.php        New tool
    ├── report_issue.php         Changes the built-in report_issue
    ├── go_to_page.php           Removes the built-in go_to_page
    └── _helpers.php             Not loaded (starts with "_")
```

- The file name is the tool name: letters, digits, `_` and `-`, up to 64 characters.
- A child theme file replaces the parent theme file with the same name.
- The file returns a **definition array**, a **`Tool` object** or **`false`** (remove the tool).

### New tool

```php
<?php
// wp-cortex/tools/public/opening_hours.php
return array(
	'label'        => 'Opening hours',
	'description'  => 'Returns the opening hours of the office. Call it when the visitor asks when the office is open.',
	'parameters'   => array(
		'type'       => 'object',
		'properties' => array(
			'day' => array(
				'type'        => 'string',
				'description' => 'Optional. Day of the week in English, for example "monday".',
			),
		),
	),
	'instructions' => 'When the visitor asks about opening hours, call opening_hours instead of searching the site.',
	'available'    => static fn( $context ) => (bool) get_option( 'my_office_hours' ),
	'callback'     => static function ( array $args, $context ) {
		return array( 'hours' => get_option( 'my_office_hours' ) );
	},
);
```

`description` and `callback` are required for a new tool.

### Changing a built-in tool

Give only the keys to change; the others stay as they are.

```php
<?php
// wp-cortex/tools/public/report_issue.php
return array(
	// A Closure gets the context and the built-in value.
	'description'  => static fn( $context, $inherited ) => $inherited . ' Also use it for problems with the online shop.',
	'instructions' => static function ( $context, array $inherited ) {
		$inherited[] = 'Before reporting a shop problem, ask which product it is about.';
		return $inherited;
	},
);
```

```php
<?php
// wp-cortex/tools/admin/open_post.php: log every post opened by the assistant.
return array(
	'callback' => static function ( array $args, $context, $base ) {
		$result = $base->execute( $args, $context );
		error_log( 'Cortex opened post ' . (int) ( $args['post_id'] ?? 0 ) );
		return $result;
	},
);
```

To change an ability, use its function name, for example `wpab__wp-cortex__list-fields.php`.

### Definition keys

| Key | Type | Notes |
|---|---|---|
| `label` | string | Name shown in the admin. |
| `description` | string or `Closure( $context, string $inherited )` | What the model reads to decide when to call the tool. |
| `parameters` | array, null or `Closure( $context, ?array $inherited )` | JSON schema of the arguments (`type: object`). |
| `instructions` | string, string[] or `Closure( $context, array $inherited )` | Lines added to the system prompt while the tool is offered. |
| `available` | bool or callable `( $context )` | When overriding a built-in tool it can only hide the tool, never offer a tool the plugin does not offer (for example when its setting is off). |
| `callback` | callable `( array $args, $context, ?Tool $base )` | Returns the response for the model, usually an array. Return `array( 'error' => '...' )` on failure. `$base` is the built-in tool being overridden, or null. |

Only Closures (and invokable objects) are called for `description`, `parameters` and `instructions`. Strings and arrays are used as they are, even if they happen to name a function.

## Tool classes

For larger tools, return an object implementing `WPCortex\Chat\Tools\Tool`. Extending `AbstractTool` gives defaults for `label()`, `is_available()`, `parameters()` and `instructions()`:

```php
<?php
// wp-cortex/tools/admin/open_crm_record.php
use WPCortex\Chat\Tools\AbstractTool;
use WPCortex\Chat\Tools\ToolContext;

final class My_Open_Crm_Record extends AbstractTool {
	public function name(): string {
		return 'open_crm_record';
	}

	public function description( ToolContext $context ): string {
		return 'Opens the CRM record of a customer by email address.';
	}

	public function parameters( ToolContext $context ): ?array {
		return array(
			'type'       => 'object',
			'properties' => array( 'email' => array( 'type' => 'string' ) ),
			'required'   => array( 'email' ),
		);
	}

	public function execute( array $args, ToolContext $context ) {
		$url = my_crm_url( sanitize_email( $args['email'] ?? '' ) );

		if ( ! $url ) {
			return array( 'error' => 'No record for this email address.' );
		}

		$context->navigate( array( 'url' => $url, 'title' => 'CRM record' ) ); // AdminContext.

		return array( 'ok' => true );
	}
}

return new My_Open_Crm_Record();
```

## Filters

```php
add_filter(
	'wp_cortex_public_chat_tools',
	static function ( array $tools, $context ) {
		unset( $tools['go_to_page'] );                                     // Remove.
		$tools['get_page'] = array( 'description' => 'Reads one page.' );  // Change (merged with the built-in tool).
		$tools['opening_hours'] = new My_Opening_Hours_Tool();              // Add.

		return $tools;
	},
	10,
	2
);
```

`wp_cortex_admin_chat_tools` works the same way for the admin chat. Filters run after the theme files.

## What the context offers

Both contexts:

- `scope()`: `admin` or `public`.
- `search()`: `SearchService` over the index of the scope (`search()`, `get_document()`, `find_authors()`, ...).
- `set_item( $key, $item )`: a transcript item shown after the answer, for example `array( 'role' => 'notice', 'text' => '...' )`. A later item with the same key replaces the earlier one.

`AdminContext`: `screen()`, `is_frontend()`, `post_id()`, `user_id()`, `admin_pages()`, `tabs()`, `skills()`, `is_known_post()`, `add_known_posts()`, `navigate( array( 'url', 'title', ... ) )`, `add_action()`. Responses of every admin tool are scanned for posts (`results`, `groups`, or a single `id` with a `title`), so posts a custom tool returns can be cited as `#ID` cards and opened with `open_post`.

`PublicContext`: `chat_id()`, `post_id()`, `page_url()`, `image_name()`, `current()`, `post_types()`, `get_public_document()`, `public_authors()`, `remember( $id, $title, $url, $snippet )` (lets the answer link the page as `[label](#ID)`), `navigate( $id, $title, $url )`.

## Rules for visitor tools

The visitor chat is open to anyone, so its tools must keep the plugin's guarantees:

- Read site data only through `$context->search()` (the public index) or data that is already public on the site. Never read drafts, private posts, users, orders, stored conversations or other visitors' data.
- Never let the visitor trigger privileged actions. Treat every argument as untrusted input written by the visitor.
- Only navigate to pages of the public index (`get_public_document()`).
- Keep responses small: they are sent to the model on every step.

The registry refuses abilities (`wpab__*`) and the plugin's admin tools in the visitor chat. A tool that throws an exception is left out (while being described) or answers with a generic error (while running), so a broken tool does not break the chat. Misconfigured tools are reported with `_doing_it_wrong()` (visible with `WP_DEBUG`).
