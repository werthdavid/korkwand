<?php
/**
 * Tests for cleanup, user deletion and uninstall.
 */
use Korkwand\Cleanup;
use Korkwand\Pins;
use Korkwand\Plugin;

class Korkwand_Test_Cleanup extends WP_UnitTestCase {

	private \WP_Term $board;
	private int $user;

	public function set_up() {
		parent::set_up();
		\Korkwand\Permissions::install_caps();
		$id          = self::factory()->term->create( array( 'taxonomy' => 'korkwand_board', 'name' => 'Test' ) );
		$this->board = get_term( $id, 'korkwand_board' );
		$this->user  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	private function pin_aged( int $days_ago, bool $sticky = false, ?int $user = null ): \WP_Post {
		$ts = time() - $days_ago * DAY_IN_SECONDS;
		$id = self::factory()->post->create(
			array(
				'post_type'     => 'korkwand_pin',
				'post_status'   => 'publish',
				'post_title'    => "Alt $days_ago",
				'post_author'   => $user ?? $this->user,
				'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $ts ),
			)
		);
		wp_set_object_terms( $id, array( $this->board->term_id ), 'korkwand_board' );
		update_post_meta( $id, '_korkwand_type', 'postit' );
		update_post_meta( $id, '_korkwand_sticky', $sticky ? 1 : 0 );
		return get_post( $id );
	}

	/** $plugin_upload = attachment comes from the plugin upload (meta _korkwand_upload), otherwise a media library image. */
	private function image_pin( ?int $user = null, bool $plugin_upload = true ): array {
		$user = $user ?? $this->user;
		$att  = self::factory()->attachment->create_object(
			array(
				'file'           => 'korkwand-test.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_author'    => $user,
			)
		);
		$id   = self::factory()->post->create(
			array(
				'post_type'   => 'korkwand_pin',
				'post_status' => 'publish',
				'post_author' => $user,
			)
		);
		wp_set_object_terms( $id, array( $this->board->term_id ), 'korkwand_board' );
		update_post_meta( $id, '_korkwand_type', 'image' );
		if ( $plugin_upload ) {
			update_post_meta( $att, '_korkwand_upload', 1 );
		}
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

	public function test_force_delete_pin_keeps_media_library_image_without_upload_flag() {
		list( $pin, $att ) = $this->image_pin( null, false );
		wp_delete_post( $pin, true );
		$this->assertNull( get_post( $pin ) );
		$this->assertNotNull( get_post( $att ), 'Mediathek-Bild ohne _korkwand_upload darf nicht geloescht werden' );
	}

	public function test_force_delete_pin_keeps_image_shared_with_trashed_pin() {
		list( $pin, $att ) = $this->image_pin();
		$other             = self::factory()->post->create( array( 'post_type' => 'korkwand_pin', 'post_author' => $this->user ) );
		set_post_thumbnail( $other, $att );
		wp_trash_post( $other );
		wp_delete_post( $pin, true );
		$this->assertNotNull( get_post( $att ) );
	}

	public function test_force_delete_pin_keeps_image_used_as_thumbnail_of_a_post() {
		list( $pin, $att ) = $this->image_pin();
		$post              = self::factory()->post->create( array( 'post_type' => 'post' ) );
		set_post_thumbnail( $post, $att );
		wp_trash_post( $post );
		wp_delete_post( $pin, true );
		$this->assertNotNull( get_post( $att ) );
	}

	public function test_force_delete_pin_keeps_image_used_by_other_pin() {
		list( $pin, $att ) = $this->image_pin();
		$other             = self::factory()->post->create( array( 'post_type' => 'korkwand_pin', 'post_author' => $this->user ) );
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

	public function test_delete_batch_skips_undeletable_first_pin_and_deletes_rest() {
		$ids     = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = $this->pin_aged( 1 )->ID;
		}
		$blocked = $ids[0];
		$block   = static fn( $check, $post ) => $post->ID === $blocked ? false : $check;
		add_filter( 'pre_delete_post', $block, 10, 2 );
		$n = Cleanup::delete_batch( $this->user, 0 );
		remove_filter( 'pre_delete_post', $block, 10 );

		$this->assertSame( 2, $n );
		$this->assertNotNull( get_post( $blocked ) );
		$this->assertNull( get_post( $ids[1] ) );
		$this->assertNull( get_post( $ids[2] ) );
	}

	public function test_delete_batch_with_limit_does_not_stop_at_undeletable_first_pin() {
		$first   = $this->pin_aged( 1 )->ID;
		$second  = $this->pin_aged( 1 )->ID;
		$block   = static fn( $check, $post ) => $post->ID === $first ? false : $check;
		add_filter( 'pre_delete_post', $block, 10, 2 );
		$n = Cleanup::delete_batch( $this->user, 1 );
		remove_filter( 'pre_delete_post', $block, 10 );
		$this->assertSame( 1, $n );
		$this->assertNull( get_post( $second ) );
	}

	public function test_uninstall_deletes_pins_behind_an_undeletable_one() {
		delete_option( 'korkwand_delete_data_on_uninstall' );
		$ids     = array( $this->pin_aged( 1 )->ID, $this->pin_aged( 1 )->ID );
		$blocked = $ids[0];
		$block   = static fn( $check, $post ) => $post->ID === $blocked ? false : $check;
		add_filter( 'pre_delete_post', $block, 10, 2 );
		Cleanup::uninstall();
		remove_filter( 'pre_delete_post', $block, 10 );
		$this->assertNull( get_post( $ids[1] ) );
		\Korkwand\Permissions::install_caps();
		Plugin::activate();
		wp_delete_post( $blocked, true );
	}

	public function test_wpmu_delete_user_hook_registered() {
		$this->assertNotFalse( has_action( 'wpmu_delete_user', array( Cleanup::class, 'delete_user_pins' ) ) );
		$this->assertNotFalse( has_action( 'delete_user', array( Cleanup::class, 'delete_user_pins' ) ) );
		$this->assertNotFalse( has_action( 'before_delete_post', array( Cleanup::class, 'delete_attachment' ) ) );
		$this->assertNotFalse( has_action( 'korkwand_daily_cleanup', array( Cleanup::class, 'trash_expired' ) ) );
	}

	public function test_cron_scheduled_on_activate() {
		wp_clear_scheduled_hook( 'korkwand_daily_cleanup' );
		$this->assertFalse( wp_next_scheduled( 'korkwand_daily_cleanup' ) );
		Plugin::activate();
		$first = wp_next_scheduled( 'korkwand_daily_cleanup' );
		$this->assertNotFalse( $first );
		Plugin::activate();
		$this->assertSame( $first, wp_next_scheduled( 'korkwand_daily_cleanup' ) );
		$this->assertSame( 'daily', wp_get_schedule( 'korkwand_daily_cleanup' ) );
	}

	public function test_cron_cleared_on_deactivate() {
		Plugin::activate();
		Plugin::deactivate();
		$this->assertFalse( wp_next_scheduled( 'korkwand_daily_cleanup' ) );
		Plugin::activate();
	}

	public function test_uninstall_removes_everything_by_default() {
		delete_option( 'korkwand_delete_data_on_uninstall' );
		update_option( 'korkwand_version', KORKWAND_VERSION, false );
		list( $pin, $att ) = $this->image_pin();
		$trashed           = $this->pin_aged( 1 );
		wp_trash_post( $trashed->ID );
		set_transient( 'korkwand_rl_5', array( time() ), 3600 );
		$term_id = $this->board->term_id;

		Cleanup::uninstall();

		$this->assertNull( get_post( $pin ) );
		$this->assertNull( get_post( $att ) );
		$this->assertNull( get_post( $trashed->ID ) );
		$this->assertNull( get_term( $term_id, 'korkwand_board' ) );
		$this->assertFalse( get_transient( 'korkwand_rl_5' ) );
		$this->assertFalse( wp_next_scheduled( 'korkwand_daily_cleanup' ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'korkwand_moderate' ) );
		$this->assertFalse( get_option( 'korkwand_delete_data_on_uninstall', false ) );
		$this->assertFalse( get_option( 'korkwand_version', false ) );
		\Korkwand\Permissions::install_caps();
		Plugin::activate();
	}

	public function test_uninstall_keeps_data_when_option_is_zero() {
		update_option( 'korkwand_delete_data_on_uninstall', '0' );
		$pin = $this->pin_aged( 1 );

		Cleanup::uninstall();

		$this->assertNotNull( get_post( $pin->ID ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $this->board->term_id, 'korkwand_board' ) );
		$this->assertFalse( get_option( 'korkwand_delete_data_on_uninstall', false ) );
		\Korkwand\Permissions::install_caps();
		Plugin::activate();
	}

	public function test_uninstall_php_guards_direct_access() {
		$src = file_get_contents( KORKWAND_DIR . 'uninstall.php' );
		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' )", $src );
		$this->assertStringContainsString( 'Cleanup::uninstall()', $src );
	}
}
