<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

/**
 * "Latest notes": a compact, server-rendered list of the newest visible notes
 * (shortcode [korkwand_latest] and block korkwand/latest).
 *
 * Visibility rules are those of Pins::visible() (published, not expired, within max_pins), but the
 * order is purely chronological: sticky notes do not float to the top of a news list.
 */
final class Latest {

	private const LABEL_MAX = 60;
	private const COUNT_MAX = 20;

	public static function register(): void {
		add_shortcode( 'korkwand_latest', array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_data' ) );
	}

	public static function register_style(): void {
		if ( ! wp_style_is( 'korkwand-latest-style', 'registered' ) ) {
			wp_register_style( 'korkwand-latest-style', KORKWAND_URL . 'assets/css/latest.css', array(), KORKWAND_VERSION );
		}
	}

	public static function register_block(): void {
		self::register_style();
		wp_register_script(
			'korkwand-latest-editor',
			KORKWAND_URL . 'blocks/latest/edit.js',
			array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			KORKWAND_VERSION,
			true
		);
		wp_set_script_translations( 'korkwand-latest-editor', 'korkwand', KORKWAND_DIR . 'languages' );
		register_block_type( KORKWAND_DIR . 'blocks/latest' );
	}

	/** Passes the board list to the block editor (only loaded in the editor, no REST endpoint needed). */
	public static function editor_data(): void {
		$terms  = get_terms( array( 'taxonomy' => 'korkwand_board', 'hide_empty' => false ) );
		$boards = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$boards[] = array( 'slug' => $term->slug, 'name' => $term->name );
			}
		}
		wp_add_inline_script( 'korkwand-latest-editor', 'window.korkwandBoards = ' . wp_json_encode( $boards ) . ';', 'before' );
	}

	/**
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'board'     => '',
				'count'     => 5,
				'show_date' => '1',
			),
			(array) $atts,
			'korkwand_latest'
		);
		return self::render( $atts );
	}

	/**
	 * @param array<string, mixed> $atts board (slug, '' = all), count (1-20), show_date (bool).
	 */
	public static function render( array $atts ): string {
		$slug      = sanitize_title( (string) ( $atts['board'] ?? '' ) );
		$count     = max( 1, min( self::COUNT_MAX, (int) ( $atts['count'] ?? 5 ) ) );
		$show_date = rest_sanitize_boolean( $atts['show_date'] ?? true );

		if ( '' !== $slug ) {
			$board = Boards::get( $slug );
			if ( ! $board ) {
				if ( current_user_can( 'manage_options' ) ) {
					return '<p class="korkwand-error">' . esc_html(
						sprintf(
							/* translators: %s: board slug */
							__( 'Board "%s" not found.', 'korkwand' ),
							$slug
						)
					) . '</p>';
				}
				return '';
			}
			$boards = array( $board );
		} else {
			$terms  = get_terms( array( 'taxonomy' => 'korkwand_board', 'hide_empty' => false ) );
			$boards = is_wp_error( $terms ) ? array() : array_values( array_filter( $terms, static fn( $t ) => $t instanceof \WP_Term ) );
		}

		self::register_style();
		wp_enqueue_style( 'korkwand-latest-style' );

		$entries = self::collect( $boards, $count );
		if ( ! $entries ) {
			return '<div class="korkwand-latest"><p class="korkwand-latest__empty">'
				. esc_html__( 'Nothing on the board yet.', 'korkwand' ) . '</p></div>';
		}

		$logged_in = get_current_user_id() > 0;
		if ( $logged_in ) {
			cache_users( array_values( array_unique( array_map( static fn( $e ) => (int) $e['pin']->post_author, $entries ) ) ) );
		}

		$urls = array();
		$out  = '<div class="korkwand-latest"><ul class="korkwand-latest__list">';
		foreach ( $entries as $e ) {
			$pin   = $e['pin'];
			$board = $e['board'];
			if ( ! array_key_exists( $board->term_id, $urls ) ) {
				$urls[ $board->term_id ] = Boards::page_url( $board );
			}
			$label = esc_html( self::label( $pin ) );

			$out .= '<li class="korkwand-latest__item">';
			$out .= null === $urls[ $board->term_id ]
				? '<span class="korkwand-latest__label">' . $label . '</span>'
				: '<a href="' . esc_url( $urls[ $board->term_id ] . '#korkwand-note-' . $pin->ID ) . '">' . $label . '</a>';

			$ts = (int) $e['ts'];
			if ( $show_date ) {
				$out .= ' <time datetime="' . esc_attr( wp_date( 'c', $ts ) ) . '">'
					. esc_html( wp_date( (string) get_option( 'date_format' ), $ts ) ) . '</time>';
			}
			if ( $logged_in ) {
				$name = (string) get_the_author_meta( 'display_name', (int) $pin->post_author );
				if ( '' !== $name ) {
					$out .= ' <span class="korkwand-latest__author">' . esc_html( $name ) . '</span>';
				}
			}
			$out .= '</li>';
		}
		return $out . '</ul></div>';
	}

	/**
	 * Newest visible notes across the given boards. One Pins::visible() call per board (a handful of
	 * queries, term/meta caches primed by WP_Query), merged and sorted by creation time.
	 *
	 * @param \WP_Term[] $boards Boards.
	 * @return array<int, array{pin:\WP_Post,board:\WP_Term,ts:int}>
	 */
	private static function collect( array $boards, int $count ): array {
		$entries = array();
		foreach ( $boards as $board ) {
			foreach ( Pins::visible( $board ) as $pin ) {
				$dt = get_post_datetime( $pin, 'date', 'gmt' );
				$entries[] = array(
					'pin'   => $pin,
					'board' => $board,
					'ts'    => $dt ? $dt->getTimestamp() : 0,
				);
			}
		}
		usort(
			$entries,
			static fn( $x, $y ) => ( $y['ts'] <=> $x['ts'] ) ?: ( $y['pin']->ID <=> $x['pin']->ID )
		);
		return array_slice( $entries, 0, $count );
	}

	/** Post-it: title. Image note: caption or "Photo". Whitespace collapsed, at most 60 characters. */
	private static function label( \WP_Post $pin ): string {
		if ( 'image' === get_post_meta( $pin->ID, '_korkwand_type', true ) ) {
			$text = self::clean( (string) $pin->post_content );
			if ( '' === $text ) {
				$text = __( 'Photo', 'korkwand' );
			}
		} else {
			$text = self::clean( (string) $pin->post_title );
		}
		if ( mb_strlen( $text ) > self::LABEL_MAX ) {
			$text = rtrim( mb_substr( $text, 0, self::LABEL_MAX - 1 ) ) . '…';
		}
		return $text;
	}

	private static function clean( string $value ): string {
		// Same decoding as Pins::to_array(): sanitize_*_field stores "<" as "&lt;"; escaping happens on output.
		return trim( (string) preg_replace( '/\s+/u', ' ', wp_specialchars_decode( $value, ENT_QUOTES ) ) );
	}
}
