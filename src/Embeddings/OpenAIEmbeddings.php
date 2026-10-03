<?php
/**
 * OpenAI embeddings client.
 *
 * @package WPCortex
 */

namespace WPCortex\Embeddings;

use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Calls the OpenAI embeddings endpoint. The WordPress AI Client does not implement
 * embedding generation yet, so this talks to the API directly but reuses the key
 * configured under Settings > Connectors.
 */
final class OpenAIEmbeddings implements EmbeddingProvider {

	private const ENDPOINT = 'https://api.openai.com/v1/embeddings';

	/**
	 * Inputs per request (the API accepts up to 2048).
	 */
	private const MAX_INPUTS = 96;

	private const MAX_ATTEMPTS = 3;

	/**
	 * Total tokens consumed by this instance.
	 */
	public int $tokens_used = 0;

	public function is_configured(): bool {
		return '' !== self::api_key();
	}

	/**
	 * API key with the same precedence as WordPress Connectors: env, constant, option.
	 */
	public static function api_key(): string {
		$key = getenv( 'OPENAI_API_KEY' );

		if ( ! is_string( $key ) || '' === $key ) {
			$key = defined( 'OPENAI_API_KEY' ) ? (string) constant( 'OPENAI_API_KEY' ) : '';
		}

		if ( '' === $key ) {
			$key = (string) get_option( 'connectors_ai_openai_api_key', '' );
		}

		/**
		 * Filters the OpenAI API key used for embeddings.
		 *
		 * @param string $key API key.
		 */
		return (string) apply_filters( 'wp_cortex_openai_api_key', $key );
	}

	public function embed( array $texts ): array {
		$vectors = array();

		foreach ( array_chunk( array_values( $texts ), self::MAX_INPUTS ) as $batch ) {
			array_push( $vectors, ...$this->request( $batch ) );
		}

		return $vectors;
	}

	/**
	 * One API request, retried on rate limits and server errors.
	 *
	 * @param string[] $batch Texts.
	 * @return array<int, float[]>
	 * @throws \RuntimeException On failure.
	 */
	private function request( array $batch ): array {
		$body = array(
			'model'           => Settings::get( 'embedding_model' ),
			'input'           => $batch,
			'dimensions'      => (int) Settings::get( 'embedding_dimensions' ),
			'encoding_format' => 'float',
		);

		for ( $attempt = 1; ; $attempt++ ) {
			$response = wp_remote_post(
				self::ENDPOINT,
				array(
					'timeout' => 60,
					'headers' => array(
						'Authorization' => 'Bearer ' . self::api_key(),
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

			if ( 200 === $code ) {
				break;
			}

			$retryable = 0 === $code || 429 === $code || $code >= 500;
			if ( ! $retryable || $attempt >= self::MAX_ATTEMPTS ) {
				throw new \RuntimeException( $this->error_message( $response, $code ) );
			}

			sleep( $attempt * 2 );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['data'] ) || count( $data['data'] ) !== count( $batch ) ) {
			throw new \RuntimeException( 'OpenAI embeddings: unexpected response.' );
		}

		$this->tokens_used += (int) ( $data['usage']['total_tokens'] ?? 0 );

		usort( $data['data'], static fn( $a, $b ) => $a['index'] <=> $b['index'] );

		return array_map( static fn( $item ) => array_map( 'floatval', $item['embedding'] ), $data['data'] );
	}

	/**
	 * Human readable error.
	 *
	 * @param array|\WP_Error $response Response.
	 * @param int             $code     HTTP status.
	 */
	private function error_message( $response, int $code ): string {
		if ( is_wp_error( $response ) ) {
			return 'OpenAI embeddings: ' . $response->get_error_message();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return sprintf( 'OpenAI embeddings (HTTP %d): %s', $code, $data['error']['message'] ?? 'request failed' );
	}
}
