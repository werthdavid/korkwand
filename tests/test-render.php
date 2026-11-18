<?php
/**
 * Tests for shortcode, block and markup.
 */
use Korkwand\Pins;
use Korkwand\Render;

class Korkwand_Test_Render extends WP_UnitTestCase {

	private \WP_Term $board;
	private int $user;

	public function set_up() {
		parent::set_up();
		\Korkwand\Permissions::install_caps();
		$id          = self::factory()->term->create( array( 'taxonomy' => 'korkwand_board', 'name' => 'Test', 'slug' => 'test' ) );
		$this->board = get_term( $id, 'korkwand_board' );
		$this->user  = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Anna Beispiel' ) );
		$pin = Pins::create( $this->board, $this->user, array( 'type' => 'postit', 'title' => 'Hallo', 'text' => 'Text' ) );
		// Stored form as after sanitize_*_field (HTML entities); output must escape.
		wp_update_post( array( 'ID' => $pin->ID, 'post_title' => 'Hallo &lt;i&gt;', 'post_content' => "&lt;b&gt;x&lt;/b&gt;\nY" ) );
		wp_set_current_user( 0 );
		unset( $GLOBALS['wp_styles'], $GLOBALS['wp_scripts'] );
	}

	public function test_shortcode_renders_pins_and_escapes() {
		$html = do_shortcode( '[korkwand board="test"]' );
		$this->assertStringContainsString( 'class="korkwand"', $html );
		$this->assertStringContainsString( 'data-board="test"', $html );
		$this->assertStringContainsString( 'data-height="700"', $html );
		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;<br />', $html );
		$this->assertStringContainsString( 'Hallo &lt;i&gt;', $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
		$this->assertStringContainsString( 'korkwand-pin--postit', $html );
		$this->assertMatchesRegularExpression( '/data-can-edit="0"/', $html );
		$this->assertMatchesRegularExpression( '/--x:\d+px;--y:\d+px;--r:-?\d+deg/', $html );
	}

	public function test_guest_sees_login_hint_no_author() {
		$html = Render::board( 'test' );
		$this->assertStringContainsString( 'data-can-post="0"', $html );
		$this->assertStringContainsString( 'wp-login.php', $html );
		$this->assertStringNotContainsString( 'Anna Beispiel', $html );
		$this->assertStringNotContainsString( 'korkwand-author', $html );
		$this->assertStringNotContainsString( 'korkwand-add', $html );
	}

	public function test_subscriber_sees_add_button_and_author() {
		wp_set_current_user( $this->user );
		$html = Render::board( 'test' );
		$this->assertStringContainsString( 'data-can-post="1"', $html );
		$this->assertStringContainsString( 'korkwand-add', $html );
		$this->assertStringContainsString( 'Anna Beispiel', $html );
		$this->assertStringContainsString( 'data-can-edit="1"', $html );
	}

	public function test_unknown_board_empty_for_guest_hint_for_admin() {
		$this->assertSame( '', Render::board( 'gibtsnicht' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringContainsString( 'korkwand-error', Render::board( 'gibtsnicht' ) );
	}

	public function test_multiple_boards_get_unique_ids() {
		$a = Render::board( 'test' );
		$b = Render::board( 'test' );
		preg_match( '/id="(korkwand-\d+)"/', $a, $ma );
		preg_match( '/id="(korkwand-\d+)"/', $b, $mb );
		$this->assertNotEmpty( $ma[1] );
		$this->assertNotSame( $ma[1], $mb[1] );
	}

	public function test_assets_enqueued_only_when_rendered() {
		$this->assertFalse( wp_style_is( 'korkwand-style', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'korkwand-config', 'enqueued' ) );
		Render::board( 'test' );
		$this->assertTrue( wp_style_is( 'korkwand-style', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'korkwand-config', 'enqueued' ) );
		$data = wp_scripts()->get_data( 'korkwand-config', 'after' );
		$this->assertStringContainsString( 'korkwand\/v1\/', implode( '', (array) $data ) );
		$this->assertStringContainsString( 'loginUrl', implode( '', (array) $data ) );
		$this->assertStringContainsString( '"maxBytes":' . \Korkwand\Upload::max_bytes(), implode( '', (array) $data ) );
		ob_start();
		wp_script_modules()->print_enqueued_script_modules();
		$this->assertStringContainsString( 'korkwand-script', ob_get_clean() );
	}

	public function test_login_hint_is_one_linked_sentence() {
		$html = Render::board( 'test' );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*wp-login\.php[^"]*">Log in to pin a note</a>#', $html );
		$this->assertStringNotContainsString( 'Anmelden</a>', $html );
	}

	public function test_style_registered_on_init_and_block_uses_it() {
		Render::register_style();
		$this->assertTrue( wp_style_is( 'korkwand-style', 'registered' ) );
		$this->assertSame( array( 'korkwand-style' ), WP_Block_Type_Registry::get_instance()->get_registered( 'korkwand/board' )->style_handles );
		$json = json_decode( file_get_contents( KORKWAND_DIR . 'blocks/board/block.json' ), true );
		$this->assertSame( 'korkwand-style', $json['style'] );
	}

	public function test_style_enqueued_early_on_singular_with_shortcode() {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => 'Hallo [korkwand board="test"]' ) );
		$this->go_to( get_permalink( $page ) );
		$this->assertFalse( wp_style_is( 'korkwand-style', 'enqueued' ) );
		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_style_is( 'korkwand-style', 'enqueued' ) );
	}

	public function test_style_not_enqueued_early_without_shortcode() {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => 'Kein Board hier' ) );
		$this->go_to( get_permalink( $page ) );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_style_is( 'korkwand-style', 'enqueued' ) );
	}

	public function test_sub_modules_are_versioned_via_import_map() {
		Render::board( 'test' );
		ob_start();
		wp_script_modules()->print_import_map();
		$map = ob_get_clean();
		foreach ( array( 'i18n', 'api', 'geometry', 'dialog', 'lightbox' ) as $name ) {
			$this->assertMatchesRegularExpression( '#"korkwand-' . $name . '":\s*"[^"]*assets\\/js\\/' . $name . '\.js\?ver=' . preg_quote( KORKWAND_VERSION, '#' ) . '"#', $map, $name );
		}
		$src = file_get_contents( KORKWAND_DIR . 'assets/js/korkwand.js' );
		$this->assertStringNotContainsString( "from './", $src );
		$this->assertStringContainsString( "from 'korkwand-api'", $src );
	}

	public function test_js_strings_are_passed_in_config_as_english_source_strings() {
		Render::board( 'test' );
		$after = implode( '', (array) wp_scripts()->get_data( 'korkwand-config', 'after' ) );
		$this->assertStringContainsString( '"i18n":{', $after );
		$this->assertStringContainsString( '"confirmDelete":"Really delete this note?"', $after );
		$this->assertStringContainsString( '"maxLabel":"' . \Korkwand\Upload::max_label() . '"', $after );
	}

	public function test_block_is_registered_and_renders() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'korkwand/board' ) );
		$html = do_blocks( '<!-- wp:korkwand/board {"board":"test"} /-->' );
		$this->assertStringContainsString( 'data-board="test"', $html );
	}

	public function test_block_output_has_wrapper_and_alignment_class() {
		$html = do_blocks( '<!-- wp:korkwand/board {"board":"test","align":"wide"} /-->' );
		$this->assertStringContainsString( 'wp-block-korkwand-board', $html );
		$this->assertStringContainsString( 'alignwide', $html );
		$this->assertStringContainsString( 'data-board="test"', $html );
	}

	public function test_image_pin_markup() {
		$att = self::factory()->attachment->create_object( array( 'file' => 'a.jpg', 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ) );
		Pins::create( $this->board, $this->user, array( 'type' => 'image', 'text' => 'Unterschrift', 'attachment_id' => $att ) );
		$html = Render::board( 'test' );
		$this->assertStringContainsString( 'korkwand-pin--image', $html );
		$this->assertStringContainsString( 'class="korkwand-lightbox"', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringContainsString( 'alt="Unterschrift"', $html );
		$this->assertStringContainsString( '<figcaption>Unterschrift</figcaption>', $html );
	}

	private function redirect_to( string $url ): string {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		return (string) ( $q['redirect_to'] ?? '' );
	}

	public function test_login_url_uses_queried_permalink() {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $page ) );
		$this->assertSame( get_permalink( $page ), $this->redirect_to( Render::login_url() ) );
		$this->assertStringContainsString( rawurlencode( get_permalink( $page ) ), Render::board( 'test' ) );
	}

	public function test_login_url_no_doubled_subdirectory() {
		update_option( 'home', 'http://example.org/wordpress' );
		update_option( 'siteurl', 'http://example.org/wordpress' );
		$_SERVER['REQUEST_URI'] = '/wordpress/foo/';
		$this->assertStringNotContainsString( '/wordpress/wordpress', Render::login_url() );
		$this->assertStringNotContainsString( '/wordpress/wordpress', rawurldecode( Render::board( 'test' ) ) );
	}

	public function test_login_url_falls_back_to_home() {
		$this->go_to( '/' );
		$this->assertSame( 0, (int) get_queried_object_id() );
		$this->assertSame( home_url( '/' ), $this->redirect_to( Render::login_url() ) );
	}

	public function test_pins_carry_note_ids() {
		\Korkwand\Render::reset_note_ids();
		$pin = get_posts( array( 'post_type' => 'korkwand_pin', 'numberposts' => 1 ) )[0];
		$html = Render::board( 'test' );
		$this->assertStringContainsString( 'id="korkwand-note-' . $pin->ID . '"', $html );
	}

	public function test_note_ids_only_on_first_render_of_same_board() {
		\Korkwand\Render::reset_note_ids();
		$pin    = get_posts( array( 'post_type' => 'korkwand_pin', 'numberposts' => 1 ) )[0];
		$first  = Render::board( 'test' );
		$second = Render::board( 'test' );
		$this->assertSame( 1, substr_count( $first . $second, 'id="korkwand-note-' . $pin->ID . '"' ) );
		$this->assertStringContainsString( 'korkwand-pin--postit', $second );
	}
}
