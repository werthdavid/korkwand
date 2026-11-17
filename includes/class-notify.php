<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

final class Notify {

	/**
	 * Mail an den Site-Admin ueber einen neuen Zettel. Nur Klartext; Betreff ist konstant
	 * (keine Nutzereingaben in Headern).
	 */
	public static function new_pin( \WP_Post $pin ): void {
		$data = Pins::to_array( $pin, 0 );
		$name = wp_specialchars_decode( (string) get_the_author_meta( 'display_name', (int) $pin->post_author ), ENT_QUOTES );
		$link = admin_url( 'post.php?post=' . (int) $pin->ID . '&action=edit' );

		$body = sprintf(
			/* translators: 1: Zettel-Titel, 2: Anzeigename, 3: Link */
			__( "Es wurde ein neuer Zettel angelegt.\n\nTitel: %1\$s\nVon: %2\$s\nBearbeiten: %3\$s\n", 'korkwand' ),
			$data['title'],
			$name,
			$link
		);

		wp_mail( (string) get_option( 'admin_email' ), __( 'Neuer Zettel auf der Pinnwand', 'korkwand' ), $body );
	}
}
