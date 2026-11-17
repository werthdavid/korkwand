<?php
/**
 * Uninstall: removes capabilities, cron, options and (by default) all notes including images and boards.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'KORKWAND_DIR' ) ) {
	define( 'KORKWAND_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! class_exists( 'Korkwand\\Cleanup', true ) ) {
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
}

\Korkwand\Cleanup::uninstall();
