<?php
/**
 * Visitor chat tool: save_contact_details.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Public;

use WPCortex\Chat\Tools\ToolContext;
use WPCortex\Chat\VisitorChatStore;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Saves the contact details the visitor gives to their own stored conversation.
 * Offered while public_chat_contact is on and the conversation is stored.
 */
final class SaveContactDetails extends PublicTool {

	public function name(): string {
		return 'save_contact_details';
	}

	public function label(): string {
		return __( 'Save contact details', 'wp-cortex' );
	}

	/**
	 * Offered when contact details are on and the conversation is stored.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		return $this->visitor( $context )->chat_id() > 0 && (bool) Settings::get( 'public_chat_contact' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Saves the contact details a visitor gives about themselves, so the site team can get back to them. Call it right away whenever the visitor gives any of these details, without asking them to confirm first; only pass the details from the visitor\'s latest messages. Calling it again adds or updates details, so call it each time the visitor adds or corrects something.';
	}

	/**
	 * Arguments.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function parameters( ToolContext $context ): ?array {
		$string = array( 'type' => 'string' );

		return array(
			'type'       => 'object',
			'properties' => array(
				'first_name' => array_merge( $string, array( 'description' => 'First name.' ) ),
				'last_name'  => array_merge( $string, array( 'description' => 'Last name.' ) ),
				'email'      => array_merge( $string, array( 'description' => 'Email address in its standard form, for example ana@example.com.' ) ),
				'phone'      => array_merge( $string, array( 'description' => 'Phone number.' ) ),
				'address'    => array_merge( $string, array( 'description' => 'Postal address, only if the visitor gave it.' ) ),
				'company'    => array_merge( $string, array( 'description' => 'Company or organization, only if the visitor gave it.' ) ),
				'website'    => array_merge( $string, array( 'description' => 'One or more website URLs, one per line, as the visitor gave them (for example www.example.com); only if the visitor gave them.' ) ),
				'request'    => array_merge( $string, array( 'description' => 'Short summary of what the visitor wants the team to get back to them about, in the visitor\'s language. Only what the visitor said in this conversation; leave it out when the visitor only asked to be contacted, never write a generic one.' ) ),
			),
		);
	}

	/**
	 * System instruction lines.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		return array( 'Contact details: whenever the visitor gives their own name, email address, phone number, website URL(s), postal address or company, at any point in the conversation, call save_contact_details right away with those details; do not ask the visitor to confirm them first. Write an email address in its standard form (for example "ana at example dot com" as ana@example.com). After saving a name alone, do not mention it: greet the visitor by name and carry on; a name alone is not a request to be contacted, so do not ask for other details because of it. After saving an email address, phone number or other contact details, repeat them in one short sentence so the visitor can correct a mistake. If the visitor wants to be contacted, asks for an offer or a quote, wants to send an inquiry, or the content cannot answer their question, you may offer to take their contact details so the team can get back to them; ask for a first and/or last name and an email address or phone number, and only if relevant a website, postal address or company, a few items at a time, and do not ask again for an item the visitor skipped. Also save a short summary of what the visitor needs as the request: as soon as the visitor wants to be contacted and the conversation shows what about (for example a service they are interested in, a question the content could not answer, or an offer they asked about), call save_contact_details with the request right away, even before any other details, and do not ask them again what it is about; if it is still unknown once an email address or phone number is saved, ask once, briefly, what they would like the team to get back to them about, and save their answer. The request must say what the visitor needs (a topic, service, question or offer); wanting to be contacted is not a request in itself, so never write one like "wants to be contacted" and never invent one. If the visitor corrects a detail, save the corrected value. Never ask for sensitive data (passwords, payment cards, ID numbers, health data). Do not push the visitor to leave details.' );
	}

	/**
	 * Saves the details to the visitor's own conversation.
	 *
	 * @param array       $args    Arguments: contact fields.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$visitor = $this->visitor( $context );

		if ( isset( $args['email'] ) && is_string( $args['email'] ) ) {
			$args['email'] = self::normalize_email( $args['email'] );
		}

		$contact = VisitorChatStore::sanitize_contact( $args );

		if ( '' !== trim( (string) ( $args['email'] ?? '' ) ) && ! isset( $contact['email'] ) ) {
			return array( 'error' => 'The email address is not valid. Ask the visitor to check it.' );
		}

		if ( '' !== trim( (string) ( $args['website'] ?? '' ) ) && ! isset( $contact['website'] ) ) {
			return array( 'error' => 'The website URL is not valid. Ask the visitor to check it.' );
		}

		if ( ! array_intersect( array( 'first_name', 'last_name', 'email', 'phone', 'website' ), array_keys( $contact ) ) ) {
			return array( 'error' => 'A name, email address, phone number, or website URL is required. Ask the visitor for one.' );
		}

		$saved = ( new VisitorChatStore() )->save_contact( $visitor->chat_id(), $contact );

		if ( null === $saved ) {
			return array( 'error' => 'The details could not be saved.' );
		}

		$visitor->set_item(
			'contact',
			array(
				'role' => 'notice',
				'text' => __( 'Contact details saved.', 'wp-cortex' ),
			)
		);

		$result = array(
			'saved'   => true,
			'contact' => $saved,
		);

		if ( ! array_intersect( array( 'email', 'phone', 'website', 'address' ), array_keys( $saved ) ) ) {
			if ( isset( $saved['request'] ) ) {
				$result['next_step'] = 'There is no way to reach the visitor yet. Unless the visitor already declined, ask for what is missing: their name and an email address or phone number.';
			}
		} elseif ( ! isset( $saved['request'] ) ) {
			$result['next_step'] = 'The request is still unknown. Unless the visitor already declined to say, ask briefly what they would like the team to get back to them about, then save it with save_contact_details.';
		}

		return $result;
	}

	/**
	 * Turns a spelled-out email address ("ana at example dot com") into its standard form.
	 *
	 * @param string $email Email address as given.
	 * @return string The address unchanged when it is already valid or cannot be repaired.
	 */
	private static function normalize_email( string $email ): string {
		$email = trim( $email );

		if ( '' === $email || is_email( $email ) ) {
			return $email;
		}

		$fixed = preg_replace(
			array( '/\s*[\[(]?\s*\bat\b\s*[\])]?\s*/i', '/\s*[\[(]?\s*\bdot\b\s*[\])]?\s*/i', '/\s+/' ),
			array( '@', '.', '' ),
			$email
		);

		return is_string( $fixed ) && is_email( $fixed ) ? $fixed : $email;
	}
}
