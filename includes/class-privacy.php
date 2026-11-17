<?php
namespace NZ\Pinnwand;

defined( 'ABSPATH' ) || exit;

/**
 * Integration in die WordPress-Datenschutzwerkzeuge (Export/Loeschung personenbezogener Daten).
 */
final class Privacy {

	private const PAGE_SIZE = 50;

	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'add_eraser' ) );
	}

	public static function add_exporter( $exporters ) {
		$exporters['nz-pinnwand'] = array(
			'exporter_friendly_name' => __( 'Pinnwand', 'nz-pinnwand' ),
			'callback'               => array( self::class, 'exporter' ),
		);
		return $exporters;
	}

	public static function add_eraser( $erasers ) {
		$erasers['nz-pinnwand'] = array(
			'eraser_friendly_name' => __( 'Pinnwand', 'nz-pinnwand' ),
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
				'post_type'        => 'pin',
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
				'group_id'    => 'nz-pinnwand',
				'group_label' => __( 'Pinnwand', 'nz-pinnwand' ),
				'item_id'     => 'pin-' . $pin->ID,
				'data'        => array(
					array( 'name' => __( 'Titel', 'nz-pinnwand' ), 'value' => wp_specialchars_decode( $pin->post_title, ENT_QUOTES ) ),
					array( 'name' => __( 'Text', 'nz-pinnwand' ), 'value' => wp_specialchars_decode( $pin->post_content, ENT_QUOTES ) ),
					array( 'name' => __( 'Datum', 'nz-pinnwand' ), 'value' => $pin->post_date_gmt . ' UTC' ),
					array( 'name' => __( 'Bild', 'nz-pinnwand' ), 'value' => $url ),
				),
			);
		}
		return array( 'data' => $items, 'done' => count( $posts ) < self::PAGE_SIZE );
	}

	/**
	 * Loescht pro Aufruf bis zu 50 Zettel. Da geloeschte Zettel aus der Abfrage verschwinden,
	 * wird unabhaengig von $page stets die naechste Charge geladen.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public static function eraser( string $email, int $page = 1 ): array {
		$removed = 0;
		$user    = get_user_by( 'email', $email );
		if ( $user ) {
			$removed = Cleanup::delete_batch( (int) $user->ID, self::PAGE_SIZE );
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $removed < self::PAGE_SIZE,
		);
	}
}
