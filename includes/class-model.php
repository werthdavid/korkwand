<?php
namespace NZ\Pinnwand;

defined( 'ABSPATH' ) || exit;

/**
 * Registriert Post Type `pin`, Taxonomie `pinboard` sowie Post-/Term-Meta.
 */
final class Model {

	public static function register(): void {
		register_post_type(
			'pin',
			array(
				'labels'          => array(
					'name'          => __( 'Pinnwand', 'nz-pinnwand' ),
					'singular_name' => __( 'Zettel', 'nz-pinnwand' ),
					'menu_name'     => __( 'Pinnwand', 'nz-pinnwand' ),
					'add_new_item'  => __( 'Neuen Zettel hinzufügen', 'nz-pinnwand' ),
					'edit_item'     => __( 'Zettel bearbeiten', 'nz-pinnwand' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_rest'    => false,
				'supports'        => array( 'title', 'author', 'thumbnail' ),
				'capability_type' => array( 'pin', 'pins' ),
				// Zettel entstehen nur im Frontend; im Backend lassen sie sich pruefen, bearbeiten, loeschen.
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
				'menu_icon'       => 'dashicons-sticky',
			)
		);

		register_taxonomy(
			'pinboard',
			'pin',
			array(
				'labels'            => array(
					'name'          => __( 'Pinnwände', 'nz-pinnwand' ),
					'singular_name' => __( 'Pinnwand', 'nz-pinnwand' ),
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
			'_pin_type'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'_pin_x'        => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'_pin_y'        => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'_pin_rotation' => array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'sanitize_rotation' ),
			),
			'_pin_sticky'   => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
		foreach ( $post_meta as $key => $args ) {
			register_post_meta(
				'pin',
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
				'pinboard',
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
