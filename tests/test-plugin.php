<?php
/**
 * Smoke-Test: Plugin ist geladen.
 */
class NZP_Test_Plugin extends WP_UnitTestCase {

	public function test_plugin_booted() {
		$this->assertTrue( defined( 'NZP_VERSION' ) );
		$this->assertTrue( class_exists( \NZ\Pinnwand\Plugin::class ) );
	}
}
