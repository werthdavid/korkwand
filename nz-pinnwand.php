<?php
/**
 * Plugin Name:       NZ Pinnwand
 * Description:       Pinnwand (Korkwand mit Post-its und Bildern) fuer WordPress.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Text Domain:       nz-pinnwand
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'NZP_VERSION', '0.1.0' );
define( 'NZP_FILE', __FILE__ );
define( 'NZP_DIR', plugin_dir_path( __FILE__ ) );
define( 'NZP_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'NZ\\Pinnwand\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = NZP_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( NZP_FILE, array( \NZ\Pinnwand\Plugin::class, 'activate' ) );
register_deactivation_hook( NZP_FILE, array( \NZ\Pinnwand\Plugin::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( \NZ\Pinnwand\Plugin::class, 'boot' ) );
