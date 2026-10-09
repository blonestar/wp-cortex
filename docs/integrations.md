# Lead integrations

Integrations send leads from the visitor chat to a CRM or another tool. They are configured under **Cortex > Settings > Leads > Integrations** and only work while leads are on (`leads_enabled`).

## How leads are sent

1. A conversation becomes a lead when the visitor leaves an email address, phone number, postal address or website. Later, the visitor may add or correct contact details, an administrator may change the lead's status, or the lead may be rated with AI.
2. At the end of the request, `wp_cortex_lead_created` or `wp_cortex_lead_updated` fires once per lead, and only when something really changed. The request may be a chat message, a status change or a rating.
3. For every integration that is switched on, configured and subscribed to the event, a delivery is scheduled on the `wp_cortex_deliver_lead` cron hook. The visitor's chat request is never slowed down by a CRM.
4. The delivery reads the lead again and sends it as it is at that moment. A failed delivery is retried after 5 and 25 minutes. Every attempt is logged under the integrations, in the `wp_cortex_integration_log` option (the latest 50).

Every event carries the **whole lead** with the same `id`. The receiver should use `id` as the external ID and update its record on `updated`. If it matches leads by email address, a visitor who corrects their email creates a duplicate.

## The lead payload

`Leads\LeadPayload::build()`, `version` 1. Keys are stable: every UTM parameter and click ID is present, and empty when it was not recorded.

```json
{
  "version": 1,
  "id": 1315,
  "site": "https://example.com/",
  "lead_at": "2026-10-09 20:23:39",
  "status": "new",
  "contact": { "first_name": "Ana", "last_name": "", "email": "ana@example.com", "phone": "", "address": "", "company": "", "website": "", "request": "Quote for a new website" },
  "rating": { "rating": "hot", "score": 80, "intent": "quote", "interest": "", "company": "", "role": "", "budget": "", "timeline": "", "next_step": "", "reason": "", "rated_at": "" },
  "attribution": {
    "first": { "at": "", "channel": "paid_search", "source": "google", "medium": "cpc", "landing": "https://example.com/offer/", "referrer": "https://www.google.com/",
               "utm": { "utm_source": "google", "utm_medium": "cpc", "utm_campaign": "spring", "utm_term": "", "utm_content": "", "utm_id": "", "utm_source_platform": "" },
               "click_ids": { "gclid": "Cj0K…", "gbraid": "", "wbraid": "", "msclkid": "", "fbclid": "", "li_fat_id": "", "ttclid": "", "twclid": "" },
               "extra": { "ref": "partner-1" } },
    "last": { "…": "same keys as first" },
    "visits": 2,
    "consent": "onetrust",
    "device": { "type": "desktop", "browser": "Chrome", "os": "Windows", "language": "en-US", "timezone": "Europe/Belgrade", "screen": "1920x1080", "country": "RS" }
  },
  "note": "",
  "started_at": "2026-10-09 20:20:01",
  "updated_at": "2026-10-09 20:23:39",
  "message_count": 6,
  "start_page": "https://example.com/offer/",
  "conversation_url": "https://example.com/wp-admin/admin.php?page=wp-cortex-visitor-chats#chat=1315"
}
```

- `rating` is `null` until the lead is rated.
- `attribution` is `null` when attribution is off or the visitor gave no marketing consent.
- Times are UTC.
- A test lead from the **Send test lead** button has `"id": 0` and `"test": true`.
- Use the `wp_cortex_lead_payload` filter to add your own keys.

## Built-in: Webhook

The Webhook integration posts `{ "event": "created" | "updated" | "test", "changes": [...], "sent_at": "…", "lead": { …payload } }` as JSON to an HTTPS URL. It is meant for Zapier, Make, n8n, a CRM that accepts webhooks, or your own code.

- `X-Cortex-Event` is `lead.created`, `lead.updated` or `lead.test`.
- With a signing secret, `X-Cortex-Signature` is `sha256=` followed by the HMAC-SHA256 of the raw body. Check it on the receiving side:

```php
$expected = 'sha256=' . hash_hmac( 'sha256', file_get_contents( 'php://input' ), $secret );
if ( ! hash_equals( $expected, $_SERVER['HTTP_X_CORTEX_SIGNATURE'] ?? '' ) ) {
	http_response_code( 401 );
	exit;
}
```

- Any 2xx answer counts as delivered. Anything else is retried.
- Addresses on the local or private network are refused (`wp_safe_remote_post()`).

## Built-in: HubSpot

Creates or updates a HubSpot contact for each lead. It needs the access token of a HubSpot private app with the scopes `crm.objects.contacts.read`, `crm.objects.contacts.write`, `crm.schemas.contacts.read` and `crm.schemas.contacts.write`.

