<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

final class Notify {

	/**
	 * Mail to the site admin about a new note. Plain text only; the subject contains
	 * only the board name, a term name sanitized by WordPress (no line breaks).
	 */
	public static function new_pin( \WP_Post $pin ): void {
		$data = Pins::to_array( $pin, 0 );
		$name = wp_specialchars_decode( (string) get_the_author_meta( 'display_name', (int) $pin->post_author ), ENT_QUOTES );
		$link = admin_url( 'post.php?post=' . (int) $pin->ID . '&action=edit' );

		$body = sprintf(
			/* translators: 1: note title, 2: author display name, 3: edit link */
			__( "A new note has been created.\n\nTitle: %1\$s\nBy: %2\$s\nEdit: %3\$s\n", 'korkwand' ),
			$data['title'],
			$name,
			$link
		);

		$term       = wp_get_object_terms( (int) $pin->ID, 'korkwand_board' );
		$board_name = ( ! is_wp_error( $term ) && $term && $term[0] instanceof \WP_Term ) ? $term[0]->name : '';
		$subject    = sprintf(
			/* translators: %s: board name */
			__( 'New note on board "%s"', 'korkwand' ),
			wp_specialchars_decode( $board_name, ENT_QUOTES )
		);

		wp_mail( (string) get_option( 'admin_email' ), $subject, $body );
	}
}
