<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the post type `korkwand_pin`, the taxonomy `korkwand_board` and post/term meta.
 */
final class Model {

	public static function register(): void {
		register_post_type(
			'korkwand_pin',
			array(
				'labels'          => array(
					'name'          => __( 'Notes', 'korkwand' ),
					'singular_name' => __( 'Note', 'korkwand' ),
					'menu_name'     => __( 'Korkwand', 'korkwand' ),
					'all_items'     => __( 'All Notes', 'korkwand' ),
					'add_new_item'  => __( 'Add New Note', 'korkwand' ),
					'edit_item'     => __( 'Edit Note', 'korkwand' ),
					'search_items'  => __( 'Search Notes', 'korkwand' ),
					'not_found'     => __( 'No notes found.', 'korkwand' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_rest'    => false,
				'supports'        => array( 'title', 'author', 'thumbnail' ),
				'capability_type' => array( 'korkwand_pin', 'korkwand_pins' ),
				// Notes are created only in the front end; in the back end they can be reviewed, edited and deleted.
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
				'menu_icon'       => 'dashicons-sticky',
			)
		);

		register_taxonomy(
			'korkwand_board',
			'korkwand_pin',
			array(
				'labels'            => array(
					'name'                       => __( 'Boards', 'korkwand' ),
					'singular_name'              => __( 'Board', 'korkwand' ),
					'menu_name'                  => __( 'Boards', 'korkwand' ),
					'all_items'                  => __( 'All Boards', 'korkwand' ),
					'edit_item'                  => __( 'Edit Board', 'korkwand' ),
					'view_item'                  => __( 'View Board', 'korkwand' ),
					'update_item'                => __( 'Update Board', 'korkwand' ),
					'add_new_item'               => __( 'Add New Board', 'korkwand' ),
					'new_item_name'              => __( 'New Board Name', 'korkwand' ),
					'search_items'               => __( 'Search Boards', 'korkwand' ),
					'popular_items'              => __( 'Popular Boards', 'korkwand' ),
					'separate_items_with_commas' => __( 'Separate boards with commas', 'korkwand' ),
					'add_or_remove_items'        => __( 'Add or remove boards', 'korkwand' ),
					'choose_from_most_used'      => __( 'Choose from the most used boards', 'korkwand' ),
					'not_found'                  => __( 'No boards found.', 'korkwand' ),
					'no_terms'                   => __( 'No boards', 'korkwand' ),
					'items_list'                 => __( 'Boards list', 'korkwand' ),
					'items_list_navigation'      => __( 'Boards list navigation', 'korkwand' ),
					'back_to_items'              => __( '&larr; Go to Boards', 'korkwand' ),
					'item_updated'               => __( 'Board updated.', 'korkwand' ),
					'item_added'                 => __( 'Board added.', 'korkwand' ),
					'item_deleted'               => __( 'Board deleted.', 'korkwand' ),
				),
				'hierarchical'      => false,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => false,
				'capabilities'      => array(
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'manage_options',
				),
			)
		);

		$post_meta = array(
			'_korkwand_type'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'_korkwand_x'        => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'_korkwand_y'        => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'_korkwand_rotation' => array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'sanitize_rotation' ),
			),
			'_korkwand_sticky'   => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
		foreach ( $post_meta as $key => $args ) {
			register_post_meta(
				'korkwand_pin',
				$key,
				$args + array(
					'single'       => true,
					'show_in_rest' => false,
				)
			);
		}

		$term_meta = array(
			'korkwand_lifetime_days' => 'integer',
			'korkwand_height'        => 'integer',
			'korkwand_max_pins'      => 'integer',
			'korkwand_notify'        => 'boolean',
			'korkwand_page_id'       => 'integer',
			'korkwand_auto_page_id'  => 'integer',
		);
		foreach ( $term_meta as $key => $type ) {
			register_term_meta(
				'korkwand_board',
				$key,
				array(
					'type'         => $type,
					'single'       => true,
					'show_in_rest' => false,
				)
			);
		}
	}

	/**
	 * Rotation: integer, clamped to -6..6.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_rotation( $value ): int {
		return max( -6, min( 6, (int) $value ) );
	}
}
