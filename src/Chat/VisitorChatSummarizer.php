<?php
/**
 * AI summary of a stored visitor chat.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Summarizes a conversation for the site team with the configured chat model: what the
 * visitor wanted, asked and looked at, their contact details and the open points.
 */
final class VisitorChatSummarizer {

	/**
	 * Maximum characters of conversation sent to the model (the most recent part is kept).
	 */
	private const MAX_INPUT = 60000;

	/**
	 * Generates the summary.
	 *
	 * @param array $chat Conversation from VisitorChatStore::get().
	 * @return string|WP_Error Summary in Markdown.
	 */
	public function summarize( array $chat ) {
		$transcript = VisitorChatReport::transcript( $chat );

		if ( '' === $transcript ) {
			return new WP_Error( 'wp_cortex_empty_chat', __( 'The conversation has no messages to summarize.', 'wp-cortex' ), array( 'status' => 400 ) );
		}

		if ( mb_strlen( $transcript ) > self::MAX_INPUT ) {
			$transcript = '…' . mb_substr( $transcript, -self::MAX_INPUT );
		}

		$contact = VisitorChatReport::contact( $chat );
		$input   = implode(
			"\n\n",
			array_filter(
				array(
					VisitorChatReport::details( $chat ),
					'' !== $contact ? "Contact details left by the visitor:\n" . $contact : 'The visitor left no contact details.',
					"Conversation:\n" . $transcript,
				)
			)
		);

		PromptFactory::extend_time_limit();

		$builder = PromptFactory::builder( array( new UserMessage( array( new MessagePart( $input ) ) ) ), $this->system_instruction(), array() );

		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		$result = $builder->generate_text_result();

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'wp_cortex_ai_error', $result->get_error_message(), array( 'status' => 502 ) );
		}

		$text = PromptFactory::parse( $result->toMessage() )['text'];

		if ( '' === $text ) {
			return new WP_Error( 'wp_cortex_ai_error', __( 'The AI model returned an empty summary.', 'wp-cortex' ), array( 'status' => 502 ) );
		}

		return $text;
	}

	/**
	 * System instruction for the summary.
	 */
	private function system_instruction(): string {
		return implode(
			"\n",
			array(
				sprintf(
					'You summarize a conversation between a visitor of the website "%s" and its chat assistant, for the site team that will follow up.',
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
				),
				'Write in the language the visitor used. Use Markdown with these short sections, as bold headings in that language, and leave out empty ones:',
				'1. Summary: two or three sentences on who the visitor is and what they want.',
				'2. Questions and requests: bullet list of everything the visitor asked or asked for.',
				'3. Pages: the pages the visitor was on, the pages they were taken to and the pages the assistant suggested.',
				'4. Contact details: only those the visitor gave.',
				'5. Open points: unanswered questions, anything promised to the visitor and the suggested next step for the team.',
				'Use only the information in the conversation, never invent anything. Be concise (at most about 250 words). Output only the summary.',
			)
		);
	}
}
