<?php
/**
 * Tests for the data model and board settings.
 */
class Korkwand_Test_Model extends WP_UnitTestCase {

	public function test_cpt_and_taxonomy_registered() {
		$this->assertTrue( post_type_exists( 'korkwand_pin' ) );
		$this->assertTrue( taxonomy_exists( 'korkwand_board' ) );
	}

	public function test_labels_are_english_source_strings() {
		$obj = get_post_type_object( 'korkwand_pin' );
		$this->assertSame( 'Notes', $obj->labels->name );
		$this->assertSame( 'Note', $obj->labels->singular_name );
		$this->assertSame( 'Korkwand', $obj->labels->menu_name );
		$this->assertSame( 'Board', get_taxonomy( 'korkwand_board' )->labels->singular_name );
	}

	public function test_settings_defaults() {
		$term = $this->factory()->term->create_and_get( array( 'taxonomy' => 'korkwand_board' ) );
		$this->assertSame(
			array(
				'lifetime_days' => 100,
				'height'        => 700,
				'max_pins'      => 60,
				'page_id'       => 0,
				'notify'        => false,
			),
			\Korkwand\Boards::settings( $term )
		);
	}

	public function test_settings_are_clamped() {
		$term = $this->factory()->term->create_and_get( array( 'taxonomy' => 'korkwand_board' ) );
		\Korkwand\Boards::save_settings(
			$term->term_id,
			array(
				'lifetime_days' => '-5',
				'height'        => '99999',
				'max_pins'      => '0',
				'notify'        => '1',
			)
		);
		$this->assertSame(
			array(
				'lifetime_days' => 1,
				'height'        => 3000,
				'max_pins'      => 1,
				'page_id'       => 0,
				'notify'        => true,
			),
			\Korkwand\Boards::settings( $term )
		);
	}

	public function test_stored_zero_lifetime_is_clamped_to_one() {
		$term = $this->factory()->term->create_and_get( array( 'taxonomy' => 'korkwand_board' ) );
		update_term_meta( $term->term_id, 'korkwand_lifetime_days', 0 );
		$this->assertSame( 1, \Korkwand\Boards::settings( $term )['lifetime_days'] );
		\Korkwand\Boards::save_settings( $term->term_id, array( 'lifetime_days' => '0' ) );
		$this->assertSame( 1, (int) get_term_meta( $term->term_id, 'korkwand_lifetime_days', true ) );
	}

	public function test_get_unknown_board_returns_null() {
		$this->assertNull( \Korkwand\Boards::get( 'gibtsnicht' ) );
	}

	public function test_get_known_board_returns_term() {
		$id = $this->factory()->term->create( array( 'taxonomy' => 'korkwand_board', 'slug' => 'kueche' ) );
		$this->assertSame( $id, \Korkwand\Boards::get( 'kueche' )->term_id );
	}

	public function test_pins_cannot_be_created_in_admin_but_can_be_managed() {
		\Korkwand\Permissions::install_caps();
		$obj = get_post_type_object( 'korkwand_pin' );
		$this->assertSame( 'do_not_allow', $obj->cap->create_posts );
		$this->assertSame( array( 'title', 'author', 'thumbnail' ), array_keys( array_filter( get_all_post_type_supports( 'korkwand_pin' ) ) ) );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$pin    = self::factory()->post->create( array( 'post_type' => 'korkwand_pin', 'post_status' => 'publish', 'post_author' => $admin ) );
		foreach ( array( $editor, $admin ) as $uid ) {
			wp_set_current_user( $uid );
			$this->assertFalse( current_user_can( $obj->cap->create_posts ), 'Kein Anlegen im Backend' );
			$this->assertTrue( current_user_can( 'edit_post', $pin ) );
			$this->assertTrue( current_user_can( 'delete_post', $pin ) );
			$this->assertTrue( current_user_can( 'read_post', $pin ) );
		}
		wp_set_current_user( $editor );
		$this->assertNotFalse( wp_trash_post( $pin ) );
		$this->assertNotFalse( wp_delete_post( $pin, true ) );
	}

	public function test_board_taxonomy_has_board_labels() {
		$labels = get_taxonomy( 'korkwand_board' )->labels;
		$this->assertSame( 'Edit Board', $labels->edit_item );
		$this->assertSame( 'Add New Board', $labels->add_new_item );
		$this->assertSame( 'Search Boards', $labels->search_items );
		$this->assertSame( 'Board updated.', $labels->item_updated );
	}
}
