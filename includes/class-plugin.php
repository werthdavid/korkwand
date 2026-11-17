<?php
namespace NZ\Pinnwand;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		add_action( 'init', array( Model::class, 'register' ) );
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
	}

	public static function activate(): void {
		Model::register();
		Permissions::install_caps();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
	}
}
