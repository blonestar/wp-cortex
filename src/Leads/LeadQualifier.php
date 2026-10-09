<?php
/**
 * AI rating of a lead.
 *
 * @package WPCortex
 */

namespace WPCortex\Leads;

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WPCortex\Chat\PromptFactory;
use WPCortex\Chat\VisitorChatReport;
use WPCortex\Chat\VisitorChatStore;
use WPCortex\Chat\VisitorChatSummarizer;
use WPCortex\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Rates a lead with the summary model: hot, warm or cold, a score from 0 to 100, the
 * intent and what the visitor told about their needs (interest, company, role, budget,
 * timeline) with a suggested next step. Only what the conversation says is used.
 */
final class LeadQualifier {

	/**
	 * Intents a lead can have.
	 */
	public const INTENTS = array( 'new_business', 'quote', 'support', 'partnership', 'job', 'information', 'spam', 'other' );

	/**
	 * Text details of a rating and their maximum lengths.
	 */
	public const DETAILS = array(
		'interest'  => 200,
		'company'   => 200,
		'role'      => 100,
		'budget'    => 100,
		'timeline'  => 100,
		'next_step' => 300,
		'reason'    => 400,
	);

	/**
	 * Maximum characters of conversation sent to the model (the most recent part is kept).
	 */
	private const MAX_INPUT = 40000;

	/**
	 * Intent names for the admin screens.
	 *
	 * @return array<string, string>
	 */
	public static function intent_labels(): array {
		return array(
			'new_business' => __( 'New business', 'wp-cortex' ),
			'quote'        => __( 'Quote / pricing', 'wp-cortex' ),
			'support'      => __( 'Support', 'wp-cortex' ),
			'partnership'  => __( 'Partnership', 'wp-cortex' ),
			'job'          => __( 'Job / career', 'wp-cortex' ),
			'information'  => __( 'Information', 'wp-cortex' ),
			'spam'         => __( 'Spam', 'wp-cortex' ),
			'other'        => __( 'Other', 'wp-cortex' ),
		);
	}

	/**
	 * Rates a lead.
	 *
	 * @param array $chat Conversation from VisitorChatStore::get().
	 * @return array<string, mixed>|WP_Error Clean rating for VisitorChatStore::save_qualification().
	 */
	public function qualify( array $chat ) {
		$transcript = VisitorChatReport::transcript( $chat );

		if ( '' === $transcript ) {
			return new WP_Error( 'wp_cortex_empty_chat', __( 'The conversation has no messages to rate.', 'wp-cortex' ), array( 'status' => 400 ) );
		}

		if ( mb_strlen( $transcript ) > self::MAX_INPUT ) {
			$transcript = '…' . mb_substr( $transcript, -self::MAX_INPUT );
		}

		$contact = VisitorChatReport::contact( $chat );
		$input   = implode(
			"\n\n",
			array(
				VisitorChatReport::details( $chat ),
				'' !== $contact ? "Contact details left by the visitor:\n" . $contact : 'The visitor left no contact details.',
				"Conversation:\n" . $transcript,
			)
		);

		PromptFactory::extend_time_limit();

		$builder = PromptFactory::builder( array( new UserMessage( array( new MessagePart( $input ) ) ) ), $this->system_instruction(), array(), 'summary' );

		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		$result = $builder->generate_text_result();

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'wp_cortex_ai_error', $result->get_error_message(), array( 'status' => 502 ) );
		}

		$rating = self::parse( PromptFactory::parse( $result->toMessage() )['text'] );

		if ( null === $rating ) {
			return new WP_Error( 'wp_cortex_ai_error', __( 'The AI model did not return a valid rating. Try again.', 'wp-cortex' ), array( 'status' => 502 ) );
		}

		return $rating;
	}

	/**
	 * Clean rating from the model's JSON answer, null when it is not usable.
	 *
	 * @param string $text Model answer.
	 * @return array<string, mixed>|null
	 */
	public static function parse( string $text ): ?array {
		// Models sometimes wrap JSON in a code fence or add a sentence around it.
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		$data  = false !== $start && false !== $end ? json_decode( substr( $text, $start, $end - $start + 1 ), true ) : null;

		if ( ! is_array( $data ) || ! in_array( $data['rating'] ?? '', VisitorChatStore::LEAD_RATINGS, true ) ) {
			return null;
		}

		$rating = array(
			'rating' => $data['rating'],
			'score'  => max( 0, min( 100, (int) ( $data['score'] ?? 0 ) ) ),
			'intent' => in_array( $data['intent'] ?? '', self::INTENTS, true ) ? $data['intent'] : 'other',
		);

		foreach ( self::DETAILS as $key => $max ) {
			$value = is_scalar( $data[ $key ] ?? null ) ? trim( mb_substr( sanitize_text_field( (string) $data[ $key ] ), 0, $max ) ) : '';

			if ( '' !== $value ) {
				$rating[ $key ] = $value;
			}
		}

		return $rating;
	}

	/**
	 * System instruction of the rating.
	 */
	private function system_instruction(): string {
		$language = 'visitor' === Settings::get( 'summary_language' )
			? 'Write the text values in the language the visitor used.'
			: sprintf( 'Write the text values in %s (the language of the site team).', VisitorChatSummarizer::site_language() );

		$lines = array(
			sprintf(
				'You qualify a sales lead for the marketing and sales team of the website "%s" from a conversation between a visitor and the site\'s chat assistant, and from how the visitor found the site.',
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			'Answer with one JSON object only, no other text, with these keys:',
			'"rating": "hot" (clear need, wants an offer, a call or to start soon), "warm" (real interest, no urgency or still comparing) or "cold" (only browsing, unclear need, not a fit, job seekers, support requests or spam);',
			'"score": an integer from 0 to 100 for how likely the lead is to become a customer (hot about 70-100, warm 40-69, cold 0-39);',
			'"intent": one of "' . implode( '", "', self::INTENTS ) . '";',
			'"interest": the service, product or topic the visitor is interested in;',
			'"company", "role", "budget", "timeline": only if the visitor said it;',
			'"next_step": the suggested next step for the team, one short sentence;',
			'"reason": one or two short sentences on why you chose this rating.',
			'Use an empty string for anything the conversation does not say. Never invent information. ' . $language,
		);

		$custom = trim( (string) Settings::get( 'summary_instructions' ) );

		if ( '' !== $custom ) {
			$lines[] = "\nThe site team's instructions for conversation summaries, for context on what matters to them (keep the JSON format above):\n" . $custom;
		}

		/**
		 * Filters the system instruction of the AI lead rating.
		 *
		 * @param string $instruction System instruction.
		 */
		return (string) apply_filters( 'wp_cortex_lead_rating_system_instruction', implode( "\n", $lines ) );
	}
}
