<?php
/**
 * Tests fuer den Pins-Service.
 */
use NZ\Pinnwand\Pins;

class NZP_Test_Pins extends WP_UnitTestCase {

	private \WP_Term $board;
	private int $user;

	public function set_up() {
		parent::set_up();
		\NZ\Pinnwand\Permissions::install_caps();
		$id          = self::factory()->term->create( array( 'taxonomy' => 'pinboard', 'name' => 'Test' ) );
		$this->board = get_term( $id, 'pinboard' );
		$this->user  = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Anna Beispiel' ) );
	}

	private function settings( array $meta ): void {
		foreach ( $meta as $k => $v ) {
			update_term_meta( $this->board->term_id, $k, $v );
		}
	}

	private function postit( array $extra = array() ) {
		return Pins::create( $this->board, $this->user, $extra + array( 'type' => 'postit', 'title' => 'Titel', 'text' => 'Text' ) );
	}

	private function old_pin( int $days_ago, bool $sticky = false ): \WP_Post {
		$ts  = time() - $days_ago * DAY_IN_SECONDS;
		$id  = self::factory()->post->create(
			array(
				'post_type'     => 'pin',
				'post_status'   => 'publish',
				'post_title'    => "Alt $days_ago",
				'post_author'   => $this->user,
				'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $ts ),
			)
		);
		wp_set_object_terms( $id, array( $this->board->term_id ), 'pinboard' );
		update_post_meta( $id, '_pin_type', 'postit' );
		update_post_meta( $id, '_pin_sticky', $sticky ? 1 : 0 );
		return get_post( $id );
	}

	public function test_create_postit_defaults() {
		$pin = $this->postit();
		$this->assertInstanceOf( WP_Post::class, $pin );
		$this->assertSame( 'pin', $pin->post_type );
		$this->assertSame( 'publish', $pin->post_status );
		$this->assertSame( 'postit', get_post_meta( $pin->ID, '_pin_type', true ) );
		$rot = (int) get_post_meta( $pin->ID, '_pin_rotation', true );
		$this->assertGreaterThanOrEqual( -6, $rot );
		$this->assertLessThanOrEqual( 6, $rot );
		$x = (int) get_post_meta( $pin->ID, '_pin_x', true );
		$y = (int) get_post_meta( $pin->ID, '_pin_y', true );
		$this->assertTrue( $x >= 0 && $x <= 800 );
		$this->assertTrue( $y >= 0 && $y <= 500 );
		$this->assertSame( array( $this->board->term_id ), wp_get_object_terms( $pin->ID, 'pinboard', array( 'fields' => 'ids' ) ) );
		$this->assertSame( $this->user, (int) $pin->post_author );
	}

	public function test_create_clamps_given_position() {
		$pin = $this->postit( array( 'x' => 5000, 'y' => 5000 ) );
		$this->assertSame( 800, (int) get_post_meta( $pin->ID, '_pin_x', true ) );
		$this->assertSame( 600, (int) get_post_meta( $pin->ID, '_pin_y', true ) );
	}

	public function test_create_rejects_empty_or_long_title() {
		foreach ( array( '', '   ', str_repeat( 'a', 61 ) ) as $title ) {
			$r = $this->postit( array( 'title' => $title ) );
			$this->assertWPError( $r );
			$this->assertSame( 'nzp_invalid', $r->get_error_code() );
			$this->assertSame( 400, $r->get_error_data()['status'] );
		}
		$this->assertNotWPError( $this->postit( array( 'title' => str_repeat( 'ä', 60 ) ) ) );
	}

	public function test_create_rejects_long_text_and_bad_type() {
		$this->assertWPError( $this->postit( array( 'text' => str_repeat( 'a', 301 ) ) ) );
		$this->assertNotWPError( $this->postit( array( 'text' => str_repeat( '🎉', 300 ) ) ) );
		$r = $this->postit( array( 'type' => 'video' ) );
		$this->assertWPError( $r );
		$this->assertSame( 'nzp_invalid', $r->get_error_code() );
	}

	public function test_text_is_stored_as_plain_text() {
		$pin = $this->postit( array( 'text' => "<script>x</script>Hallo\nWelt 🎉" ) );
		$this->assertNotWPError( $pin );
		$stored = get_post( $pin->ID )->post_content;
		$this->assertStringNotContainsString( '<script', $stored );
		$this->assertStringContainsString( "\n", $stored );
		$this->assertStringContainsString( '🎉', $stored );
	}

	public function test_create_image_pin() {
		$att = self::factory()->attachment->create_object( array( 'file' => 'a.jpg', 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ) );
		$pin = Pins::create( $this->board, $this->user, array( 'type' => 'image', 'text' => 'Unterschrift', 'attachment_id' => $att ) );
		$this->assertNotWPError( $pin );
		$this->assertSame( 'Bild', $pin->post_title );
		$this->assertSame( 'Unterschrift', $pin->post_content );
		$this->assertSame( $att, (int) get_post_thumbnail_id( $pin->ID ) );
		$this->assertSame( 'image', get_post_meta( $pin->ID, '_pin_type', true ) );
	}

