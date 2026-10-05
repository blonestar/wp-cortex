<?php
/**
 * Images visitors attach in the visitor chat.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

use WPCortex\Settings;
use WPCortex\Storage\Storage;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates, re-encodes and stores visitor chat images (for example screenshots).
 *
 * Every image is decoded and drawn again with GD, so only pixels are kept: metadata
 * (EXIF, location) and anything appended to the file are dropped, and files that only
 * look like images are rejected. PNG stays PNG (sharp text in screenshots), everything
 * else becomes JPEG. While the conversation log is on, images are stored in the protected
 * data directory, one subdirectory per conversation, and deleted with the conversation.
 */
final class VisitorImages {

	/**
	 * Largest decoded image accepted from the browser, in bytes.
	 */
	public const MAX_UPLOAD_BYTES = 4194304;

	/**
	 * Largest data URL accepted from the browser: base64 of MAX_UPLOAD_BYTES plus the prefix.
	 */
	public const MAX_DATA_URL = 5592500;

	/**
	 * Longest side of a stored image, in pixels; larger images are scaled down.
	 */
	public const MAX_SIDE = 1600;

	/**
	 * Largest image decoded (width × height), so a small file cannot exhaust memory.
	 */
	private const MAX_PIXELS = 16777216;

	/**
	 * A PNG larger than this after re-encoding is stored as JPEG instead.
	 */
	private const MAX_PNG_BYTES = 2097152;

	private const JPEG_QUALITY = 85;

	/**
	 * Stored file names: random lowercase letters and digits with the extension.
	 */
	public const NAME_PATTERN = '[a-z0-9]{24}\.(?:jpg|png)';

	/**
	 * Whether the server can decode and re-encode images (GD with PNG and JPEG).
	 */
	public static function is_supported(): bool {
		return function_exists( 'imagecreatefromstring' ) && function_exists( 'imagetypes' )
			&& ( imagetypes() & IMG_PNG ) && ( imagetypes() & IMG_JPG );
	}

	/**
	 * Whether visitors may attach images.
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'public_chat_images' ) && self::is_supported();
	}

	/**
	 * Validates and re-encodes an image sent by the browser as a data URL.
	 *
	 * @param string $data_url data:image/png|jpeg|webp|gif;base64,… URL.
	 * @return array{mime: string, ext: string, bytes: string}|WP_Error
	 */
	public static function process( string $data_url ) {
		$invalid = new WP_Error( 'wp_cortex_invalid_image', __( 'The image could not be read. Please use a PNG, JPEG, WebP or GIF image.', 'wp-cortex' ), array( 'status' => 400 ) );

		if ( strlen( $data_url ) > self::MAX_DATA_URL || ! preg_match( '#^data:image/(?:png|jpeg|webp|gif);base64,([A-Za-z0-9+/]+={0,2})$#', $data_url, $m ) ) {
			return $invalid;
		}

		$bytes = base64_decode( $m[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $bytes || '' === $bytes || strlen( $bytes ) > self::MAX_UPLOAD_BYTES ) {
			return $invalid;
		}

		// The real type and size come from the file, never from the data URL.
		$info  = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$types = array( IMAGETYPE_PNG => IMG_PNG, IMAGETYPE_JPEG => IMG_JPG, IMAGETYPE_WEBP => IMG_WEBP, IMAGETYPE_GIF => IMG_GIF );

		if ( ! is_array( $info ) || ! isset( $types[ $info[2] ] ) || ! ( imagetypes() & $types[ $info[2] ] ) ) {
			return $invalid;
		}

		$width  = (int) $info[0];
		$height = (int) $info[1];

		if ( $width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS ) {
			return new WP_Error( 'wp_cortex_invalid_image', __( 'The image is too large. Please send a smaller image.', 'wp-cortex' ), array( 'status' => 400 ) );
		}

		wp_raise_memory_limit( 'image' );

		$source = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $source ) {
			return $invalid;
		}

		$scale  = min( 1, self::MAX_SIDE / max( $width, $height ) );
		$new_w  = max( 1, (int) round( $width * $scale ) );
		$new_h  = max( 1, (int) round( $height * $scale ) );
		$is_png = IMAGETYPE_PNG === $info[2];
		$output = self::encode( $source, $new_w, $new_h, $is_png );

		if ( $is_png && strlen( $output ) > self::MAX_PNG_BYTES ) {
			$is_png = false;
			$output = self::encode( $source, $new_w, $new_h, false );
		}

		unset( $source );

		if ( '' === $output ) {
			return $invalid;
		}

		return array(
			'mime'  => $is_png ? 'image/png' : 'image/jpeg',
			'ext'   => $is_png ? 'png' : 'jpg',
			'bytes' => $output,
		);
	}

