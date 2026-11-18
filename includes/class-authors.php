<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps note authors from being enumerated: core redirects /?author=N to /author/<nicename>/ and shows
 * the author archive for any user with a published post of ANY type, which includes notes. For users
 * who have no public content other than notes, the author archive answers 404 and ?author=N does not redirect.
 */
final class Authors {

	public static function register(): void {
		// Before redirect_canonical (template_redirect priority 10).
		add_action( 'template_redirect', array( self::class, 'maybe_404' ), 1 );
		add_filter( 'redirect_canonical', array( self::class, 'no_author_redirect' ) );
	}

	/** True for users without any published post of a public post type (notes are not public). */
	public static function is_hidden( int $user_id ): bool {
		return $user_id > 0 && 0 === (int) count_user_posts( $user_id, array_values( get_post_types( array( 'public' => true ) ) ), true );
	}

	private static function queried_hidden_author(): bool {
		if ( ! is_author() ) {
			return false;
		}
		$user = get_queried_object();
		return $user instanceof \WP_User && self::is_hidden( (int) $user->ID );
	}

	public static function maybe_404(): void {
		if ( ! self::queried_hidden_author() ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * @param string|false $redirect_url Proposed canonical URL.
	 * @return string|false
	 */
	public static function no_author_redirect( $redirect_url ) {
		return self::queried_hidden_author() ? false : $redirect_url;
	}
}
