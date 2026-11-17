<?php
/**
 * PHPUnit bootstrap for korkwand.
 */

$korkwand_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $korkwand_tests_dir ) {
	$korkwand_tests_dir = '/wordpress-phpunit';
}

$korkwand_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';
if ( ! getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && is_dir( $korkwand_polyfills ) ) {
	putenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . $korkwand_polyfills );
}

if ( ! file_exists( $korkwand_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WP-Testbibliothek nicht gefunden unter {$korkwand_tests_dir}. WP_TESTS_DIR setzen.\n" );
	exit( 1 );
}

require_once $korkwand_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/korkwand.php';
	}
);

require $korkwand_tests_dir . '/includes/bootstrap.php';

// Run activation once (tables/options for the later tasks).
\Korkwand\Plugin::activate();
