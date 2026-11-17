<?php
/**
 * Tests fuer den Admin-Bereich.
 */
use NZ\Pinnwand\Admin;
use NZ\Pinnwand\Boards;

class NZP_Test_Admin extends WP_UnitTestCase {

	private int $admin;

	public function set_up() {
		parent::set_up();
		// Hooks werden in Plugin::boot() nur im Admin registriert.
		Admin::register();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function tear_down() {
		$_POST = array();
		parent::tear_down();
	}

	private function board( string $taxonomy = 'pinboard' ): int {
		return self::factory()->term->create( array( 'taxonomy' => $taxonomy, 'name' => 'Brett ' . wp_generate_password( 4, false ) ) );
	}

	private function post_data( array $extra = array() ): void {
		$_POST = $extra + array(
			'nzp_board_nonce' => wp_create_nonce( 'nzp_board_settings' ),
			'lifetime_days'   => '30',
			'height'          => '800',
			'max_pins'        => '20',
			'notify'          => '1',
		);
	}

	public function test_columns_registered() {
		$cols = apply_filters( 'manage_pin_posts_columns', array( 'title' => 'Titel' ) );
		$this->assertArrayHasKey( 'nzp_type', $cols );
		$this->assertArrayHasKey( 'nzp_sticky', $cols );
		$this->assertArrayHasKey( 'nzp_expires', $cols );
	}

	private function column( string $col, int $post_id ): string {
		ob_start();
		do_action( 'manage_pin_posts_custom_column', $col, $post_id );
		return ob_get_clean();
	}

	public function test_column_output() {
		$term = $this->board();
		update_term_meta( $term, 'lifetime_days', 10 );
		$pin = self::factory()->post->create( array( 'post_type' => 'pin' ) );
		wp_set_object_terms( $pin, array( $term ), 'pinboard' );
		update_post_meta( $pin, '_pin_type', 'image' );
		$this->assertSame( 'Bild', $this->column( 'nzp_type', $pin ) );
		$this->assertSame( '–', $this->column( 'nzp_sticky', $pin ) );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), time() + 10 * DAY_IN_SECONDS ), $this->column( 'nzp_expires', $pin ) );

		update_post_meta( $pin, '_pin_type', 'postit' );
		update_post_meta( $pin, '_pin_sticky', '1' );
		$this->assertSame( 'Post-it', $this->column( 'nzp_type', $pin ) );
		$this->assertSame( 'Ja', $this->column( 'nzp_sticky', $pin ) );
		$this->assertSame( 'nie', $this->column( 'nzp_expires', $pin ) );
	}

	public function test_expires_column_without_board() {
		$pin = self::factory()->post->create( array( 'post_type' => 'pin' ) );
		$this->assertSame( '–', $this->column( 'nzp_expires', $pin ) );
	}

	public function test_term_meta_saved_on_edit() {
		wp_set_current_user( $this->admin );
		$id = $this->board();
		$this->post_data();
		do_action( 'edited_pinboard', $id );
		$s = Boards::settings( get_term( $id, 'pinboard' ) );
		$this->assertSame( 30, $s['lifetime_days'] );
		$this->assertSame( 800, $s['height'] );
		$this->assertSame( 20, $s['max_pins'] );
		$this->assertTrue( $s['notify'] );
	}

	public function test_unchecked_notify_saves_false() {
		wp_set_current_user( $this->admin );
		$id = $this->board();
		update_term_meta( $id, 'notify', 1 );
		$this->post_data();
		unset( $_POST['notify'] );
		do_action( 'edited_pinboard', $id );
		$this->assertFalse( Boards::settings( get_term( $id, 'pinboard' ) )['notify'] );
	}

	public function test_term_meta_ignored_without_nonce() {
		wp_set_current_user( $this->admin );
		$id = $this->board();
		$this->post_data();
		unset( $_POST['nzp_board_nonce'] );
		do_action( 'edited_pinboard', $id );
		$this->assertSame( 100, Boards::settings( get_term( $id, 'pinboard' ) )['lifetime_days'] );
	}

	public function test_term_meta_ignored_for_non_admin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$id = $this->board();
		$this->post_data();
		do_action( 'created_pinboard', $id );
		$this->assertSame( 100, Boards::settings( get_term( $id, 'pinboard' ) )['lifetime_days'] );
	}

	public function test_term_meta_ignored_for_wrong_taxonomy() {
		wp_set_current_user( $this->admin );
		$id = $this->board( 'category' );
		$this->post_data();
		Admin::save_term( $id );
		$this->assertSame( '', get_term_meta( $id, 'lifetime_days', true ) );
	}

	public function test_shortcode_column_on_term_list() {
		$cols = apply_filters( 'manage_edit-pinboard_columns', array( 'name' => 'Name' ) );
		$this->assertArrayHasKey( 'nzp_shortcode', $cols );
		$id   = self::factory()->term->create( array( 'taxonomy' => 'pinboard', 'name' => 'Foo', 'slug' => 'foo-<b>' ) );
		$term = get_term( $id, 'pinboard' );
		$out  = apply_filters( 'manage_pinboard_custom_column', '', 'nzp_shortcode', $id );
		$this->assertSame( '<code>' . esc_html( '[pinnwand board="' . $term->slug . '"]' ) . '</code>', $out );
	}

	public function test_form_fields_render_with_nonce() {
		ob_start();
		Admin::add_fields( 'pinboard' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="lifetime_days"', $html );
		$this->assertStringContainsString( 'name="nzp_board_nonce"', $html );
		$id = $this->board();
		ob_start();
		Admin::edit_fields( get_term( $id, 'pinboard' ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="max_pins"', $html );
		$this->assertStringContainsString( 'name="notify"', $html );
	}

	public function test_setting_registered_and_sanitized() {
		global $wp_registered_settings;
		$this->assertNotFalse( has_action( 'admin_init', array( Admin::class, 'register_settings' ) ) );
		Admin::register_settings();
		$this->assertArrayHasKey( 'nzp_delete_data_on_uninstall', $wp_registered_settings );
		$this->assertSame( '1', sanitize_option( 'nzp_delete_data_on_uninstall', '1' ) );
		$this->assertSame( '0', sanitize_option( 'nzp_delete_data_on_uninstall', '0' ) );
		$this->assertSame( '0', sanitize_option( 'nzp_delete_data_on_uninstall', 'x' ) );
	}

	public function test_settings_page_renders_checkbox() {
		wp_set_current_user( $this->admin );
		ob_start();
		Admin::settings_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="nzp_delete_data_on_uninstall"', $html );
		$this->assertStringContainsString( 'Beim Löschen des Plugins alle Zettel und Bilder entfernen', $html );
	}
}
