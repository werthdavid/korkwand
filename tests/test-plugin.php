<?php
/**
 * Smoke-Test: Plugin ist geladen.
 */
class NZP_Test_Plugin extends WP_UnitTestCase {

	public function test_plugin_booted() {
		$this->assertTrue( defined( 'NZP_VERSION' ) );
		$this->assertTrue( class_exists( \NZ\Pinnwand\Plugin::class ) );
	}

	public function test_upgrade_routine_restores_caps_and_cron() {
		\NZ\Pinnwand\Permissions::remove_caps();
		wp_clear_scheduled_hook( 'nzp_daily_cleanup' );
		delete_option( 'nzp_version' );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'pinnwand_post' ) );

		\NZ\Pinnwand\Plugin::maybe_upgrade();

		$this->assertTrue( get_role( 'subscriber' )->has_cap( 'pinnwand_post' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'pinnwand_moderate' ) );
		$this->assertNotFalse( wp_next_scheduled( 'nzp_daily_cleanup' ) );
		$this->assertSame( NZP_VERSION, get_option( 'nzp_version' ) );
	}

	public function test_upgrade_routine_is_noop_when_version_current() {
		update_option( 'nzp_version', NZP_VERSION, false );
		\NZ\Pinnwand\Permissions::remove_caps();
		\NZ\Pinnwand\Plugin::maybe_upgrade();
		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'pinnwand_post' ) );
		\NZ\Pinnwand\Permissions::install_caps();
	}

	public function test_activate_sets_version_option() {
		delete_option( 'nzp_version' );
		\NZ\Pinnwand\Plugin::activate();
		$this->assertSame( NZP_VERSION, get_option( 'nzp_version' ) );
	}
}
