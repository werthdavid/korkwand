<?php
namespace NZ\Pinnwand;

defined( 'ABSPATH' ) || exit;

/**
 * Domaenen-Service fuer Zettel (Post Type `pin`): anlegen, aendern, abfragen, serialisieren.
 * Autorisierung (ausser `sticky`) liegt beim Aufrufer (REST via Permissions).
 */
final class Pins {

	public const WIDTH_MAX = 800;

	private const TYPES = array( 'postit', 'image' );

	private const MAX_TITLE   = 60;
	private const MAX_POSTIT  = 300;
	private const MAX_CAPTION = 100;

	/**
	 * @param array<string, mixed> $data type, title, text, attachment_id, x, y.
	 * @return \WP_Post|\WP_Error
	 */
	public static function create( \WP_Term $board, int $user_id, array $data ) {
		$type = isset( $data['type'] ) && is_string( $data['type'] ) ? $data['type'] : '';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return self::invalid( __( 'Ungültiger Zettel-Typ.', 'nz-pinnwand' ) );
		}

		$text  = self::clean_text( $data['text'] ?? '' );
		$thumb = 0;
		if ( 'postit' === $type ) {
			$title = self::clean_title( $data['title'] ?? '' );
			$err   = self::validate_title( $title ) ?? self::validate_text( $text, self::MAX_POSTIT );
		} else {
			$title = __( 'Bild', 'nz-pinnwand' );
			$thumb = isset( $data['attachment_id'] ) ? absint( $data['attachment_id'] ) : 0;
			$err   = self::validate_attachment( $thumb ) ?? self::validate_text( $text, self::MAX_CAPTION );
		}
		if ( $err ) {
			return $err;
		}

		$height = Boards::settings( $board )['height'];
		if ( isset( $data['x'] ) && isset( $data['y'] ) ) {
			$x = (int) $data['x'];
			$y = (int) $data['y'];
		} else {
			$x = random_int( 0, self::WIDTH_MAX );
			$y = random_int( 0, max( 0, $height - 200 ) );
		}
		list( $x, $y ) = self::clamp_position( $x, $y, $height );

		$post_id = self::write_post(
			array(
				'post_type'    => 'pin',
				'post_status'  => 'publish',
				'post_author'  => $user_id,
				'post_title'   => $title,
				'post_content' => $text,
			)
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_pin_type', $type );
		update_post_meta( $post_id, '_pin_x', $x );
		update_post_meta( $post_id, '_pin_y', $y );
		update_post_meta( $post_id, '_pin_rotation', random_int( -6, 6 ) );
		update_post_meta( $post_id, '_pin_sticky', 0 );
		wp_set_object_terms( $post_id, array( $board->term_id ), 'pinboard' );
		if ( $thumb ) {
			set_post_thumbnail( $post_id, $thumb );
			// Unzugeordnetes Bild dem Zettel zuordnen (Mediathek-Bilder mit Elternbeitrag bleiben unveraendert).
			if ( 0 === (int) wp_get_post_parent_id( $thumb ) ) {
				wp_update_post(
					array(
						'ID'          => $thumb,
						'post_parent' => $post_id,
					)
				);
			}
		}

		return get_post( $post_id );
	}

