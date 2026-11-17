<?php
/**
 * Validierter Bild-Upload (JPEG/PNG/WebP, max. 8 MB) mit Entfernen der Metadaten.
 */

namespace NZ\Pinnwand;

defined( 'ABSPATH' ) || exit;

class Upload {

	public const MAX_BYTES  = 8388608;
	public const MAX_PIXELS = 50000000;

	/** @return array<string,string> */
	public static function mimes(): array {
		return array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
		);
	}

	private static function error( string $message ): \WP_Error {
		return new \WP_Error( 'nzp_upload', $message, array( 'status' => 400 ) );
	}

	/**
	 * Nimmt eine Datei im $_FILES-Format entgegen und legt ein Attachment an.
	 *
	 * @param array $file    Eintrag im $_FILES-Format.
	 * @param int   $user_id Autor des Attachments.
	 * @return int|\WP_Error Attachment-ID oder Fehler (Code nzp_upload, Status 400).
	 */
	public static function handle( array $file, int $user_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$mimes = self::mimes();
		$tmp   = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$name  = isset( $file['name'] ) ? (string) $file['name'] : '';

		// 1. Validierung, bevor irgendetwas verschoben wird.
		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return self::error( __( 'Der Upload ist fehlgeschlagen. Bitte versuche es erneut.', 'nz-pinnwand' ) );
		}
		if ( '' === $tmp || ! is_file( $tmp ) ) {
			return self::error( __( 'Es wurde keine Datei hochgeladen.', 'nz-pinnwand' ) );
		}
		$size = max( (int) ( $file['size'] ?? 0 ), (int) filesize( $tmp ) );
		if ( $size > self::MAX_BYTES ) {
			return self::error( __( 'Das Bild ist zu groß (maximal 8 MB).', 'nz-pinnwand' ) );
		}
		$type_msg = __( 'Nur JPEG-, PNG- und WebP-Bilder sind erlaubt.', 'nz-pinnwand' );

		$check = wp_check_filetype_and_ext( $tmp, $name, $mimes );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], $mimes, true ) ) {
			return self::error( $type_msg );
		}
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $info || empty( $info['mime'] ) || $info['mime'] !== $check['type'] ) {
			return self::error( $type_msg );
		}
		if ( (int) $info[0] * (int) $info[1] > self::MAX_PIXELS ) {
			return self::error( __( 'Die Bildabmessungen sind zu groß.', 'nz-pinnwand' ) );
		}

		// 2. Verschieben in die Mediathek.
		$moved = wp_handle_sideload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);
		if ( isset( $moved['error'] ) || empty( $moved['file'] ) ) {
			return self::error( $type_msg );
		}
		$path = $moved['file'];
		$mime = (string) $moved['type'];

		// 3. Metadaten entfernen (Re-Encode), bevor Unterformate erzeugt werden.
		if ( ! self::reencode( $path, $mime ) ) {
			wp_delete_file( $path );
			return self::error( __( 'Das Bild konnte nicht verarbeitet werden.', 'nz-pinnwand' ) );
		}

		// 4. Attachment anlegen.
		$id = wp_insert_attachment(
			array(
				'post_author'    => $user_id,
				'post_mime_type' => $mime,
				'post_title'     => sanitize_text_field( pathinfo( $name, PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
			),
			$path,
			0,
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			wp_delete_file( $path );
			return self::error( __( 'Das Bild konnte nicht gespeichert werden.', 'nz-pinnwand' ) );
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );

		return (int) $id;
	}

	/** Kodiert die Datei per GD neu (verwirft EXIF/GPS/ICC/XMP). Gibt false bei Fehler zurueck. */
	private static function reencode( string $path, string $mime ): bool {
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
				$ok = function_exists( 'imagejpeg' ) && imagejpeg( $img, $path, 90 );
				break;
			case 'image/png':
				$ok = function_exists( 'imagepng' ) && imagepng( $img, $path );
				break;
			default:
				$ok = function_exists( 'imagewebp' ) && imagewebp( $img, $path, 90 );
		}
		imagedestroy( $img );
		return (bool) $ok;
	}

	/** Wendet die EXIF-Orientierung (2, 3, 4, 6, 8) an. */
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
				$angle = 270; // 90 Grad im Uhrzeigersinn.
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
