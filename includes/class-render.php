<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

/**
 * Rendering: shortcode [korkwand], block korkwand/board and HTML markup.
 *
 * Script strategy: `korkwand-script` is an ES module (wp_register_script_module). The
 * `Korkwand` configuration is output via a tiny classic handle `korkwand-config`
 * (src=false + wp_add_inline_script) before the module; modules run
 * deferred, so `window.Korkwand` is set when the module starts.
 */
final class Render {

	/**
	 * Boards whose notes already carry `id="korkwand-note-{ID}"` in this request. The same board embedded twice
	 * must not output duplicate ids: only the first rendering gets them (the "latest notes" links target that one).
	 *
	 * @var array<int, true>
	 */
	private static array $noted = array();

	public static function reset_note_ids(): void {
		self::$noted = array();
	}

	public static function register(): void {
		add_shortcode( 'korkwand', array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue_early' ) );
	}

	/** Register the stylesheet early (editor preview via block.json, front-end fallback). */
	public static function register_style(): void {
		if ( ! wp_style_is( 'korkwand-style', 'registered' ) ) {
			wp_register_style( 'korkwand-style', KORKWAND_URL . 'assets/css/korkwand.css', array(), KORKWAND_VERSION );
		}
	}

	/**
	 * Classic themes: load the stylesheet in <head> when the post contains the shortcode or block.
	 * (avoids a flash of unstyled content). The late enqueue at render time remains as a fallback.
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
		wp_set_script_translations( 'korkwand-block-editor', 'korkwand', KORKWAND_DIR . 'languages' );
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
						/* translators: %s: board slug */
						__( 'Board "%s" not found.', 'korkwand' ),
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
			$out .= '<button type="button" class="korkwand-add">' . esc_html__( '+ Add note', 'korkwand' ) . '</button>';
		} else {
			$out .= '<span class="korkwand-login-hint"><a href="' . esc_url( self::login_url() ) . '">'
				. esc_html__( 'Log in to pin a note', 'korkwand' ) . '</a></span>';
		}
		$out .= '</div>';
		$out .= '<div class="korkwand-board"><div class="korkwand-canvas" style="height:' . $height . 'px">';
		$with_ids = ! isset( self::$noted[ $board->term_id ] );
		self::$noted[ $board->term_id ] = true;
		foreach ( Pins::visible( $board ) as $pin ) {
			$out .= self::pin( Pins::to_array( $pin, $viewer, $board ), $viewer > 0, $with_ids );
		}
		$out .= '</div></div></div>';

		return $out;
	}

	/**
	 * @param array<string, mixed> $p Result of Pins::to_array().
	 */
	private static function pin( array $p, bool $logged_in, bool $with_id = true ): string {
		$is_image = 'image' === $p['type'] && ! empty( $p['image'] );
		$classes  = 'korkwand-pin korkwand-pin--' . ( $is_image ? 'image' : 'postit' ) . ( $p['sticky'] ? ' korkwand-pin--sticky' : '' );

		$out = sprintf(
			'<article %sclass="%s" data-id="%d" data-x="%d" data-y="%d" data-can-edit="%s" data-sticky="%s" style="--x:%dpx;--y:%dpx;--r:%ddeg">',
			$with_id ? 'id="korkwand-note-' . (int) $p['id'] . '" ' : '',
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
	 * Login URL that returns to the current page (permalink of the queried object, otherwise the home page).
	 * Deliberately reads neither REQUEST_URI nor HTTP_HOST (subdirectory installs, REST context).
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
				'maxLabel' => Upload::max_label(),
				'i18n'     => self::js_strings(),
			);
			wp_add_inline_script( 'korkwand-config', 'window.Korkwand = ' . wp_json_encode( $config ) . ';' );
			wp_enqueue_script( 'korkwand-config' );
		}

		// Register sub-modules individually with a version and import them by module ID: the import map carries
		// ?ver=..., so browsers do not use stale sub-modules from the cache after updates.
		$deps = array();
		foreach ( array( 'i18n', 'api', 'geometry', 'hash', 'dialog', 'lightbox' ) as $name ) {
			wp_register_script_module( 'korkwand-' . $name, KORKWAND_URL . 'assets/js/' . $name . '.js', array(), KORKWAND_VERSION );
			$deps[] = 'korkwand-' . $name;
		}
		wp_register_script_module( 'korkwand-script', KORKWAND_URL . 'assets/js/korkwand.js', $deps, KORKWAND_VERSION );
		wp_enqueue_script_module( 'korkwand-script' );
	}

	/**
	 * UI strings for the front-end JavaScript. Script modules cannot use wp_set_script_translations(),
	 * so the strings are translated here and passed via window.Korkwand.i18n.
	 *
	 * @return array<string,string>
	 */
	private static function js_strings(): array {
		return array(
			'sessionExpired' => __( 'Session expired – please reload the page.', 'korkwand' ),
			'networkError'   => __( 'Network error – please check your connection.', 'korkwand' ),
			'unknownError'   => __( 'Unknown error.', 'korkwand' ),
			'editNote'       => __( 'Edit note', 'korkwand' ),
			'newNote'        => __( 'New note', 'korkwand' ),
			'noteType'       => __( 'Note type', 'korkwand' ),
			'postit'         => __( 'Post-it', 'korkwand' ),
			'image'          => __( 'Image', 'korkwand' ),
			'titleLabel'     => __( 'Title (required, max. 60 characters)', 'korkwand' ),
			'textLabel'      => __( 'Text (optional, max. 300 characters)', 'korkwand' ),
			/* translators: %s: maximum upload size in MB */
			'imageLabel'     => __( 'Image (JPEG, PNG or WebP, max. %s MB)', 'korkwand' ),
			'captionLabel'   => __( 'Caption (optional, max. 100 characters)', 'korkwand' ),
			'save'           => __( 'Save', 'korkwand' ),
			'pin'            => __( 'Pin', 'korkwand' ),
			'cancel'         => __( 'Cancel', 'korkwand' ),
			'chooseImage'    => __( 'Please choose an image.', 'korkwand' ),
			'imageTypeOnly'  => __( 'Only JPEG, PNG or WebP are allowed.', 'korkwand' ),
			/* translators: %s: maximum upload size in MB */
			'imageTooLarge'  => __( 'The image is too large (maximum %s MB).', 'korkwand' ),
			'enterTitle'     => __( 'Please enter a title.', 'korkwand' ),
			'imageView'      => __( 'Image view', 'korkwand' ),
			'close'          => __( 'Close', 'korkwand' ),
			'openMenu'       => __( 'Open note menu', 'korkwand' ),
			'noteGone'       => __( 'This note no longer exists.', 'korkwand' ),
			'edit'           => __( 'Edit', 'korkwand' ),
			'delete'         => __( 'Delete', 'korkwand' ),
			'sticky'         => __( 'Sticky', 'korkwand' ),
			'removeSticky'   => __( 'Remove sticky', 'korkwand' ),
			'confirmDelete'  => __( 'Really delete this note?', 'korkwand' ),
		);
	}
}
