<?php
/**
 * Media library attachments.
 *
 * @package WPCortex
 */

namespace WPCortex\Indexing\Extractors;

use WP_Post;
use WPCortex\Indexing\Document;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * File details, alt text, caption and audio/video/image metadata of attachments.
 * Title, description (body) and caption (excerpt) come from CoreExtractor. Data that
 * is visible on the front end or embedded in the public file is public; the rest is
 * admin only.
 */
final class MediaExtractor implements Extractor {

	/**
	 * Audio/video metadata key => field name.
	 */
	private const AV_FIELDS = array(
		'length_formatted' => 'duration',
		'artist'           => 'artist',
		'album'            => 'album',
	);

	/**
	 * Image metadata (EXIF/IPTC) key => field name. Admin only.
	 */
	private const IMAGE_META_FIELDS = array(
		'credit'    => 'credit',
		'copyright' => 'copyright',
		'camera'    => 'camera',
	);

	public function is_available(): bool {
		return (bool) Settings::get( 'index_media' );
	}

	public function extract( WP_Post $post, Document $doc ): void {
		if ( 'attachment' !== $post->post_type ) {
			return;
		}

		$meta = wp_get_attachment_metadata( $post->ID );
		$meta = is_array( $meta ) ? $meta : array();
		$file = (string) get_attached_file( $post->ID );

		$doc->add_field( 'media', 'mime_type', (string) $post->post_mime_type, true );
		$doc->add_field( 'media', 'type', strtok( (string) $post->post_mime_type, '/' ) ?: '', true );

		if ( '' !== $file ) {
			$doc->add_field( 'media', 'file_name', wp_basename( $file ), true );
		}

		$size = (int) ( $meta['filesize'] ?? 0 );
		if ( ! $size && '' !== $file && is_readable( $file ) ) {
			$size = (int) filesize( $file );
		}
		if ( $size ) {
			$doc->add_field( 'media', 'file_size', $size, true );
		}

		if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			$doc->add_field( 'media', 'width', (int) $meta['width'], true );
			$doc->add_field( 'media', 'height', (int) $meta['height'], true );
		}

		foreach ( self::AV_FIELDS as $key => $name ) {
			if ( isset( $meta[ $key ] ) && is_scalar( $meta[ $key ] ) && '' !== (string) $meta[ $key ] ) {
				$doc->add_field( 'media', $name, (string) $meta[ $key ], true );
			}
		}

		$image_meta = is_array( $meta['image_meta'] ?? null ) ? $meta['image_meta'] : array();
		foreach ( self::IMAGE_META_FIELDS as $key => $name ) {
			if ( isset( $image_meta[ $key ] ) && is_scalar( $image_meta[ $key ] ) && '' !== (string) $image_meta[ $key ] ) {
				$doc->add_field( 'media', $name, (string) $image_meta[ $key ] );
			}
		}

		// Stored even when empty, so "images without alt text" is a simple equality check.
		if ( wp_attachment_is_image( $post ) ) {
			$alt = trim( wp_strip_all_tags( (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ) ) );
			$doc->add_field( 'media', 'alt_text', $alt, true );
			if ( '' !== $alt ) {
				$doc->add_section( 'Alt text', $alt, true );
			}
		}

		// The caption is the excerpt, which is not chunked; make it searchable. Headings are
		// not translated so the content hash does not depend on the request locale.
		if ( '' !== $doc->excerpt ) {
			$doc->add_section( 'Caption', $doc->excerpt, true );
		}
	}
}
