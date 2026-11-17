<?php
/**
 * Deinstallation: entfernt Rechte, Cron, Optionen und (Standard) alle Zettel samt Bildern und Pinnwaende.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'NZP_DIR' ) ) {
	define( 'NZP_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! class_exists( 'NZ\\Pinnwand\\Cleanup', true ) ) {
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
}

\NZ\Pinnwand\Cleanup::uninstall();
