<?php
/**
 * Tests fuer Rechte.
 */
class Korkwand_Test_Permissions extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		\Korkwand\Permissions::install_caps();
	}

	public function tear_down() {
		\Korkwand\Permissions::remove_caps();
		\Korkwand\Permissions::install_caps();
		parent::tear_down();
	}

	private function pin_by( int $uid ): WP_Post {
		return get_post(
			self::factory()->post->create(
				array(
					'post_type'   => 'korkwand_pin',
					'post_author' => $uid,
				)
			)
		);
	}

	public function test_guest_cannot_post() {
		$this->assertFalse( \Korkwand\Permissions::can_post( 0 ) );
		$this->assertFalse( \Korkwand\Permissions::can_post( 999999 ) );
	}

	public function test_subscriber_rights() {
		$sub   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertTrue( \Korkwand\Permissions::can_post( $sub ) );
		$this->assertTrue( \Korkwand\Permissions::can_edit( $sub, $this->pin_by( $sub ) ) );
		$this->assertFalse( \Korkwand\Permissions::can_edit( $sub, $this->pin_by( $other ) ) );
		$this->assertFalse( \Korkwand\Permissions::can_edit( 0, $this->pin_by( $other ) ) );
		$this->assertFalse( \Korkwand\Permissions::can_moderate( $sub ) );
	}

	public function test_non_pin_post_is_not_editable() {
		$sub  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post = self::factory()->post->create_and_get( array( 'post_author' => $sub ) );
		$this->assertFalse( \Korkwand\Permissions::can_edit( $sub, $post ) );
	}

	public function test_editor_moderates() {
		$ed  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertTrue( \Korkwand\Permissions::can_moderate( $ed ) );
		$this->assertTrue( \Korkwand\Permissions::can_edit( $ed, $this->pin_by( $sub ) ) );
		$this->assertTrue( user_can( $ed, 'delete_others_korkwand_pins' ) );
	}

	public function test_remove_caps() {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\Korkwand\Permissions::remove_caps();
		$this->assertFalse( \Korkwand\Permissions::can_post( $sub ) );
	}
}