	/**
	 * Draws the image on a new canvas and encodes it.
	 *
	 * @param \GdImage $source Decoded image.
	 * @param int      $width  Target width.
	 * @param int      $height Target height.
	 * @param bool     $png    PNG with transparency, or JPEG on a white background.
	 * @return string Encoded bytes, empty on failure.
	 */
	private static function encode( $source, int $width, int $height, bool $png ): string {
		$canvas = imagecreatetruecolor( $width, $height );

		if ( false === $canvas ) {
			return '';
		}

		if ( $png ) {
			imagealphablending( $canvas, false );
			imagesavealpha( $canvas, true );
			imagefill( $canvas, 0, 0, imagecolorallocatealpha( $canvas, 0, 0, 0, 127 ) );
		} else {
			imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 255, 255, 255 ) );
		}

		imagecopyresampled( $canvas, $source, 0, 0, 0, 0, $width, $height, imagesx( $source ), imagesy( $source ) );

		ob_start();
		$ok    = $png ? imagepng( $canvas, null, 6 ) : imagejpeg( $canvas, null, self::JPEG_QUALITY );
		$bytes = (string) ob_get_clean();

		unset( $canvas );

		return $ok ? $bytes : '';
	}

	/**
	 * Data URL of a processed image, for the model.
	 *
	 * @param array{mime: string, bytes: string} $image Processed image.
	 */
	public static function data_url( array $image ): string {
		return 'data:' . $image['mime'] . ';base64,' . base64_encode( $image['bytes'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Stores a processed image with a conversation.
	 *
	 * @param int                               $chat_id Conversation ID.
	 * @param array{ext: string, bytes: string} $image   Processed image.
	 * @return string Stored file name, empty on failure.
	 */
	public static function store( int $chat_id, array $image ): string {
		if ( $chat_id < 1 ) {
			return '';
		}

		try {
			Storage::ensure_data_dir();
		} catch ( \RuntimeException $e ) {
			return '';
		}

		$root = Storage::visitor_images_dir();
		$dir  = $root . '/' . $chat_id;

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		foreach ( array( $root, $dir ) as $path ) {
			if ( ! file_exists( $path . '/index.php' ) ) {
				file_put_contents( $path . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}

		$name = strtolower( wp_generate_password( 24, false ) ) . '.' . $image['ext'];

		if ( false === file_put_contents( $dir . '/' . $name, $image['bytes'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return '';
		}

		return $name;
	}

	/**
	 * Absolute path of a stored image.
	 *
	 * @param int    $chat_id Conversation ID.
	 * @param string $name    File name.
	 * @return string Path, empty when the name is invalid or the file is missing.
	 */
	public static function path( int $chat_id, string $name ): string {
		if ( $chat_id < 1 || ! preg_match( '/^' . self::NAME_PATTERN . '$/', $name ) ) {
			return '';
		}

		$path = Storage::visitor_images_dir() . '/' . $chat_id . '/' . $name;

		return is_file( $path ) ? $path : '';
	}

	/**
	 * MIME type of a stored image, from its extension.
	 *
	 * @param string $name File name.
	 */
	public static function mime( string $name ): string {
		return str_ends_with( $name, '.png' ) ? 'image/png' : 'image/jpeg';
	}

	/**
	 * Stored images of a conversation, in transcript order.
	 *
	 * @param int   $chat_id    Conversation ID.
	 * @param array $transcript Transcript items.
	 * @return string[] Absolute paths of the files that exist.
	 */
	public static function paths( int $chat_id, array $transcript ): array {
		$paths = array();

		foreach ( $transcript as $item ) {
			$path = is_array( $item ) && ! empty( $item['image'] ) ? self::path( $chat_id, (string) $item['image'] ) : '';

			if ( '' !== $path ) {
				$paths[] = $path;
			}
		}

		return $paths;
	}

	/**
	 * Deletes the stored images of conversations.
	 *
	 * @param int[] $chat_ids Conversation IDs.
	 */
	public static function delete( array $chat_ids ): void {
		foreach ( array_filter( array_map( 'absint', $chat_ids ) ) as $id ) {
			Storage::delete_dir( Storage::visitor_images_dir() . '/' . $id );
		}
	}
}
