<?php
/**
 * Tests fuer Aufraeumen, Nutzerloeschung und Deinstallation.
 */
use NZ\Pinnwand\Cleanup;
use NZ\Pinnwand\Pins;
use NZ\Pinnwand\Plugin;

class NZP_Test_Cleanup extends WP_UnitTestCase {

	private \WP_Term $board;
	private int $user;

	public function set_up() {
		parent::set_up();
		\NZ\Pinnwand\Permissions::install_caps();
		$id          = self::factory()->term->create( array( 'taxonomy' => 'pinboard', 'name' => 'Test' ) );
		$this->board = get_term( $id, 'pinboard' );
		$this->user  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	private function pin_aged( int $days_ago, bool $sticky = false, ?int $user = null ): \WP_Post {
		$ts = time() - $days_ago * DAY_IN_SECONDS;
		$id = self::factory()->post->create(
			array(
				'post_type'     => 'pin',
				'post_status'   => 'publish',
				'post_title'    => "Alt $days_ago",
				'post_author'   => $user ?? $this->user,
				'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $ts ),
			)
		);
		wp_set_object_terms( $id, array( $this->board->term_id ), 'pinboard' );
		update_post_meta( $id, '_pin_type', 'postit' );
		update_post_meta( $id, '_pin_sticky', $sticky ? 1 : 0 );
		return get_post( $id );
	}

	private function image_pin( ?int $user = null ): array {
		$user = $user ?? $this->user;
		$att  = self::factory()->attachment->create_object(
			array(
				'file'           => 'nzp-test.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_author'    => $user,
			)
		);
		$id   = self::factory()->post->create(
			array(
				'post_type'   => 'pin',
				'post_status' => 'publish',
				'post_author' => $user,
			)
		);
		wp_set_object_terms( $id, array( $this->board->term_id ), 'pinboard' );
		update_post_meta( $id, '_pin_type', 'image' );
		set_post_thumbnail( $id, $att );
		return array( $id, $att );
	}

	public function test_trash_expired_moves_old_nonsticky_only() {
		update_term_meta( $this->board->term_id, 'lifetime_days', 10 );
		$old    = $this->pin_aged( 30 );
		$sticky = $this->pin_aged( 30, true );
		$fresh  = $this->pin_aged( 2 );

		$this->assertSame( 1, Cleanup::trash_expired() );
		$this->assertSame( 'trash', get_post_status( $old->ID ) );
		$this->assertSame( 'publish', get_post_status( $sticky->ID ) );
		$this->assertSame( 'publish', get_post_status( $fresh->ID ) );
	}

	public function test_trash_expired_skips_lifetime_zero() {
		update_term_meta( $this->board->term_id, 'lifetime_days', 0 );
		$old = $this->pin_aged( 500 );
		$this->assertSame( 0, Cleanup::trash_expired() );
		$this->assertSame( 'publish', get_post_status( $old->ID ) );
	}

	public function test_trash_expired_handles_more_than_one_batch() {
		update_term_meta( $this->board->term_id, 'lifetime_days', 10 );
		for ( $i = 0; $i < 120; $i++ ) {
			$this->pin_aged( 30 );
		}
		$this->assertSame( 120, Cleanup::trash_expired() );
	}

	public function test_force_delete_pin_deletes_image() {
		list( $pin, $att ) = $this->image_pin();
		wp_delete_post( $pin, true );
		$this->assertNull( get_post( $pin ) );
		$this->assertNull( get_post( $att ) );
	}

	public function test_force_delete_pin_keeps_image_used_by_other_pin() {
		list( $pin, $att ) = $this->image_pin();
		$other             = self::factory()->post->create( array( 'post_type' => 'pin', 'post_author' => $this->user ) );
		set_post_thumbnail( $other, $att );
		wp_delete_post( $pin, true );
		$this->assertNotNull( get_post( $att ) );
	}

	public function test_force_delete_pin_keeps_image_attached_to_other_post() {
		list( $pin, $att ) = $this->image_pin();
		$page              = self::factory()->post->create( array( 'post_type' => 'page' ) );
		wp_update_post( array( 'ID' => $att, 'post_parent' => $page ) );
		wp_delete_post( $pin, true );
		$this->assertNotNull( get_post( $att ) );
	}

	public function test_delete_other_post_type_does_not_touch_thumbnail() {
		$att  = self::factory()->attachment->create_object( array( 'file' => 'x.jpg', 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ) );
		$post = self::factory()->post->create();
		set_post_thumbnail( $post, $att );
		wp_delete_post( $post, true );
		$this->assertNotNull( get_post( $att ) );
	}

	public function test_deleting_user_deletes_their_pins_even_with_reassign() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		list( $p1, $a1 ) = $this->image_pin();
		$p2               = $this->pin_aged( 1 );
		$p3               = $this->pin_aged( 1 );
		wp_trash_post( $p3->ID );
		$foreign = $this->pin_aged( 1, false, $admin );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->user, $admin );