	/**
	 * @param array<string, mixed> $data x, y, title, text, sticky. Typ und Bild sind unveraenderlich.
	 * @return \WP_Post|\WP_Error
	 */
	public static function update( \WP_Post $pin, array $data, int $user_id ) {
		$type    = self::type_of( $pin );
		$changes = array();

		if ( 'postit' === $type && array_key_exists( 'title', $data ) ) {
			$title = self::clean_title( $data['title'] );
			$err   = self::validate_title( $title );
			if ( $err ) {
				return $err;
			}
			$changes['post_title'] = $title;
		}
		if ( array_key_exists( 'text', $data ) ) {
			$text = self::clean_text( $data['text'] );
			$err  = self::validate_text( $text, 'image' === $type ? self::MAX_CAPTION : self::MAX_POSTIT );
			if ( $err ) {
				return $err;
			}
			$changes['post_content'] = $text;
		}

		if ( $changes ) {
			$res = self::write_post( $changes + array( 'ID' => $pin->ID ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		if ( isset( $data['x'] ) || isset( $data['y'] ) ) {
			$height = self::board_height( $pin );
			$x      = isset( $data['x'] ) ? (int) $data['x'] : (int) get_post_meta( $pin->ID, '_pin_x', true );
			$y      = isset( $data['y'] ) ? (int) $data['y'] : (int) get_post_meta( $pin->ID, '_pin_y', true );
			list( $x, $y ) = self::clamp_position( $x, $y, $height );
			update_post_meta( $pin->ID, '_pin_x', $x );
			update_post_meta( $pin->ID, '_pin_y', $y );
		}

		if ( array_key_exists( 'sticky', $data ) && Permissions::can_moderate( $user_id ) ) {
			update_post_meta( $pin->ID, '_pin_sticky', rest_sanitize_boolean( $data['sticky'] ) ? 1 : 0 );
		}

		clean_post_cache( $pin->ID );
		return get_post( $pin->ID );
	}

	/**
	 * @return array{0:int,1:int}
	 */
	public static function clamp_position( int $x, int $y, int $board_height ): array {
		return array(
			max( 0, min( self::WIDTH_MAX, $x ) ),
			max( 0, min( max( 0, $board_height - 100 ), $y ) ),
		);
	}

	/** True nur, wenn der Zettel tatsaechlich im Papierkorb gelandet ist. */
	public static function trash( \WP_Post $pin ): bool {
		return wp_trash_post( $pin->ID ) instanceof \WP_Post;
	}

	/**
	 * Sichtbare Zettel: Sticky zuerst, dann neueste; insgesamt max. `max_pins`.
	 * Ablauf-Vergleich ueber post_date_gmt gegen UTC.
	 *
	 * @return \WP_Post[]
	 */
	public static function visible( \WP_Term $board ): array {
		$s     = Boards::settings( $board );
		$limit = $s['max_pins'];
		$base  = array(
			'post_type'              => 'pin',
			'post_status'            => 'publish',
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'tax_query'              => array(
				array(
					'taxonomy' => 'pinboard',
					'field'    => 'term_id',
					'terms'    => array( $board->term_id ),
				),
			),
		);

		$sticky = ( new \WP_Query(
			$base + array(
				'posts_per_page' => $limit,
				'meta_query'     => array(
					array(
						'key'   => '_pin_sticky',
						'value' => '1',
					),
				),
			)
		) )->posts;

		$rest = $limit - count( $sticky );
		if ( $rest <= 0 ) {
			return array_slice( $sticky, 0, $limit );
		}

		$args = $base + array(
			'posts_per_page' => $rest,
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_pin_sticky',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => '_pin_sticky',
					'value' => '1',
					'compare' => '!=',
				),
			),
		);
		if ( $s['lifetime_days'] > 0 ) {
			$args['date_query'] = array(
				array(
					'column'    => 'post_date_gmt',
					'after'     => gmdate( 'Y-m-d H:i:s', time() - $s['lifetime_days'] * DAY_IN_SECONDS ),
					'inclusive' => true,
				),
			);
		}
		$normal = ( new \WP_Query( $args ) )->posts;

		return array_merge( $sticky, $normal );
	}

	public static function expires_at( \WP_Post $pin, \WP_Term $board ): ?\DateTimeImmutable {
		$days = Boards::settings( $board )['lifetime_days'];
		if ( $days <= 0 || self::is_sticky( $pin ) ) {
			return null;
		}
		$created = get_post_datetime( $pin, 'date', 'gmt' );
		if ( ! $created ) {
			return null;
		}
		return $created->modify( '+' . ( $days * DAY_IN_SECONDS ) . ' seconds' )->setTimezone( wp_timezone() );
	}

	/**
	 * @param \WP_Term|null $board Pinnwand des Zettels, wenn der Aufrufer sie kennt (spart eine Term-Abfrage pro Zettel).
	 * @return array<string, mixed>
	 */
	public static function to_array( \WP_Post $pin, int $viewer_id, ?\WP_Term $board = null ): array {
		$type  = self::type_of( $pin );
		$board = $board ?? self::board_of( $pin );
		$exp   = $board ? self::expires_at( $pin, $board ) : null;

		$out = array(
			'id'           => (int) $pin->ID,
			'type'         => $type,
			'title'        => self::decode( (string) $pin->post_title ),
			'text'         => self::decode( (string) $pin->post_content ),
			'x'            => (int) get_post_meta( $pin->ID, '_pin_x', true ),
			'y'            => (int) get_post_meta( $pin->ID, '_pin_y', true ),
			'rotation'     => (int) get_post_meta( $pin->ID, '_pin_rotation', true ),
			'sticky'       => self::is_sticky( $pin ),
			'image'        => 'image' === $type ? self::image_of( $pin ) : null,
			'can_edit'     => Permissions::can_edit( $viewer_id, $pin ),
			'can_moderate' => Permissions::can_moderate( $viewer_id ),
			'expires'      => $exp ? $exp->format( \DATE_ATOM ) : null,
		);
		if ( $viewer_id > 0 ) {
			$out['author'] = (string) get_the_author_meta( 'display_name', (int) $pin->post_author );
		}
		return $out;
	}

	/* ---------------------------------------------------------------- */

	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'nzp_invalid', $message, array( 'status' => 400 ) );
	}

	/**
	 * sanitize_*_field speichert "<" als "&lt;". Ausgabe und Laengenpruefung arbeiten auf dem
	 * decodierten Text; Escaping (esc_html) passiert beim Rendern.
	 */
	private static function decode( string $value ): string {
		return wp_specialchars_decode( $value, ENT_QUOTES );
	}

	private static function clean_title( $value ): string {
		return trim( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) );
	}

	private static function clean_text( $value ): string {
		$text = is_scalar( $value ) ? (string) $value : '';
		// Multipart liefert CRLF: auf LF normalisieren, damit die Zeichenzaehlung stimmt.
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		return trim( sanitize_textarea_field( $text ) );
	}

	private static function validate_title( string $title ): ?\WP_Error {
		$len = mb_strlen( self::decode( $title ) );
		if ( $len < 1 || $len > self::MAX_TITLE ) {
			return self::invalid( __( 'Der Titel muss 1 bis 60 Zeichen lang sein.', 'nz-pinnwand' ) );
		}
		return null;
	}

	private static function validate_text( string $text, int $max ): ?\WP_Error {
		if ( mb_strlen( self::decode( $text ) ) > $max ) {
			/* translators: %d: maximale Zeichenzahl */
			return self::invalid( sprintf( __( 'Der Text darf höchstens %d Zeichen lang sein.', 'nz-pinnwand' ), $max ) );
		}
		return null;
	}

	private static function validate_attachment( int $id ): ?\WP_Error {
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			return self::invalid( __( 'Ein gültiges Bild ist erforderlich.', 'nz-pinnwand' ) );
		}
		return null;
	}

	/**
	 * Speichert Klartext unveraendert: kses-Filter werden kurz entfernt (Text ist bereits bereinigt
	 * und wird nur escaped ausgegeben).
	 *
	 * @param array<string, mixed> $postarr
	 * @return int|\WP_Error
	 */
	private static function write_post( array $postarr ) {
		kses_remove_filters();
		try {
			$res = isset( $postarr['ID'] )
				? wp_update_post( wp_slash( $postarr ), true )
				: wp_insert_post( wp_slash( $postarr ), true );
		} finally {
			kses_init();
		}
		return $res;
	}

	private static function type_of( \WP_Post $pin ): string {
		$type = get_post_meta( $pin->ID, '_pin_type', true );
		return in_array( $type, self::TYPES, true ) ? $type : 'postit';
	}

	private static function is_sticky( \WP_Post $pin ): bool {
		return (bool) (int) get_post_meta( $pin->ID, '_pin_sticky', true );
	}

	private static function board_of( \WP_Post $pin ): ?\WP_Term {
		$terms = wp_get_object_terms( $pin->ID, 'pinboard' );
		return ( ! is_wp_error( $terms ) && $terms && $terms[0] instanceof \WP_Term ) ? $terms[0] : null;
	}

	private static function board_height( \WP_Post $pin ): int {
		$board = self::board_of( $pin );
		return $board ? Boards::settings( $board )['height'] : 700;
	}

	/**
	 * @return array{thumb:string,full:string,width:int,height:int}|null
	 */
	private static function image_of( \WP_Post $pin ): ?array {
		$id = (int) get_post_thumbnail_id( $pin->ID );
		if ( ! $id ) {
			return null;
		}
		$thumb = wp_get_attachment_image_src( $id, 'medium_large' );
		$full  = wp_get_attachment_image_src( $id, 'large' );
		if ( ! $thumb || ! $full ) {
			return null;
		}
		return array(
			'thumb'  => $thumb[0],
			'full'   => $full[0],
			'width'  => (int) $thumb[1],
			'height' => (int) $thumb[2],
		);
	}
}
