<?php
/**
 * Plugin Name:       NZ Pinnwand
 * Description:       Pinnwand (Korkwand mit Post-its und Bildern) fuer WordPress.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Text Domain:       korkwand
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'KORKWAND_VERSION', '0.1.0' );
define( 'KORKWAND_FILE', __FILE__ );
define( 'KORKWAND_DIR', plugin_dir_path( __FILE__ ) );
define( 'KORKWAND_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Korkwand\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = KORKWAND_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( KORKWAND_FILE, array( \Korkwand\Plugin::class, 'activate' ) );
register_deactivation_hook( KORKWAND_FILE, array( \Korkwand\Plugin::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( \Korkwand\Plugin::class, 'boot' ) );