	public function test_create_image_pin_validation() {
		$att  = self::factory()->attachment->create_object( array( 'file' => 'a.jpg', 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ) );
		$txt  = self::factory()->attachment->create_object( array( 'file' => 'a.txt', 'post_mime_type' => 'text/plain', 'post_type' => 'attachment' ) );
		$post = self::factory()->post->create();
		$cases = array(
			array( 'type' => 'image' ),
			array( 'type' => 'image', 'attachment_id' => 999999 ),
			array( 'type' => 'image', 'attachment_id' => $txt ),
			array( 'type' => 'image', 'attachment_id' => $post ),
			array( 'type' => 'image', 'attachment_id' => $att, 'text' => str_repeat( 'a', 101 ) ),
		);
		foreach ( $cases as $c ) {
			$r = Pins::create( $this->board, $this->user, $c );
			$this->assertWPError( $r );
			$this->assertSame( 'nzp_invalid', $r->get_error_code() );
			$this->assertSame( 400, $r->get_error_data()['status'] );
		}
	}

	public function test_clamp_position() {
		$this->assertSame( array( 0, 600 ), Pins::clamp_position( -50, 5000, 700 ) );
		$this->assertSame( array( 800, 10 ), Pins::clamp_position( 950, 10, 700 ) );
	}

	public function test_update_position_and_text_but_not_type() {
		$pin = $this->postit();
		$r   = Pins::update( $pin, array( 'x' => 9999, 'y' => 20, 'title' => 'Neu', 'text' => 'Neuer Text', 'type' => 'image' ), $this->user );
		$this->assertNotWPError( $r );
		$this->assertSame( 'Neu', $r->post_title );
		$this->assertSame( 'Neuer Text', $r->post_content );
		$this->assertSame( 800, (int) get_post_meta( $pin->ID, '_pin_x', true ) );
		$this->assertSame( 20, (int) get_post_meta( $pin->ID, '_pin_y', true ) );
		$this->assertSame( 'postit', get_post_meta( $pin->ID, '_pin_type', true ) );
		$bad = Pins::update( $pin, array( 'title' => '' ), $this->user );
		$this->assertWPError( $bad );
		$this->assertSame( 'Neu', get_post( $pin->ID )->post_title );
	}

	public function test_update_image_pin_keeps_title_and_validates_caption() {
		$att = self::factory()->attachment->create_object( array( 'file' => 'a.jpg', 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ) );
		$pin = Pins::create( $this->board, $this->user, array( 'type' => 'image', 'text' => 'a', 'attachment_id' => $att ) );
		$r   = Pins::update( $pin, array( 'title' => 'Ignoriert', 'text' => 'b' ), $this->user );
		$this->assertSame( 'Bild', $r->post_title );
		$this->assertSame( 'b', $r->post_content );
		$this->assertWPError( Pins::update( $pin, array( 'text' => str_repeat( 'a', 101 ) ), $this->user ) );
	}

	public function test_update_ignores_sticky_for_non_moderator() {
		$pin = $this->postit();
		Pins::update( $pin, array( 'sticky' => true ), $this->user );
		$this->assertEmpty( (int) get_post_meta( $pin->ID, '_pin_sticky', true ) );
	}

	public function test_update_sets_sticky_for_editor() {
		$ed  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$pin = $this->postit();
		Pins::update( $pin, array( 'sticky' => true ), $ed );
		$this->assertSame( 1, (int) get_post_meta( $pin->ID, '_pin_sticky', true ) );
		Pins::update( $pin, array( 'sticky' => false ), $ed );
		$this->assertSame( 0, (int) get_post_meta( $pin->ID, '_pin_sticky', true ) );
	}

	public function test_trash() {
		$pin = $this->postit();
		Pins::trash( $pin );
		$this->assertSame( 'trash', get_post_status( $pin->ID ) );
	}

	public function test_visible_hides_expired_and_keeps_sticky() {
		$this->settings( array( 'lifetime_days' => 10 ) );
		$fresh   = $this->old_pin( 1 );
		$expired = $this->old_pin( 11 );
		$sticky  = $this->old_pin( 400, true );
		$ids     = wp_list_pluck( Pins::visible( $this->board ), 'ID' );
		$this->assertSame( array( $sticky->ID, $fresh->ID ), $ids );
		$this->assertNotContains( $expired->ID, $ids );
	}

	public function test_visible_orders_newest_first_and_only_published() {
		$this->settings( array( 'lifetime_days' => 100 ) );
		$a = $this->old_pin( 5 );
		$b = $this->old_pin( 2 );
		$c = $this->old_pin( 3 );
		wp_update_post( array( 'ID' => $c->ID, 'post_status' => 'draft' ) );
		$this->assertSame( array( $b->ID, $a->ID ), wp_list_pluck( Pins::visible( $this->board ), 'ID' ) );
	}

