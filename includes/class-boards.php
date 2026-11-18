<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

/**
 * Access to boards (terms of the `korkwand_board` taxonomy) and their settings.
 */
final class Boards {

	private const DEFAULTS = array(
		'lifetime_days' => 100,
		'height'        => 700,
		'max_pins'      => 60,
		'notify'        => 0,
		'page_id'       => 0,
	);

	private const LIMITS = array(
		'lifetime_days' => array( 0, 3650 ),
		'height'        => array( 300, 3000 ),
		'max_pins'      => array( 1, 200 ),
		'page_id'       => array( 0, PHP_INT_MAX ),
	);

	public static function register(): void {
		add_action( 'save_post', array( self::class, 'detect_page' ), 10, 2 );
		add_action( 'transition_post_status', array( self::class, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( self::class, 'clear_auto_page' ) );
	}

	public static function get( string $slug ): ?\WP_Term {
		if ( '' === $slug ) {
			return null;
		}
		$term = get_term_by( 'slug', $slug, 'korkwand_board' );
		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * @return array{lifetime_days:int,height:int,max_pins:int,page_id:int,notify:bool}
	 */
	public static function settings( \WP_Term $term ): array {
		$out = array();
		foreach ( self::LIMITS as $key => $limits ) {
			$raw         = get_term_meta( $term->term_id, $key, true );
			$value       = ( '' === $raw || false === $raw ) ? self::DEFAULTS[ $key ] : (int) $raw;
			$out[ $key ] = self::clamp( $value, $limits[0], $limits[1] );
		}
		$raw           = get_term_meta( $term->term_id, 'notify', true );
		$out['notify'] = '' === $raw ? (bool) self::DEFAULTS['notify'] : (bool) (int) $raw;

		return $out;
	}

	/**
	 * Stores sanitized and clamped settings; missing keys remain unchanged.
	 *
	 * @param int                  $term_id Term-ID.
	 * @param array<string, mixed> $raw     Rohdaten (z. B. $_POST).
	 */
	public static function save_settings( int $term_id, array $raw ): void {
		foreach ( self::LIMITS as $key => $limits ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			$value = self::clamp( (int) $raw[ $key ], $limits[0], $limits[1] );
			update_term_meta( $term_id, $key, $value );
		}
		if ( array_key_exists( 'notify', $raw ) ) {
			update_term_meta( $term_id, 'notify', rest_sanitize_boolean( $raw['notify'] ) ? 1 : 0 );
		}
	}

	/**
	 * URL of the page showing the board: explicit page (if published), else the auto-detected one, else null.
	 */
	public static function page_url( \WP_Term $term ): ?string {
		$candidates = array(
			self::settings( $term )['page_id'],
			(int) get_term_meta( $term->term_id, 'auto_page_id', true ),
		);
		foreach ( $candidates as $id ) {
			if ( $id > 0 && 'publish' === get_post_status( $id ) && ! post_password_required( $id ) ) {
				$url = get_permalink( $id );
				if ( is_string( $url ) && '' !== $url ) {
					return $url;
				}
			}
		}
		return null;
	}

	/**
	 * save_post: remember the first published page/post that embeds a board (shortcode or block).
	 *
	 * @param int      $post_id Post-ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function detect_page( $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public || 'publish' !== $post->post_status ) {
			return;
		}
		$slugs = self::embedded_slugs( (string) $post->post_content );

		// A page that no longer embeds its board stops being its auto page.
		foreach ( self::boards_with_auto_page( (int) $post_id ) as $term ) {
			if ( ! in_array( $term->slug, $slugs, true ) ) {
				delete_term_meta( $term->term_id, 'auto_page_id' );
			}
		}

		foreach ( $slugs as $slug ) {
			$term = self::get( $slug );
			if ( ! $term || self::settings( $term )['page_id'] > 0 ) {
				continue;
			}
			$old = (int) get_term_meta( $term->term_id, 'auto_page_id', true );
			if ( $old > 0 && $old !== (int) $post_id && self::is_valid_auto_page( $old, $term->slug ) ) {
				continue;
			}
			update_term_meta( $term->term_id, 'auto_page_id', (int) $post_id );
		}
	}

	public static function on_transition( $new_status, $old_status, $post ): void {
		if ( 'publish' === $old_status && 'publish' !== $new_status && $post instanceof \WP_Post ) {
			self::clear_auto_page( $post->ID );
		}
	}

	/** Clears `auto_page_id` of every board that points to this post. */
	public static function clear_auto_page( $post_id ): void {
		foreach ( self::boards_with_auto_page( (int) $post_id ) as $term ) {
			delete_term_meta( $term->term_id, 'auto_page_id' );
		}
	}

	/**
	 * @return \WP_Term[]
	 */
	private static function boards_with_auto_page( int $post_id ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'korkwand_board',
				'hide_empty' => false,
				'meta_key'   => 'auto_page_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $post_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return is_wp_error( $terms ) ? array() : array_filter( $terms, static fn( $t ) => $t instanceof \WP_Term );
	}

	private static function is_valid_auto_page( int $post_id, string $slug ): bool {
		$post = get_post( $post_id );
		return $post instanceof \WP_Post
			&& 'publish' === $post->post_status
			&& in_array( $slug, self::embedded_slugs( (string) $post->post_content ), true );
	}

	/**
	 * Board slugs embedded via [korkwand board="..."] or the korkwand/board block (nested blocks included).
	 *
	 * @return string[]
	 */
	public static function embedded_slugs( string $content ): array {
		$slugs = array();
		if ( false !== stripos( $content, '[korkwand' ) && preg_match_all( '/' . get_shortcode_regex( array( 'korkwand' ) ) . '/', $content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$atts = shortcode_parse_atts( $match[3] );
				if ( is_array( $atts ) && ! empty( $atts['board'] ) ) {
					$slugs[] = sanitize_title( (string) $atts['board'] );
				}
			}
		}
		if ( false !== strpos( $content, 'wp:korkwand/board' ) ) {
			$walk = static function ( array $blocks ) use ( &$walk, &$slugs ): void {
				foreach ( $blocks as $block ) {
					if ( 'korkwand/board' === ( $block['blockName'] ?? '' ) && ! empty( $block['attrs']['board'] ) ) {
						$slugs[] = sanitize_title( (string) $block['attrs']['board'] );
					}
					if ( ! empty( $block['innerBlocks'] ) ) {
						$walk( $block['innerBlocks'] );
					}
				}
			};
			$walk( parse_blocks( $content ) );
		}
		return array_values( array_unique( array_filter( $slugs ) ) );
	}

	private static function clamp( int $value, int $min, int $max ): int {
		return max( $min, min( $max, $value ) );
	}
}
