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
				'suppress_filters' => true,
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