	public function test_visible_only_this_board() {
		$other = get_term( self::factory()->term->create( array( 'taxonomy' => 'pinboard' ) ), 'pinboard' );
		$mine  = $this->old_pin( 1 );
		$foreign = $this->old_pin( 1 );
		wp_set_object_terms( $foreign->ID, array( $other->term_id ), 'pinboard' );
		$this->assertSame( array( $mine->ID ), wp_list_pluck( Pins::visible( $this->board ), 'ID' ) );
	}

	public function test_visible_respects_max_pins() {
		$this->settings( array( 'max_pins' => 3 ) );
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->old_pin( $i );
		}
		$this->assertCount( 3, Pins::visible( $this->board ) );
		$this->old_pin( 50, true );
		$res = Pins::visible( $this->board );
		$this->assertCount( 3, $res );
		$this->assertSame( 1, (int) get_post_meta( $res[0]->ID, '_pin_sticky', true ) );
	}

	public function test_lifetime_zero_never_expires() {
		$this->settings( array( 'lifetime_days' => 0 ) );
		$old = $this->old_pin( 2000 );
		$this->assertContains( $old->ID, wp_list_pluck( Pins::visible( $this->board ), 'ID' ) );
		$this->assertNull( Pins::expires_at( $old, $this->board ) );
	}

	public function test_expires_at() {
		$this->settings( array( 'lifetime_days' => 10 ) );
		$pin = $this->old_pin( 4 );
		$exp = Pins::expires_at( $pin, $this->board );
		$this->assertInstanceOf( DateTimeImmutable::class, $exp );
		$this->assertEqualsWithDelta( time() + 6 * DAY_IN_SECONDS, $exp->getTimestamp(), 5 );
		$this->assertNull( Pins::expires_at( $this->old_pin( 4, true ), $this->board ) );
	}

	public function test_to_array_hides_author_for_guests() {
		$pin   = $this->postit( array( 'title' => 'T', 'text' => 'X' ) );
		$guest = Pins::to_array( $pin, 0 );
		$this->assertArrayNotHasKey( 'author', $guest );
		$this->assertFalse( $guest['can_edit'] );
		$this->assertFalse( $guest['can_moderate'] );
		$sub = Pins::to_array( $pin, $this->user );
		$this->assertSame( 'Anna Beispiel', $sub['author'] );
		$this->assertTrue( $sub['can_edit'] );
		$this->assertSame( array( 'id', 'type', 'title', 'text', 'x', 'y', 'rotation', 'sticky', 'image', 'can_edit', 'can_moderate', 'expires', 'author' ), array_keys( $sub ) );
		$this->assertSame( 'postit', $sub['type'] );
		$this->assertSame( 'X', $sub['text'] );
		$this->assertNull( $sub['image'] );
		$this->assertIsString( $sub['expires'] );
		$this->assertSame( $pin->ID, $sub['id'] );
	}

	public function test_to_array_sticky_has_no_expiry_and_moderator_flags() {
		$ed  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$pin = $this->postit();
		Pins::update( $pin, array( 'sticky' => true ), $ed );
		$arr = Pins::to_array( get_post( $pin->ID ), $ed );
		$this->assertTrue( $arr['sticky'] );
		$this->assertNull( $arr['expires'] );
		$this->assertTrue( $arr['can_moderate'] );
		$this->assertTrue( $arr['can_edit'] );
	}

	public function test_to_array_image_structure() {
		$att = self::factory()->attachment->create_object( array( 'file' => 'a.jpg', 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ) );
		wp_update_attachment_metadata(
			$att,
			array(
				'width'  => 2000,
				'height' => 1000,
				'file'   => 'a.jpg',
				'sizes'  => array(
					'medium_large' => array( 'file' => 'a-768x384.jpg', 'width' => 768, 'height' => 384, 'mime-type' => 'image/jpeg' ),
					'large'        => array( 'file' => 'a-1024x512.jpg', 'width' => 1024, 'height' => 512, 'mime-type' => 'image/jpeg' ),
				),
			)
		);
		$pin = Pins::create( $this->board, $this->user, array( 'type' => 'image', 'text' => 'c', 'attachment_id' => $att ) );
		$arr = Pins::to_array( $pin, 0 );
		$this->assertSame( array( 'thumb', 'full', 'width', 'height' ), array_keys( $arr['image'] ) );
		$this->assertStringContainsString( 'a-768x384.jpg', $arr['image']['thumb'] );
		$this->assertStringContainsString( 'a-1024x512.jpg', $arr['image']['full'] );
		$this->assertSame( 768, $arr['image']['width'] );
		$this->assertSame( 384, $arr['image']['height'] );
	}
}
