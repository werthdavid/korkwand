<?php
/**
 * PHPUnit-Bootstrap fuer nz-pinnwand.
 */

$nzp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $nzp_tests_dir ) {
	$nzp_tests_dir = '/wordpress-phpunit';
}

$nzp_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';
if ( ! getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && is_dir( $nzp_polyfills ) ) {
	putenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . $nzp_polyfills );
}

if ( ! file_exists( $nzp_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WP-Testbibliothek nicht gefunden unter {$nzp_tests_dir}. WP_TESTS_DIR setzen.\n" );
	exit( 1 );
}

require_once $nzp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/nz-pinnwand.php';
	}
);

require $nzp_tests_dir . '/includes/bootstrap.php';

// Aktivierung einmalig ausfuehren (Tabellen/Optionen der spaeteren Tasks).
\NZ\Pinnwand\Plugin::activate();
