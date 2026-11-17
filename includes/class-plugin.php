<?php
namespace NZ\Pinnwand;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		add_action( 'init', array( Model::class, 'register' ) );
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
		Cleanup::register();
		Privacy::register();
	}

	public static function activate(): void {
		Model::register();
		Permissions::install_caps();
		if ( ! wp_next_scheduled( 'nzp_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'nzp_daily_cleanup' );
		}
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'nzp_daily_cleanup' );
	}
}