		$this->assertNull( get_post( $p1 ) );
		$this->assertNull( get_post( $a1 ) );
		$this->assertNull( get_post( $p2->ID ) );
		$this->assertNull( get_post( $p3->ID ) );
		$this->assertNotNull( get_post( $foreign->ID ) );
	}

	public function test_wpmu_delete_user_hook_registered() {
		$this->assertNotFalse( has_action( 'wpmu_delete_user', array( Cleanup::class, 'delete_user_pins' ) ) );
		$this->assertNotFalse( has_action( 'delete_user', array( Cleanup::class, 'delete_user_pins' ) ) );
		$this->assertNotFalse( has_action( 'before_delete_post', array( Cleanup::class, 'delete_attachment' ) ) );
		$this->assertNotFalse( has_action( 'nzp_daily_cleanup', array( Cleanup::class, 'trash_expired' ) ) );
	}

	public function test_cron_scheduled_on_activate() {
		wp_clear_scheduled_hook( 'nzp_daily_cleanup' );
		$this->assertFalse( wp_next_scheduled( 'nzp_daily_cleanup' ) );
		Plugin::activate();
		$first = wp_next_scheduled( 'nzp_daily_cleanup' );
		$this->assertNotFalse( $first );
		Plugin::activate();
		$this->assertSame( $first, wp_next_scheduled( 'nzp_daily_cleanup' ) );
		$this->assertSame( 'daily', wp_get_schedule( 'nzp_daily_cleanup' ) );
	}

	public function test_cron_cleared_on_deactivate() {
		Plugin::activate();
		Plugin::deactivate();
		$this->assertFalse( wp_next_scheduled( 'nzp_daily_cleanup' ) );
		Plugin::activate();
	}

	public function test_uninstall_removes_everything_by_default() {
		delete_option( 'nzp_delete_data_on_uninstall' );
		update_option( 'nzp_version', NZP_VERSION, false );
		list( $pin, $att ) = $this->image_pin();
		$trashed           = $this->pin_aged( 1 );
		wp_trash_post( $trashed->ID );
		set_transient( 'nzp_rl_5', array( time() ), 3600 );
		$term_id = $this->board->term_id;

		Cleanup::uninstall();

		$this->assertNull( get_post( $pin ) );
		$this->assertNull( get_post( $att ) );
		$this->assertNull( get_post( $trashed->ID ) );
		$this->assertNull( get_term( $term_id, 'pinboard' ) );
		$this->assertFalse( get_transient( 'nzp_rl_5' ) );
		$this->assertFalse( wp_next_scheduled( 'nzp_daily_cleanup' ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'pinnwand_moderate' ) );
		$this->assertFalse( get_option( 'nzp_delete_data_on_uninstall', false ) );
		$this->assertFalse( get_option( 'nzp_version', false ) );
		\NZ\Pinnwand\Permissions::install_caps();
		Plugin::activate();
	}

	public function test_uninstall_keeps_data_when_option_is_zero() {
		update_option( 'nzp_delete_data_on_uninstall', '0' );
		$pin = $this->pin_aged( 1 );

		Cleanup::uninstall();

		$this->assertNotNull( get_post( $pin->ID ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $this->board->term_id, 'pinboard' ) );
		$this->assertFalse( get_option( 'nzp_delete_data_on_uninstall', false ) );
		\NZ\Pinnwand\Permissions::install_caps();
		Plugin::activate();
	}

	public function test_uninstall_php_guards_direct_access() {
		$src = file_get_contents( NZP_DIR . 'uninstall.php' );
		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' )", $src );
		$this->assertStringContainsString( 'Cleanup::uninstall()', $src );
	}
}
