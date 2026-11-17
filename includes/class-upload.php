<?php
/**
 * Validierter Bild-Upload (JPEG/PNG/WebP, max. 8 MB) mit Entfernen der Metadaten.
 */

namespace NZ\Pinnwand;

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
		return new \WP_Error( 'nzp_upload', $message, array( 'status' => 400 ) );
	}

	/** Effektives Limit: Plugin-Grenze, begrenzt durch die Host-Einstellungen (upload_max_filesize/post_max_size). */
	public static function max_bytes(): int {
		return max( 1, min( self::MAX_BYTES, (int) wp_max_upload_size() ) );
	}

	/** Limit in MB als Text, z. B. "8" oder "1,5". */
	private static function max_label(): string {
		$mb = round( self::max_bytes() / 1048576, 1 );
		return floor( $mb ) === $mb ? (string) (int) $mb : number_format_i18n( $mb, 1 );
	}

	/** Fehlermeldung "Bild zu gross" mit dem effektiven Limit. */
	public static function too_large_error(): \WP_Error {
		/* translators: %s: maximale Groesse in MB */
		return self::error( sprintf( __( 'Das Bild ist zu groß (maximal %s MB).', 'nz-pinnwand' ), self::max_label() ) );
	}

	/** Uebersetzt einen PHP-Upload-Fehlercode (UPLOAD_ERR_*) in einen WP_Error. */
	public static function upload_error( int $code ): \WP_Error {
		if ( UPLOAD_ERR_INI_SIZE === $code || UPLOAD_ERR_FORM_SIZE === $code ) {
			return self::too_large_error();
		}
		return self::error( __( 'Der Upload ist fehlgeschlagen. Bitte versuche es erneut.', 'nz-pinnwand' ) );
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
			return self::upload_error( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) );
		}
		if ( '' === $tmp || ! is_file( $tmp ) ) {
			return self::error( __( 'Es wurde keine Datei hochgeladen.', 'nz-pinnwand' ) );
		}
		$size = max( (int) ( $file['size'] ?? 0 ), (int) filesize( $tmp ) );
		if ( $size > self::max_bytes() ) {
			return self::too_large_error();
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

		// 2. Metadaten im Temp-Verzeichnis entfernen (Re-Encode), bevor die Datei oeffentlich erreichbar wird.
		wp_raise_memory_limit( 'image' );
		$clean = wp_tempnam( $name );
		if ( ! $clean || ! self::reencode( $tmp, $check['type'], $clean ) ) {
			if ( $clean ) {
				wp_delete_file( $clean );
			}
			return self::error( __( 'Das Bild konnte nicht verarbeitet werden.', 'nz-pinnwand' ) );
		}
		$file['tmp_name'] = $clean;
		$file['size']     = (int) filesize( $clean );

		// 3. Bereinigte Datei in die Mediathek verschieben.
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
		update_post_meta( $id, '_nzp_upload', 1 );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );

		return (int) $id;
	}

	/** Kodiert $path per GD neu und schreibt das Ergebnis nach $dest (verwirft EXIF/GPS/ICC/XMP). Gibt false bei Fehler zurueck. */
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
