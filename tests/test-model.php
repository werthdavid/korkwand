<?php
/**
 * Tests fuer Datenmodell und Pinnwand-Einstellungen.
 */
class NZP_Test_Model extends WP_UnitTestCase {

	public function test_cpt_and_taxonomy_registered() {
		$this->assertTrue( post_type_exists( 'pin' ) );
		$this->assertTrue( taxonomy_exists( 'pinboard' ) );
	}

	public function test_settings_defaults() {
		$term = $this->factory()->term->create_and_get( array( 'taxonomy' => 'pinboard' ) );
		$this->assertSame(
			array(
				'lifetime_days' => 100,
				'height'        => 700,
				'max_pins'      => 60,
				'notify'        => false,
			),
			\NZ\Pinnwand\Boards::settings( $term )
		);
	}

	public function test_settings_are_clamped() {
		$term = $this->factory()->term->create_and_get( array( 'taxonomy' => 'pinboard' ) );
		\NZ\Pinnwand\Boards::save_settings(
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
				'lifetime_days' => 0,
				'height'        => 3000,
				'max_pins'      => 1,
				'notify'        => true,
			),
			\NZ\Pinnwand\Boards::settings( $term )
		);
	}

	public function test_get_unknown_board_returns_null() {
		$this->assertNull( \NZ\Pinnwand\Boards::get( 'gibtsnicht' ) );
	}

	public function test_get_known_board_returns_term() {
		$id = $this->factory()->term->create( array( 'taxonomy' => 'pinboard', 'slug' => 'kueche' ) );
		$this->assertSame( $id, \NZ\Pinnwand\Boards::get( 'kueche' )->term_id );
	}

	public function test_pins_cannot_be_created_in_admin_but_can_be_managed() {
		\NZ\Pinnwand\Permissions::install_caps();
		$obj = get_post_type_object( 'pin' );
		$this->assertSame( 'do_not_allow', $obj->cap->create_posts );
		$this->assertSame( array( 'title', 'author', 'thumbnail' ), array_keys( array_filter( get_all_post_type_supports( 'pin' ) ) ) );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$pin    = self::factory()->post->create( array( 'post_type' => 'pin', 'post_status' => 'publish', 'post_author' => $admin ) );
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
}
