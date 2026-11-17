<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		// Runs on plugins_loaded (boot hook): catch up capabilities/cron after updates.
		self::maybe_upgrade();
		add_action( 'init', array( Model::class, 'register' ) );
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
		add_action( 'template_redirect', array( Upload::class, 'block_attachment_page' ), 1 );
		Render::register();
		Cleanup::register();
		Privacy::register();
		add_action( 'admin_init', array( Admin::class, 'register_settings' ) );
		if ( is_admin() ) {
			Admin::register();
		}
	}

	/**
	 * Upgrade routine: reinstalls capabilities and cron when the plugin version has changed
	 * (the activation hook does not run on updates).
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'korkwand_version' ) === KORKWAND_VERSION ) {
			return;
		}
		Permissions::install_caps();
		if ( ! wp_next_scheduled( 'korkwand_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'korkwand_daily_cleanup' );
		}
		update_option( 'korkwand_version', KORKWAND_VERSION, false );
	}

	public static function activate(): void {
		Model::register();
		Permissions::install_caps();
		if ( ! wp_next_scheduled( 'korkwand_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'korkwand_daily_cleanup' );
		}
		update_option( 'korkwand_version', KORKWAND_VERSION, false );
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'korkwand_daily_cleanup' );
	}
}
