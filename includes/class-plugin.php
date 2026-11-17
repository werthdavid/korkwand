<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		// Laeuft auf plugins_loaded (boot-Hook): Rechte/Cron nach Updates nachziehen.
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
	 * Upgrade-Routine: installiert Rechte und Cron neu, wenn sich die Plugin-Version geaendert hat
	 * (Aktivierungs-Hook laeuft bei Updates nicht).
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
