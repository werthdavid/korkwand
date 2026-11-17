<?php
/**
 * Validated image upload (JPEG/PNG/WebP, max. 8 MB) with metadata stripping.
 */

namespace Korkwand;

defined( 'ABSPATH' ) || exit;

class Upload {

	public const MAX_BYTES  = 8388608;
	public const MAX_PIXELS = 25000000;

	/** @return array<string,string> */
	public static function mimes(): array {
		return array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
		);
	}

	private static function error( string $message ): \WP_Error {
		return new \WP_Error( 'korkwand_upload', $message, array( 'status' => 400 ) );
	}

	/**
	 * Attachment pages of plugin uploads return 404 (otherwise guests could see title/file metadata there).
	 * Hook: template_redirect.
	 */
	public static function block_attachment_page(): void {
		if ( ! is_attachment() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( $id > 0 && get_post_meta( $id, '_korkwand_upload', true ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/** Effective limit: plugin cap, bounded by the host settings (upload_max_filesize/post_max_size). */
	public static function max_bytes(): int {
		return max( 1, min( self::MAX_BYTES, (int) wp_max_upload_size() ) );
	}

	/** Limit in MB as localized text, e.g. "8" or "1,5". */
	public static function max_label(): string {
		$mb = round( self::max_bytes() / 1048576, 1 );
		return floor( $mb ) === $mb ? (string) (int) $mb : number_format_i18n( $mb, 1 );
	}

	/** Error message "Image too large" with the effective limit. */
	public static function too_large_error(): \WP_Error {
		/* translators: %s: maximum size in MB */
		return self::error( sprintf( __( 'The image is too large (maximum %s MB).', 'korkwand' ), self::max_label() ) );
	}

	/** Translates a PHP upload error code (UPLOAD_ERR_*) into a WP_Error. */
	public static function upload_error( int $code ): \WP_Error {
		if ( UPLOAD_ERR_INI_SIZE === $code || UPLOAD_ERR_FORM_SIZE === $code ) {
			return self::too_large_error();
		}
		return self::error( __( 'The upload failed. Please try again.', 'korkwand' ) );
	}

	/**
	 * Accepts a file in $_FILES format and creates an attachment.
	 *
	 * @param array $file    Eintrag im $_FILES-Format.
	 * @param int   $user_id Autor des Attachments.
	 * @return int|\WP_Error Attachment ID or error (code korkwand_upload, status 400).
	 */
	public static function handle( array $file, int $user_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$mimes = self::mimes();
		$tmp   = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$name  = isset( $file['name'] ) ? (string) $file['name'] : '';

		// 1. Validate before anything is moved.
		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return self::upload_error( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) );
		}
		if ( '' === $tmp || ! is_file( $tmp ) ) {
			return self::error( __( 'No file was uploaded.', 'korkwand' ) );
		}
		$size = max( (int) ( $file['size'] ?? 0 ), (int) filesize( $tmp ) );
		if ( $size > self::max_bytes() ) {
			return self::too_large_error();
		}
		$type_msg = __( 'Only JPEG, PNG and WebP images are allowed.', 'korkwand' );

		$check = wp_check_filetype_and_ext( $tmp, $name, $mimes );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], $mimes, true ) ) {
			return self::error( $type_msg );
		}
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $info || empty( $info['mime'] ) || $info['mime'] !== $check['type'] ) {
			return self::error( $type_msg );
		}
		if ( (int) $info[0] * (int) $info[1] > self::MAX_PIXELS ) {
			return self::error( __( 'The image dimensions are too large.', 'korkwand' ) );
		}

		// 2. Strip metadata in the temp directory (re-encode) before the file becomes publicly reachable.
		wp_raise_memory_limit( 'image' );
		$clean = wp_tempnam( $name );
		if ( ! $clean || ! self::reencode( $tmp, $check['type'], $clean ) ) {
			if ( $clean ) {
				wp_delete_file( $clean );
			}
			return self::error( __( 'The image could not be processed.', 'korkwand' ) );
		}
		$file['tmp_name'] = $clean;
		$file['size']     = (int) filesize( $clean );
		// Never publish the original filename: neutral random name with a verified extension.
		$exts         = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
		);
		$ext          = $exts[ $check['type'] ];
		$file['name'] = 'korkwand-' . wp_generate_password( 12, false ) . '.' . $ext;

		// 3. Move the cleaned file into the media library.
		$moved = wp_handle_sideload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);
		if ( isset( $moved['error'] ) || empty( $moved['file'] ) ) {
			wp_delete_file( $clean );
			return self::error( $type_msg );
		}
		$path = $moved['file'];
		$mime = (string) $moved['type'];

		// 4. Create the attachment.
		$id = wp_insert_attachment(
			array(
				'post_author'    => $user_id,
				'post_mime_type' => $mime,
				'post_title'     => __( 'Korkwand image', 'korkwand' ),
				'post_status'    => 'inherit',
			),
			$path,
			0,
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			wp_delete_file( $path );
			return self::error( __( 'The image could not be saved.', 'korkwand' ) );
		}
		update_post_meta( $id, '_korkwand_upload', 1 );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );

		return (int) $id;
	}

	/** Re-encodes $path via GD and writes the result to $dest (discards EXIF/GPS/ICC/XMP). Returns false on error. */
	private static function reencode( string $path, string $mime, string $dest ): bool {
		$loaders = array(
			'image/jpeg' => 'imagecreatefromjpeg',
			'image/png'  => 'imagecreatefrompng',
			'image/webp' => 'imagecreatefromwebp',
		);
		if ( ! isset( $loaders[ $mime ] ) || ! function_exists( $loaders[ $mime ] ) ) {
			return false;
		}

		$orientation = 1;
		if ( 'image/jpeg' === $mime && function_exists( 'exif_read_data' ) ) {
			$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $exif ) && isset( $exif['Orientation'] ) ) {
				$orientation = (int) $exif['Orientation'];
			}
		}

		$img = @$loaders[ $mime ]( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $img ) {
			return false;
		}

		if ( 'image/jpeg' === $mime ) {
			$img = self::orient( $img, $orientation );
		} else {
			imagealphablending( $img, false );
			imagesavealpha( $img, true );
		}

		switch ( $mime ) {
			case 'image/jpeg':
				$ok = function_exists( 'imagejpeg' ) && imagejpeg( $img, $dest, 90 );
				break;
			case 'image/png':
				$ok = function_exists( 'imagepng' ) && imagepng( $img, $dest );
				break;
			default:
				$ok = function_exists( 'imagewebp' ) && imagewebp( $img, $dest, 90 );
		}
		imagedestroy( $img );
		return (bool) $ok;
	}

	/** Applies the EXIF orientation (2, 3, 4, 6, 8). */
	private static function orient( \GdImage $img, int $orientation ): \GdImage {
		switch ( $orientation ) {
			case 2:
				imageflip( $img, IMG_FLIP_HORIZONTAL );
				return $img;
			case 4:
				imageflip( $img, IMG_FLIP_VERTICAL );
				return $img;
			case 3:
				$angle = 180;
				break;
			case 6:
				$angle = 270; // 90 degrees clockwise.
				break;
			case 8:
				$angle = 90;
				break;
			default:
				return $img;
		}
		$rotated = imagerotate( $img, $angle, 0 );
		if ( false === $rotated ) {
			return $img;
		}
		imagedestroy( $img );
		return $rotated;
	}
}
