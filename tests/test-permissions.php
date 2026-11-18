<?php
/**
 * Tests for permissions.
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

	public function test_board_editor_rights() {
		$sub   = self::factory()->user->create( array( 'role' => 'korkwand_editor' ) );
		$other = self::factory()->user->create( array( 'role' => 'korkwand_editor' ) );
		$this->assertTrue( \Korkwand\Permissions::can_post( $sub ) );
		$this->assertTrue( \Korkwand\Permissions::can_edit( $sub, $this->pin_by( $sub ) ) );
		$this->assertFalse( \Korkwand\Permissions::can_edit( $sub, $this->pin_by( $other ) ) );
		$this->assertFalse( \Korkwand\Permissions::can_edit( 0, $this->pin_by( $other ) ) );
		$this->assertFalse( \Korkwand\Permissions::can_moderate( $sub ) );
	}

	public function test_non_pin_post_is_not_editable() {
		$sub  = self::factory()->user->create( array( 'role' => 'korkwand_editor' ) );
		$post = self::factory()->post->create_and_get( array( 'post_author' => $sub ) );
		$this->assertFalse( \Korkwand\Permissions::can_edit( $sub, $post ) );
	}

	public function test_editor_moderates() {
		$ed  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$sub = self::factory()->user->create( array( 'role' => 'korkwand_editor' ) );
		$this->assertTrue( \Korkwand\Permissions::can_moderate( $ed ) );
		$this->assertTrue( \Korkwand\Permissions::can_edit( $ed, $this->pin_by( $sub ) ) );
		$this->assertTrue( user_can( $ed, 'delete_others_korkwand_pins' ) );
	}

	public function test_default_roles_have_no_post_cap() {
		foreach ( array( 'subscriber', 'contributor', 'author' ) as $name ) {
			$this->assertFalse( get_role( $name )->has_cap( 'korkwand_post' ), $name );
			$this->assertFalse( get_role( $name )->has_cap( 'korkwand_moderate' ), $name );
			$uid = self::factory()->user->create( array( 'role' => $name ) );
			$this->assertFalse( \Korkwand\Permissions::can_post( $uid ), $name );
		}
	}

	public function test_post_cap_only_for_board_editor_editor_administrator() {
		foreach ( array( 'korkwand_editor', 'editor', 'administrator' ) as $name ) {
			$this->assertTrue( get_role( $name )->has_cap( 'korkwand_post' ), $name );
		}
		foreach ( array( 'korkwand_editor' ) as $name ) {
			$this->assertFalse( get_role( $name )->has_cap( 'korkwand_moderate' ), $name );
		}
		foreach ( array( 'editor', 'administrator' ) as $name ) {
			$this->assertTrue( get_role( $name )->has_cap( 'korkwand_moderate' ), $name );
		}
	}

	public function test_board_editor_role_definition() {
		$role = get_role( 'korkwand_editor' );
		$this->assertNotNull( $role );
		$caps = array_keys( array_filter( $role->capabilities ) );
		sort( $caps );
		$this->assertSame( array( 'korkwand_post', 'read' ), $caps );
		$this->assertFalse( $role->has_cap( 'upload_files' ) );
		$this->assertSame( 'Board editor', wp_roles()->role_names['korkwand_editor'] );
	}

	public function test_role_display_name_follows_current_locale() {
		$this->assertNotFalse( has_action( 'init', array( \Korkwand\Permissions::class, 'localize_role_name' ) ) );
		$filter = static fn( $t, $text, $domain ) => ( 'korkwand' === $domain && 'Board editor' === $text ) ? 'Pinnwand-Redaktion' : $t;
		add_filter( 'gettext', $filter, 10, 3 );
		\Korkwand\Permissions::localize_role_name();
		remove_filter( 'gettext', $filter, 10 );
		$this->assertSame( 'Pinnwand-Redaktion', wp_roles()->role_names['korkwand_editor'] );
		\Korkwand\Permissions::localize_role_name();
		$this->assertSame( 'Board editor', wp_roles()->role_names['korkwand_editor'] );
	}

	public function test_remove_caps_removes_role_and_caps_and_demotes_users() {
		$be = self::factory()->user->create( array( 'role' => 'korkwand_editor' ) );
		$multi = self::factory()->user->create( array( 'role' => 'korkwand_editor' ) );
		get_userdata( $multi )->add_role( 'author' );
		\Korkwand\Permissions::remove_caps();
		$this->assertSame( array( 'author' ), get_userdata( $multi )->roles );
		$this->assertNull( get_role( 'korkwand_editor' ) );
		$this->assertContains( 'subscriber', get_userdata( $be )->roles );
		$this->assertNotContains( 'korkwand_editor', get_userdata( $be )->roles );
		$this->assertFalse( \Korkwand\Permissions::can_post( $be ) );
		foreach ( array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' ) as $name ) {
			$this->assertFalse( get_role( $name )->has_cap( 'korkwand_post' ), $name );
			$this->assertFalse( get_role( $name )->has_cap( 'korkwand_moderate' ), $name );
		}
	}
}
