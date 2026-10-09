<?php
/**
 * Base class for lead integrations.
 *
 * @package WPCortex
 */

namespace WPCortex\Integrations;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A destination for leads (a CRM, a webhook, an email marketing tool). It only describes
 * its settings and sends one lead; IntegrationRegistry stores the settings, Dispatcher
 * decides when to send, retries failed deliveries and logs them.
 *
 * Built-in integrations live in src/Integrations/Builtin/. Others come from the theme's
 * wp-cortex/integrations/ files or the wp_cortex_lead_integrations filter.
 */
abstract class Integration {

	/**
	 * Unique ID: lowercase letters, digits, "_" and "-" (it keys the settings and the log).
	 */
	abstract public function id(): string;

	/**
	 * Name shown under Settings > Leads > Integrations.
	 */
	abstract public function label(): string;

	/**
	 * Sends one lead.
	 *
	 * @param string $event    "created", "updated" or "test".
	 * @param array  $payload  Lead payload (Leads\LeadPayload::build()); a made-up lead for "test".
	 * @param array  $changes  What changed for "updated": "contact", "status" and/or "rating".
	 * @param array  $settings This integration's settings (fields() keys plus enabled and events).
	 * @return true|WP_Error WP_Error when the destination refused the lead or could not be reached.
	 */
	abstract public function send( string $event, array $payload, array $changes, array $settings );

	/**
	 * One sentence on what the integration does.
	 */
	public function description(): string {
		return '';
	}

	/**
	 * Settings fields, keyed by setting name. Each field: type ("text", "url", "secret",
	 * "textarea", "select" or "checkbox"), label, optional description, placeholder,
	 * default, required (bool) and options (for "select", value => label). A secret is never shown again
	 * once saved; leaving it empty keeps the stored value.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array();
	}

	/**
	 * Whether the settings are complete enough to send: every required field is filled in.
	 *
	 * @param array $settings This integration's settings.
	 */
	public function is_configured( array $settings ): bool {
		foreach ( $this->fields() as $key => $field ) {
			if ( ! empty( $field['required'] ) && '' === trim( (string) ( $settings[ $key ] ?? '' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Checks the settings before they are saved or tested (for example the URL format).
	 *
	 * @param array $settings Sanitized settings.
	 * @return true|WP_Error
	 */
	public function validate( array $settings ) {
		return true;
	}
}
