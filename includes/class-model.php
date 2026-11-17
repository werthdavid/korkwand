<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

/**
 * Registriert Post Type `pin`, Taxonomie `korkwand_board` sowie Post-/Term-Meta.
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
				// Zettel entstehen nur im Frontend; im Backend lassen sie sich pruefen, bearbeiten, loeschen.
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
					'name'          => __( 'Boards', 'korkwand' ),
					'singular_name' => __( 'Board', 'korkwand' ),
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
			'lifetime_days' => 'integer',
			'height'        => 'integer',
			'max_pins'      => 'integer',
			'notify'        => 'boolean',
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
	 * Rotation: Ganzzahl, geklemmt auf -6..6.
	 *
	 * @param mixed $value Rohwert.
	 */
	public static function sanitize_rotation( $value ): int {
		return max( -6, min( 6, (int) $value ) );
	}
}
