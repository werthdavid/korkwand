<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

/**
 * Integration with the WordPress privacy tools (export/erasure of personal data).
 */
final class Privacy {

	private const PAGE_SIZE = 50;

	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'add_eraser' ) );
		add_action( 'admin_init', array( self::class, 'add_policy_content' ) );
	}

	/**
	 * Suggested text for the site's privacy policy (Settings > Privacy). Keep in sync with the Privacy section of readme.txt.
	 */
	public static function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content  = '<h2>' . esc_html__( 'Korkwand pinboard', 'korkwand' ) . '</h2>';
		$content .= '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text. Review it and adapt it to how you use the plugin.', 'korkwand' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Only users with the Board editor role, editors and administrators can pin notes and photos to a pinboard on this site. For every note we store the user ID of the author, the note text, its position and tilt on the board, the sticky flag and its creation date. Uploaded images are stored in the media library as attachments with the uploader as author; before storage they are re-encoded to remove EXIF and GPS metadata, and they are given random file names.', 'korkwand' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Visitors see the notes but the names of their authors are never shown to guests. Author archives of users who only wrote notes are disabled, so authors cannot be looked up through author URLs. The plugin does not store IP addresses, does not set its own cookies and does not use external services. The limit on new notes per hour is based on the user ID only.', 'korkwand' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Notes expire after the lifetime configured for the board unless they are marked sticky. Expired notes are hidden immediately, moved to the trash by a daily job and permanently deleted together with their images when WordPress empties the trash (after 30 days by default). Deleting a board permanently deletes all of its notes and images. On request, notes and images of a user are included in the personal data export and are deleted by the personal data erasure of WordPress. If the email notification of a board is enabled, an email with the note title and the display name of the author is sent to the site administrator.', 'korkwand' ) . '</p>';
		wp_add_privacy_policy_content( 'Korkwand', wp_kses_post( $content ) );
	}

	public static function add_exporter( $exporters ) {
		$exporters['korkwand'] = array(
			'exporter_friendly_name' => __( 'Korkwand', 'korkwand' ),
			'callback'               => array( self::class, 'exporter' ),
		);
		return $exporters;
	}

	public static function add_eraser( $erasers ) {
		$erasers['korkwand'] = array(
			'eraser_friendly_name' => __( 'Korkwand', 'korkwand' ),
			'callback'             => array( self::class, 'eraser' ),
		);
		return $erasers;
	}

	/**
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public static function exporter( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		$page  = max( 1, $page );
		// get_posts() defaults to suppress_filters=true.
		$posts = get_posts(
			array(
				'post_type'        => 'korkwand_pin',
				'post_status'      => array_merge( array_values( get_post_stati() ), array( 'trash' ) ),
				'author'           => $user->ID,
				'posts_per_page'   => self::PAGE_SIZE,
				'paged'            => $page,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
			)
		);

		$items = array();
		foreach ( $posts as $pin ) {
			$thumb = (int) get_post_thumbnail_id( $pin->ID );
			$url   = $thumb ? (string) wp_get_attachment_url( $thumb ) : '';
			$items[] = array(
				'group_id'    => 'korkwand',
				'group_label' => __( 'Korkwand', 'korkwand' ),
				'item_id'     => 'korkwand-note-' . $pin->ID,
				'data'        => array(
					array( 'name' => __( 'Title', 'korkwand' ), 'value' => wp_specialchars_decode( $pin->post_title, ENT_QUOTES ) ),
					array( 'name' => __( 'Text', 'korkwand' ), 'value' => wp_specialchars_decode( $pin->post_content, ENT_QUOTES ) ),
					array( 'name' => __( 'Date', 'korkwand' ), 'value' => $pin->post_date_gmt . ' UTC' ),
					array( 'name' => __( 'Image', 'korkwand' ), 'value' => $url ),
				),
			);
		}
		return array( 'data' => $items, 'done' => count( $posts ) < self::PAGE_SIZE );
	}

	/**
	 * Erases up to 50 notes per call. Since erased notes disappear from the query,
	 * the next batch is always loaded regardless of $page.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public static function eraser( string $email, int $page = 1 ): array {
		$removed  = 0;
		$retained = false;
		$messages = array();
		$done     = true;
		$user     = get_user_by( 'email', $email );
		if ( $user ) {
			$removed = Cleanup::delete_batch( (int) $user->ID, self::PAGE_SIZE );
			if ( Cleanup::user_has_pins( (int) $user->ID ) ) {
				if ( $removed >= self::PAGE_SIZE ) {
					$done = false; // There may still be erasable items left: next batch.
				} else {
					$retained   = true; // The rest cannot be erased.
					$messages[] = __( 'Some notes could not be deleted.', 'korkwand' );
				}
			}
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}
}
