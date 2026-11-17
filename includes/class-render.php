<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

/**
 * Darstellung: Shortcode [pinnwand], Block korkwand/board und HTML-Markup.
 *
 * Skript-Strategie: `korkwand-script` ist ein ES-Modul (wp_register_script_module). Die
 * Konfiguration `Korkwand` wird ueber ein winziges klassisches Handle `korkwand-config`
 * (src=false + wp_add_inline_script) vor dem Modul ausgegeben; Module laufen
 * deferred, `window.Korkwand` ist also beim Start des Moduls gesetzt.
 */
final class Render {

	public static function register(): void {
		add_shortcode( 'korkwand', array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue_early' ) );
	}

	/** Stylesheet frueh registrieren (Editor-Vorschau ueber block.json, Frontend-Fallback). */
	public static function register_style(): void {
		if ( ! wp_style_is( 'korkwand-style', 'registered' ) ) {
			wp_register_style( 'korkwand-style', KORKWAND_URL . 'assets/css/korkwand.css', array(), KORKWAND_VERSION );
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
		if ( $post && ( has_shortcode( (string) $post->post_content, 'korkwand' ) || has_block( 'korkwand/board', $post ) ) ) {
			self::register_style();
			wp_enqueue_style( 'korkwand-style' );
		}
	}

	public static function register_block(): void {
		self::register_style();
		wp_register_script(
			'korkwand-block-editor',
			KORKWAND_URL . 'blocks/board/edit.js',
			array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			KORKWAND_VERSION,
			true
		);
		register_block_type( KORKWAND_DIR . 'blocks/board' );
	}

	/**
	 * @param array<string, string>|string $atts Shortcode-Attribute.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'board' => '' ), (array) $atts, 'korkwand' );
		return self::board( (string) $atts['board'] );
	}

	public static function board( string $slug ): string {
		$slug  = sanitize_title( $slug );
		$board = Boards::get( $slug );
		if ( ! $board ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="korkwand-error">' . esc_html(
					sprintf(
						/* translators: %s: Pinnwand-Slug */
						__( 'Pinnwand „%s“ nicht gefunden.', 'korkwand' ),
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
		$id       = wp_unique_id( 'korkwand-' );

		$out  = sprintf(
			'<div class="korkwand" id="%s" data-board="%s" data-height="%d" data-can-post="%s">',
			esc_attr( $id ),
			esc_attr( $board->slug ),
			$height,
			$can_post ? '1' : '0'
		);
		$out .= '<div class="korkwand-toolbar">';
		if ( $can_post ) {
			$out .= '<button type="button" class="korkwand-add">' . esc_html__( '+ Zettel', 'korkwand' ) . '</button>';
		} else {
			$out .= '<span class="korkwand-login-hint"><a href="' . esc_url( self::login_url() ) . '">'
				. esc_html__( 'Zum Anheften bitte anmelden', 'korkwand' ) . '</a></span>';
		}
		$out .= '</div>';
		$out .= '<div class="korkwand-board"><div class="korkwand-canvas" style="height:' . $height . 'px">';
		foreach ( Pins::visible( $board ) as $pin ) {
			$out .= self::pin( Pins::to_array( $pin, $viewer, $board ), $viewer > 0 );
		}
		$out .= '</div></div></div>';

		return $out;
	}

	/**
	 * @param array<string, mixed> $p Ergebnis von Pins::to_array().
	 */
	private static function pin( array $p, bool $logged_in ): string {
		$is_image = 'image' === $p['type'] && ! empty( $p['image'] );
		$classes  = 'korkwand-pin korkwand-pin--' . ( $is_image ? 'image' : 'postit' ) . ( $p['sticky'] ? ' korkwand-pin--sticky' : '' );

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
			$out .= '<a class="korkwand-lightbox" href="' . esc_url( $img['full'] ) . '"><img src="' . esc_url( $img['thumb'] )
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
			$out .= '<span class="korkwand-author">' . esc_html( $p['author'] ) . '</span>';
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
		wp_enqueue_style( 'korkwand-style' );

		if ( ! wp_script_is( 'korkwand-config', 'registered' ) ) {
			wp_register_script( 'korkwand-config', false, array(), KORKWAND_VERSION, true );
		}
		if ( ! wp_script_is( 'korkwand-config', 'enqueued' ) ) {
			$config = array(
				'rest'     => esc_url_raw( rest_url( 'korkwand/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'loginUrl' => self::login_url(),
				'maxBytes' => Upload::max_bytes(),
			);
			wp_add_inline_script( 'korkwand-config', 'window.Korkwand = ' . wp_json_encode( $config ) . ';' );
			wp_enqueue_script( 'korkwand-config' );
		}

		// Teilmodule einzeln mit Version registrieren und per Modul-ID importieren: Die Import-Map traegt
		// ?ver=..., so dass Browser nach Updates keine veralteten Teilmodule aus dem Cache nutzen.
		$deps = array();
		foreach ( array( 'api', 'geometry', 'dialog', 'lightbox' ) as $name ) {
			wp_register_script_module( 'korkwand-' . $name, KORKWAND_URL . 'assets/js/' . $name . '.js', array(), KORKWAND_VERSION );
			$deps[] = 'korkwand-' . $name;
		}
		wp_register_script_module( 'korkwand-script', KORKWAND_URL . 'assets/js/korkwand.js', $deps, KORKWAND_VERSION );
		wp_enqueue_script_module( 'korkwand-script' );
	}
}
