<?php
/**
 * Smoke-Test: Plugin ist geladen.
 */
class Korkwand_Test_Plugin extends WP_UnitTestCase {

	public function test_plugin_booted() {
		$this->assertTrue( defined( 'KORKWAND_VERSION' ) );
		$this->assertTrue( class_exists( \Korkwand\Plugin::class ) );
	}

	public function test_upgrade_routine_restores_caps_and_cron() {
		\Korkwand\Permissions::remove_caps();
		wp_clear_scheduled_hook( 'korkwand_daily_cleanup' );
		delete_option( 'korkwand_version' );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'korkwand_post' ) );

		\Korkwand\Plugin::maybe_upgrade();

		$this->assertTrue( get_role( 'subscriber' )->has_cap( 'korkwand_post' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'korkwand_moderate' ) );
		$this->assertNotFalse( wp_next_scheduled( 'korkwand_daily_cleanup' ) );
		$this->assertSame( KORKWAND_VERSION, get_option( 'korkwand_version' ) );
	}

	public function test_upgrade_routine_is_noop_when_version_current() {
		update_option( 'korkwand_version', KORKWAND_VERSION, false );
		\Korkwand\Permissions::remove_caps();
		\Korkwand\Plugin::maybe_upgrade();
		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'korkwand_post' ) );
		\Korkwand\Permissions::install_caps();
	}

	public function test_activate_sets_version_option() {
		delete_option( 'korkwand_version' );
		\Korkwand\Plugin::activate();
		$this->assertSame( KORKWAND_VERSION, get_option( 'korkwand_version' ) );
	}
}
