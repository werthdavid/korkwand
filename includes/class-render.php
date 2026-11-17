<?php
namespace NZ\Pinnwand;

defined( 'ABSPATH' ) || exit;

/**
 * Darstellung: Shortcode [pinnwand], Block nz/pinnwand und HTML-Markup.
 *
 * Skript-Strategie: `nzp-script` ist ein ES-Modul (wp_register_script_module). Die
 * Konfiguration `NZP` wird ueber ein winziges klassisches Handle `nzp-config`
 * (src=false + wp_add_inline_script) vor dem Modul ausgegeben; Module laufen
 * deferred, `window.NZP` ist also beim Start des Moduls gesetzt.
 */
final class Render {

	public static function register(): void {
		add_shortcode( 'pinnwand', array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue_early' ) );
	}

	/** Stylesheet frueh registrieren (Editor-Vorschau ueber block.json, Frontend-Fallback). */
	public static function register_style(): void {
		if ( ! wp_style_is( 'nzp-style', 'registered' ) ) {
			wp_register_style( 'nzp-style', NZP_URL . 'assets/css/pinnwand.css', array(), NZP_VERSION );
		}
	}

	/**
	 * Klassische Themes: Stylesheet im <head> laden, wenn der Beitrag den Shortcode oder Block enthaelt
	 * (vermeidet ungestylten Blitz). Das spaete Enqueue beim Rendern bleibt als Fallback bestehen.
	 */
	public static function maybe_enqueue_early(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( $post && ( has_shortcode( (string) $post->post_content, 'pinnwand' ) || has_block( 'nz/pinnwand', $post ) ) ) {
			self::register_style();
			wp_enqueue_style( 'nzp-style' );
		}
	}

	public static function register_block(): void {
		self::register_style();
		wp_register_script(
			'nzp-block-editor',
			NZP_URL . 'blocks/pinnwand/edit.js',
			array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			NZP_VERSION,
			true
		);
		register_block_type( NZP_DIR . 'blocks/pinnwand' );
	}

	/**
	 * @param array<string, string>|string $atts Shortcode-Attribute.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'board' => '' ), (array) $atts, 'pinnwand' );
		return self::board( (string) $atts['board'] );
	}

	public static function board( string $slug ): string {
		$slug  = sanitize_title( $slug );
		$board = Boards::get( $slug );
		if ( ! $board ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="nzp-error">' . esc_html(
					sprintf(
						/* translators: %s: Pinnwand-Slug */
						__( 'Pinnwand „%s“ nicht gefunden.', 'nz-pinnwand' ),
						$slug
					)
				) . '</p>';
			}
			return '';
		}

		self::enqueue_assets();

		$viewer   = get_current_user_id();
		$settings = Boards::settings( $board );
		$height   = (int) $settings['height'];
		$can_post = $viewer > 0 && Permissions::can_post( $viewer );
		$id       = wp_unique_id( 'nzp-' );

		$out  = sprintf(
			'<div class="nzp" id="%s" data-board="%s" data-height="%d" data-can-post="%s">',
			esc_attr( $id ),
			esc_attr( $board->slug ),
			$height,
			$can_post ? '1' : '0'
		);
		$out .= '<div class="nzp-toolbar">';
		if ( $can_post ) {
			$out .= '<button type="button" class="nzp-add">' . esc_html__( '+ Zettel', 'nz-pinnwand' ) . '</button>';
		} else {
			$out .= '<span class="nzp-login-hint"><a href="' . esc_url( self::login_url() ) . '">'
				. esc_html__( 'Zum Anheften bitte anmelden', 'nz-pinnwand' ) . '</a></span>';
		}
		$out .= '</div>';
		$out .= '<div class="nzp-board"><div class="nzp-canvas" style="height:' . $height . 'px">';
		foreach ( Pins::visible( $board ) as $pin ) {
			$out .= self::pin( Pins::to_array( $pin, $viewer ), $viewer > 0 );
		}
		$out .= '</div></div></div>';

		return $out;
	}

	/**
	 * @param array<string, mixed> $p Ergebnis von Pins::to_array().
	 */
	private static function pin( array $p, bool $logged_in ): string {
		$is_image = 'image' === $p['type'] && ! empty( $p['image'] );
		$classes  = 'nzp-pin nzp-pin--' . ( $is_image ? 'image' : 'postit' ) . ( $p['sticky'] ? ' nzp-pin--sticky' : '' );

		$out = sprintf(
			'<article class="%s" data-id="%d" data-x="%d" data-y="%d" data-can-edit="%s" data-sticky="%s" style="--x:%dpx;--y:%dpx;--r:%ddeg">',
			esc_attr( $classes ),
			(int) $p['id'],
			(int) $p['x'],
			(int) $p['y'],
			$p['can_edit'] ? '1' : '0',
			$p['sticky'] ? '1' : '0',
			(int) $p['x'],
			(int) $p['y'],
			(int) $p['rotation']
		);

		if ( $is_image ) {
			$img  = $p['image'];
			$out .= '<a class="nzp-lightbox" href="' . esc_url( $img['full'] ) . '"><img src="' . esc_url( $img['thumb'] )
				. '" width="' . (int) $img['width'] . '" height="' . (int) $img['height']
				. '" loading="lazy" alt="' . esc_attr( $p['text'] ) . '"></a>';
			if ( '' !== $p['text'] ) {
				$out .= '<figcaption>' . esc_html( $p['text'] ) . '</figcaption>';
			}
		} else {
			$out .= '<h3>' . esc_html( $p['title'] ) . '</h3>';
			if ( '' !== $p['text'] ) {
				$out .= '<p>' . nl2br( esc_html( $p['text'] ) ) . '</p>';
			}
		}
		if ( $logged_in && isset( $p['author'] ) ) {
			$out .= '<span class="nzp-author">' . esc_html( $p['author'] ) . '</span>';
		}
		return $out . '</article>';
	}

	/**
	 * Login-URL mit Rueckkehr zur aktuellen Seite (Permalink des abgefragten Objekts, sonst Startseite).
	 * Liest bewusst weder REQUEST_URI noch HTTP_HOST (Unterverzeichnis-Installationen, REST-Kontext).
	 */
	public static function login_url(): string {
		$id     = (int) get_queried_object_id();
		$target = $id ? get_permalink( $id ) : false;
		if ( ! is_string( $target ) || '' === $target ) {
			$target = home_url( '/' );
		}
		return wp_login_url( $target );
	}

	private static function enqueue_assets(): void {
		self::register_style();
		wp_enqueue_style( 'nzp-style' );

		if ( ! wp_script_is( 'nzp-config', 'registered' ) ) {
			wp_register_script( 'nzp-config', false, array(), NZP_VERSION, true );
		}
		if ( ! wp_script_is( 'nzp-config', 'enqueued' ) ) {
			$config = array(
				'rest'     => esc_url_raw( rest_url( 'pinnwand/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'loginUrl' => self::login_url(),
				'maxBytes' => Upload::max_bytes(),
			);
			wp_add_inline_script( 'nzp-config', 'window.NZP = ' . wp_json_encode( $config ) . ';' );
			wp_enqueue_script( 'nzp-config' );
		}

		wp_register_script_module( 'nzp-script', NZP_URL . 'assets/js/pinnwand.js', array(), NZP_VERSION );
		wp_enqueue_script_module( 'nzp-script' );
	}
}
