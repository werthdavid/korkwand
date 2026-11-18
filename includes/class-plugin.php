<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		add_action( 'init', array( Model::class, 'register' ) );
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
		add_action( 'template_redirect', array( Upload::class, 'block_attachment_page' ), 1 );
		Render::register();
		Boards::register();
		Latest::register();
		Cleanup::register();
		Authors::register();
		Permissions::register();
		Privacy::register();
		add_action( 'admin_init', array( Admin::class, 'register_settings' ) );
		if ( is_admin() ) {
			Admin::register();
		}
	}

	public static function activate(): void {
		Model::register();
		Permissions::install_caps();
		if ( ! wp_next_scheduled( 'korkwand_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'korkwand_daily_cleanup' );
		}
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'korkwand_daily_cleanup' );
	}
}