- **Matching.** The contact is matched by the custom unique property `wp_cortex_lead_id` (`<site>#<lead ID>`, for example `example.com#1315`). A corrected email address therefore updates the same contact, and several sites can share one HubSpot account. If HubSpot already has a contact with the lead's email address, that contact is updated and gets the lead ID. When the same visitor becomes a lead again, the contact holds the data of the lead that was sent last.
- **Contact details.** `email`, `firstname`, `lastname`, `phone`, `address`, `company` and `website` are sent only when the lead has them. An empty field never clears a value in HubSpot.
- **Custom properties.** On the first delivery, a "WP Cortex" property group is created with these properties:
  - `wp_cortex_lead_status`, `wp_cortex_lead_rating`, `wp_cortex_lead_score`, `wp_cortex_lead_intent`;
  - `wp_cortex_request`, `wp_cortex_conversation_url` and `wp_cortex_visits`;
  - for the latest (`last`) and the first (`first`) visit: `wp_cortex_<visit>_channel`, `_landing`, `_referrer`, every UTM parameter (`wp_cortex_last_utm_source`, …) and every ad click ID (`wp_cortex_last_gclid`, …).
  
  The rating is sent once the lead is rated. Visit properties are sent only when the lead has attribution.
- **Property check.** A transient (`wp_cortex_hubspot_schema`) records for a week that the properties exist. If a property is deleted in HubSpot, it is created again on the next delivery.
- **Send test lead.** Creates or updates the contact `Test Lead` (`test@example.com`, key `<site>#test`). You can delete it in HubSpot.
- **Errors.** A refused token, a missing scope or a HubSpot error is shown in the delivery log and retried like any failed delivery.

## Writing your own integration

An integration is a class extending `WPCortex\Integrations\Integration`. It describes its settings and sends one lead. Storing the settings, scheduling, retries and the log are handled for it.

| Method | Required | Purpose |
|---|---|---|
| `id()` | yes | Unique ID: lowercase letters, digits, `_`, `-`. |
| `label()` | yes | Name in the settings. |
| `send( $event, $payload, $changes, $settings )` | yes | Send one lead. Return `true`, or a `WP_Error` (which is retried). |
| `description()` | no | One sentence shown in the card. |
| `fields()` | no | Settings fields (see below). |
| `is_configured( $settings )` | no | Defaults to "every required field is filled in". |
| `validate( $settings )` | no | Return a `WP_Error` to refuse switching it on (the reason is shown). |

Each field in `fields()` has these keys:

- `type`: `text`, `url`, `secret`, `textarea`, `select` or `checkbox`.
- `label`
- `description`
- `placeholder`
- `default`
- `required`
- `options`, for `select`.

A `secret` is never shown again once saved; leaving it empty keeps the stored value. The settings also hold `enabled` and `events` (`created`, `updated`).

### From the theme

Add a file in `wp-cortex/integrations/` of the active theme that returns the integration. A child theme file replaces the parent theme file with the same name. Files starting with `_` are skipped.

```php
<?php
// wp-content/themes/my-theme/wp-cortex/integrations/my-crm.php

use WPCortex\Integrations\Integration;

return new class() extends Integration {

	public function id(): string {
		return 'my-crm';
	}

	public function label(): string {
		return 'My CRM';
	}

	public function fields(): array {
		return array(
			'api_key' => array( 'type' => 'secret', 'label' => 'API key', 'required' => true ),
		);
	}

	public function send( string $event, array $payload, array $changes, array $settings ) {
		$response = wp_remote_post(
			'https://api.my-crm.example/leads/' . $payload['id'],
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $settings['api_key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'external_id'  => $payload['id'],
						'email'        => $payload['contact']['email'],
						'phone'        => $payload['contact']['phone'],
						'utm_source'   => $payload['attribution']['last']['utm']['utm_source'] ?? '',
						'utm_campaign' => $payload['attribution']['last']['utm']['utm_campaign'] ?? '',
						'gclid'        => $payload['attribution']['last']['click_ids']['gclid'] ?? '',
					)
				),
				'method'  => 'PUT',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		return $code >= 200 && $code < 300 ? true : new WP_Error( 'my_crm', 'HTTP ' . $code );
	}
};
```

### From a plugin

```php
add_filter(
	'wp_cortex_lead_integrations',
	function ( array $integrations ) {
		$integrations[] = new My_Plugin\Cortex_Integration();
		return $integrations;
	}
);
```

The filter can also remove built-in integrations.

### Without an integration class

To react to leads in your own way (for example to add them to a mailing list), hook the actions directly. Do slow work in a scheduled event:

```php
add_action( 'wp_cortex_lead_created', function ( array $payload, int $id ) {
	wp_schedule_single_event( time(), 'my_send_lead', array( $id ) );
}, 10, 2 );
```
