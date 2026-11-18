<?php
/**
 * Tests for the admin area.
 */
use Korkwand\Admin;
use Korkwand\Boards;

class Korkwand_Test_Admin extends WP_UnitTestCase {

	private int $admin;

	public function set_up() {
		parent::set_up();
		// Hooks are registered only in the admin in Plugin::boot().
		Admin::register();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function tear_down() {
		$_POST = array();
		parent::tear_down();
	}

	private function board( string $taxonomy = 'korkwand_board' ): int {
		return self::factory()->term->create( array( 'taxonomy' => $taxonomy, 'name' => 'Brett ' . wp_generate_password( 4, false ) ) );
	}

	private function post_data( array $extra = array() ): void {
		$_POST = $extra + array(
			'korkwand_board_nonce' => wp_create_nonce( 'korkwand_board_settings' ),
			'lifetime_days'   => '30',
			'height'          => '800',
			'max_pins'        => '20',
			'notify'          => '1',
		);
	}

	public function test_columns_registered() {
		$cols = apply_filters( 'manage_korkwand_pin_posts_columns', array( 'title' => 'Title' ) );
		$this->assertArrayHasKey( 'korkwand_type', $cols );
		$this->assertArrayHasKey( 'korkwand_sticky', $cols );
		$this->assertArrayHasKey( 'korkwand_expires', $cols );
	}

	private function column( string $col, int $post_id ): string {
		ob_start();
		do_action( 'manage_korkwand_pin_posts_custom_column', $col, $post_id );
		return ob_get_clean();
	}

	public function test_column_output() {
		$term = $this->board();
		update_term_meta( $term, 'lifetime_days', 10 );
		$pin = self::factory()->post->create( array( 'post_type' => 'korkwand_pin' ) );
		wp_set_object_terms( $pin, array( $term ), 'korkwand_board' );
		update_post_meta( $pin, '_korkwand_type', 'image' );
		$this->assertSame( 'Image', $this->column( 'korkwand_type', $pin ) );
		$this->assertSame( '–', $this->column( 'korkwand_sticky', $pin ) );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), time() + 10 * DAY_IN_SECONDS ), $this->column( 'korkwand_expires', $pin ) );

		update_post_meta( $pin, '_korkwand_type', 'postit' );
		update_post_meta( $pin, '_korkwand_sticky', '1' );
		$this->assertSame( 'Post-it', $this->column( 'korkwand_type', $pin ) );
		$this->assertSame( 'Yes', $this->column( 'korkwand_sticky', $pin ) );
		$this->assertSame( 'never', $this->column( 'korkwand_expires', $pin ) );
	}

	public function test_expires_column_without_board() {
		$pin = self::factory()->post->create( array( 'post_type' => 'korkwand_pin' ) );
		$this->assertSame( '–', $this->column( 'korkwand_expires', $pin ) );
	}

	public function test_term_meta_saved_on_edit() {
		wp_set_current_user( $this->admin );
		$id = $this->board();
		$this->post_data();
		do_action( 'edited_korkwand_board', $id );
		$s = Boards::settings( get_term( $id, 'korkwand_board' ) );
		$this->assertSame( 30, $s['lifetime_days'] );
		$this->assertSame( 800, $s['height'] );
		$this->assertSame( 20, $s['max_pins'] );
		$this->assertTrue( $s['notify'] );
	}

	public function test_page_id_saved_on_edit() {
		wp_set_current_user( $this->admin );
		$id   = $this->board();
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->post_data( array( 'page_id' => (string) $page ) );
		do_action( 'edited_korkwand_board', $id );
		$this->assertSame( $page, Boards::settings( get_term( $id, 'korkwand_board' ) )['page_id'] );
	}

	public function test_page_id_ignored_without_nonce() {
		wp_set_current_user( $this->admin );
		$id = $this->board();
		$this->post_data( array( 'page_id' => '7' ) );
		unset( $_POST['korkwand_board_nonce'] );
		do_action( 'edited_korkwand_board', $id );
		$this->assertSame( 0, Boards::settings( get_term( $id, 'korkwand_board' ) )['page_id'] );
	}

	public function test_board_page_dropdown_rendered_in_forms() {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Pinnwandseite' ) );
		ob_start();
		Admin::add_fields( 'korkwand_board' );
		$add = ob_get_clean();
		$this->assertStringContainsString( "name='page_id'", $add );
		$this->assertStringContainsString( '<option value="0">— automatic —</option>', $add );
		$this->assertStringContainsString( 'Pinnwandseite', $add );

		$id = $this->board();
		update_term_meta( $id, 'page_id', $page );
		ob_start();
		Admin::edit_fields( get_term( $id, 'korkwand_board' ) );
		$edit = ob_get_clean();
		$this->assertMatchesRegularExpression( '/<option[^>]*value="' . $page . '"[^>]*selected/', $edit );
	}

	public function test_unchecked_notify_saves_false() {
		wp_set_current_user( $this->admin );
		$id = $this->board();
		update_term_meta( $id, 'notify', 1 );
		$this->post_data();
		unset( $_POST['notify'] );
		do_action( 'edited_korkwand_board', $id );
		$this->assertFalse( Boards::settings( get_term( $id, 'korkwand_board' ) )['notify'] );
	}

	public function test_term_meta_ignored_without_nonce() {
		wp_set_current_user( $this->admin );
		$id = $this->board();
		$this->post_data();
		unset( $_POST['korkwand_board_nonce'] );
		do_action( 'edited_korkwand_board', $id );
		$this->assertSame( 100, Boards::settings( get_term( $id, 'korkwand_board' ) )['lifetime_days'] );
	}

	public function test_term_meta_ignored_for_non_admin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$id = $this->board();
		$this->post_data();
		do_action( 'created_korkwand_board', $id );
		$this->assertSame( 100, Boards::settings( get_term( $id, 'korkwand_board' ) )['lifetime_days'] );
	}

	public function test_term_meta_ignored_for_wrong_taxonomy() {
		wp_set_current_user( $this->admin );
		$id = $this->board( 'category' );
		$this->post_data();
		Admin::save_term( $id );
		$this->assertSame( '', get_term_meta( $id, 'lifetime_days', true ) );
	}

	public function test_shortcode_column_on_term_list() {
		$cols = apply_filters( 'manage_edit-korkwand_board_columns', array( 'name' => 'Name' ) );
		$this->assertArrayHasKey( 'korkwand_shortcode', $cols );
		$id   = self::factory()->term->create( array( 'taxonomy' => 'korkwand_board', 'name' => 'Foo', 'slug' => 'foo-<b>' ) );
		$term = get_term( $id, 'korkwand_board' );
		$out  = apply_filters( 'manage_korkwand_board_custom_column', '', 'korkwand_shortcode', $id );
		$this->assertSame( '<code>' . esc_html( '[korkwand board="' . $term->slug . '"]' ) . '</code>', $out );
	}

	public function test_form_fields_render_with_nonce() {
		ob_start();
		Admin::add_fields( 'korkwand_board' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="lifetime_days"', $html );
		$this->assertStringContainsString( 'name="korkwand_board_nonce"', $html );
		$id = $this->board();
		ob_start();
		Admin::edit_fields( get_term( $id, 'korkwand_board' ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="max_pins"', $html );
		$this->assertStringContainsString( 'name="notify"', $html );
	}

	public function test_setting_registered_and_sanitized() {
		global $wp_registered_settings;
		$this->assertNotFalse( has_action( 'admin_init', array( Admin::class, 'register_settings' ) ) );
		Admin::register_settings();
		$this->assertArrayHasKey( 'korkwand_delete_data_on_uninstall', $wp_registered_settings );
		$this->assertSame( '1', sanitize_option( 'korkwand_delete_data_on_uninstall', '1' ) );
		$this->assertSame( '0', sanitize_option( 'korkwand_delete_data_on_uninstall', '0' ) );
		$this->assertSame( '0', sanitize_option( 'korkwand_delete_data_on_uninstall', 'x' ) );
	}

	public function test_settings_page_renders_checkbox() {
		wp_set_current_user( $this->admin );
		ob_start();
		Admin::settings_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="korkwand_delete_data_on_uninstall"', $html );
		$this->assertStringContainsString( 'Remove all notes and images when the plugin is deleted', $html );
	}
}
