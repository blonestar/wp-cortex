<?php
/**
 * Embedding provider contract.
 *
 * @package WPCortex
 */

namespace WPCortex\Embeddings;

defined( 'ABSPATH' ) || exit;

/**
 * Turns texts into vectors.
 */
interface EmbeddingProvider {

	/**
	 * Whether credentials are available.
	 */
	public function is_configured(): bool;

	/**
	 * Embeds texts, preserving order.
	 *
	 * @param string[] $texts Texts.
	 * @return array<int, float[]>
	 * @throws \RuntimeException On API failure.
	 */
	public function embed( array $texts ): array;
}
