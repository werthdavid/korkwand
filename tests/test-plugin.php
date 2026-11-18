<?php
/**
 * Smoke test: plugin is loaded.
 */
class Korkwand_Test_Plugin extends WP_UnitTestCase {

	public function test_plugin_booted() {
		$this->assertTrue( defined( 'KORKWAND_VERSION' ) );
		$this->assertTrue( class_exists( \Korkwand\Plugin::class ) );
	}

	public function test_version_comes_from_plugin_header() {
		$header = get_file_data( KORKWAND_FILE, array( 'Version' => 'Version' ) )['Version'];
		$this->assertNotSame( '', $header );
		$this->assertSame( $header, KORKWAND_VERSION );

		$readme = (string) file_get_contents( KORKWAND_DIR . 'readme.txt' );
		$this->assertMatchesRegularExpression( '/^Stable tag:\s*' . preg_quote( $header, '/' ) . '\s*$/m', $readme );
		$this->assertStringContainsString( '= ' . $header . ' =', $readme, 'Changelog entry missing' );

		foreach ( array( 'board', 'latest' ) as $block ) {
			$meta = json_decode( (string) file_get_contents( KORKWAND_DIR . "blocks/$block/block.json" ), true );
			$this->assertArrayNotHasKey( 'version', $meta, "blocks/$block/block.json must not carry its own version" );
		}
	}

	public function test_no_upgrade_routine_or_version_option() {
		$this->assertFalse( method_exists( \Korkwand\Plugin::class, 'maybe_upgrade' ) );
		delete_option( 'korkwand_version' );
		\Korkwand\Plugin::activate();
		$this->assertFalse( get_option( 'korkwand_version', false ) );
	}

	public function test_activate_installs_role_caps_and_cron() {
		\Korkwand\Permissions::remove_caps();
		wp_clear_scheduled_hook( 'korkwand_daily_cleanup' );
		\Korkwand\Plugin::activate();
		$this->assertNotNull( get_role( 'korkwand_editor' ) );
		$this->assertTrue( get_role( 'korkwand_editor' )->has_cap( 'korkwand_post' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'korkwand_moderate' ) );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'korkwand_post' ) );
		$this->assertNotFalse( wp_next_scheduled( 'korkwand_daily_cleanup' ) );
	}
}
