<?php
/**
 * Tests for the REST API (authorization boundary of the plugin).
 */
use Korkwand\Pins;

class Korkwand_Test_Rest extends WP_UnitTestCase {

	private \WP_Term $board;
	private int $sub;
	private int $other;
	private int $editor;

	public function set_up() {
		parent::set_up();
		\Korkwand\Permissions::install_caps();

		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$id           = self::factory()->term->create( array( 'taxonomy' => 'korkwand_board', 'name' => 'Test', 'slug' => 'test' ) );
		$this->board  = get_term( $id, 'korkwand_board' );
		$this->sub    = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Anna Beispiel' ) );
		$this->other  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( 0 );
		// wp-env host "localhost" yields an invalid default sender address (wordpress@localhost).
		add_filter( 'wp_mail_from', static fn() => 'wordpress@example.org' );
		reset_phpmailer_instance();
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'korkwand_is_uploaded_file' );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	private function req( string $method, string $route, array $params = array(), array $files = array() ): \WP_REST_Response {
		$r = new \WP_REST_Request( $method, '/korkwand/v1' . $route );
		foreach ( $params as $k => $v ) {
			$r->set_param( $k, $v );
		}
		if ( $files ) {
			$r->set_file_params( $files );
		}
		return rest_do_request( $r );
	}

	private function create_as( int $user, array $params = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		return $this->req( 'POST', '/boards/test/pins', $params + array( 'type' => 'postit', 'title' => 'Hallo', 'text' => 'Welt' ) );
	}

	private function pin_of( int $user ): \WP_Post {
		return Pins::create( $this->board, $user, array( 'type' => 'postit', 'title' => 'Fremd', 'text' => 't', 'x' => 100, 'y' => 100 ) );
	}

	private function attachment_count(): int {
		return (int) ( new \WP_Query( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1 ) ) )->found_posts;
	}

	private function image_file(): array {
		$tmp = wp_tempnam( 'foto.jpg' );
		copy( DIR_TESTDATA . '/images/2004-07-22-DSC_0008.jpg', $tmp );
		return array( 'image' => array( 'name' => 'foto.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize( $tmp ) ) );
	}

	public function test_get_pins_public_without_author() {
		$this->pin_of( $this->sub );
		$res = $this->req( 'GET', '/boards/test/pins' );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( array( 'slug' => 'test', 'height' => 700 ), $data['board'] );
		$this->assertCount( 1, $data['pins'] );
		$this->assertArrayNotHasKey( 'author', $data['pins'][0] );
	}

	public function test_get_pins_logged_in_has_author() {
		$this->pin_of( $this->sub );
		wp_set_current_user( $this->other );
		$data = $this->req( 'GET', '/boards/test/pins' )->get_data();
		$this->assertSame( 'Anna Beispiel', $data['pins'][0]['author'] );
	}

	public function test_get_unknown_board_404() {
		$res = $this->req( 'GET', '/boards/gibtsnicht/pins' );
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'korkwand_not_found', $res->get_data()['code'] );
	}

	public function test_guest_cannot_create_401() {
		$res = $this->create_as( 0 );
		$this->assertSame( 401, $res->get_status() );
		$this->assertCount( 0, Pins::visible( $this->board ) );
	}

	public function test_user_without_cap_cannot_create_403() {
		$u = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $u )->remove_cap( 'korkwand_post' );
		get_userdata( $u )->add_cap( 'korkwand_post', false );
		$res = $this->create_as( $u );
		$this->assertSame( 403, $res->get_status() );
		$this->assertCount( 0, Pins::visible( $this->board ) );
	}

	public function test_subscriber_creates_postit_201() {
		$res = $this->create_as( $this->sub, array( 'x' => 10, 'y' => 20 ) );
		$this->assertSame( 201, $res->get_status() );
		$d = $res->get_data();
		$this->assertSame( 'Hallo', $d['title'] );
		$this->assertSame( 10, $d['x'] );
		$this->assertSame( 'Anna Beispiel', $d['author'] );
		$this->assertCount( 1, Pins::visible( $this->board ) );
	}

	public function test_create_on_unknown_board_404() {
		wp_set_current_user( $this->sub );
		$res = $this->req( 'POST', '/boards/gibtsnicht/pins', array( 'type' => 'postit', 'title' => 'x' ) );
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'korkwand_not_found', $res->get_data()['code'] );
	}

	public function test_slug_with_underscore_is_routable() {
		$id = self::factory()->term->create( array( 'taxonomy' => 'korkwand_board', 'name' => 'Mein Board', 'slug' => 'mein_board' ) );
		$this->assertSame( 'mein_board', get_term( $id, 'korkwand_board' )->slug );
		$res = $this->req( 'GET', '/boards/mein_board/pins' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'mein_board', $res->get_data()['board']['slug'] );
		wp_set_current_user( $this->sub );
		$this->assertSame( 201, $this->req( 'POST', '/boards/mein_board/pins', array( 'type' => 'postit', 'title' => 'x' ) )->get_status() );
	}

	public function test_dropped_multipart_body_answers_too_large_400() {
		wp_set_current_user( $this->sub );
		$_SERVER['CONTENT_LENGTH'] = '99999999';
		$r = new \WP_REST_Request( 'POST', '/korkwand/v1/boards/test/pins' );
		$r->set_header( 'content_type', 'multipart/form-data; boundary=x' );
		$res = rest_do_request( $r );
		unset( $_SERVER['CONTENT_LENGTH'] );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'korkwand_upload', $res->get_data()['code'] );
		$this->assertStringStartsWith( 'The image is too large (maximum ', $res->get_data()['message'] );
	}

	public function test_missing_type_without_dropped_body_is_plain_400_and_costs_no_rate_slot() {
		wp_set_current_user( $this->sub );
		$res = $this->req( 'POST', '/boards/test/pins', array( 'title' => 'x' ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'korkwand_invalid', $res->get_data()['code'] );
		$this->assertTrue( \Korkwand\RateLimit::hit( $this->sub ) );
	}

	public function test_guest_without_type_gets_401_not_400() {
		$res = $this->req( 'POST', '/boards/test/pins', array( 'title' => 'x' ) );
		$this->assertSame( 401, $res->get_status() );
	}

	public function test_ini_size_upload_error_maps_to_too_large() {
		wp_set_current_user( $this->sub );
		$file = array( 'image' => array( 'name' => 'x.jpg', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0 ) );
		$res  = $this->req( 'POST', '/boards/test/pins', array( 'type' => 'image' ), $file );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'korkwand_upload', $res->get_data()['code'] );
		$this->assertStringStartsWith( 'The image is too large', $res->get_data()['message'] );
	}

	public function test_create_validation_errors_400_and_bad_type_rejected() {
		$this->assertSame( 400, $this->create_as( $this->sub, array( 'title' => '' ) )->get_status() );
		$this->assertSame( 400, $this->create_as( $this->sub, array( 'type' => 'video' ) )->get_status() );
	}

	public function test_client_attachment_id_is_ignored() {
		$att = self::factory()->attachment->create_object( array( 'file' => 'a.jpg', 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ) );
		$res = $this->create_as( $this->sub, array( 'type' => 'image', 'attachment_id' => $att, 'text' => 'c' ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertCount( 0, Pins::visible( $this->board ) );
	}

	public function test_image_upload_requires_genuine_upload() {
		// Without the filter: tmp_name is not a real HTTP upload (is_uploaded_file false).
		$before = $this->attachment_count();
		wp_set_current_user( $this->sub );
		$res = $this->req( 'POST', '/boards/test/pins', array( 'type' => 'image', 'text' => 'c' ), $this->image_file() );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_subscriber_creates_image_pin_201() {
		add_filter( 'korkwand_is_uploaded_file', '__return_true' );
		wp_set_current_user( $this->sub );
		$res = $this->req( 'POST', '/boards/test/pins', array( 'type' => 'image', 'text' => 'Bild' ), $this->image_file() );
		$this->assertSame( 201, $res->get_status() );
		$d = $res->get_data();
		$this->assertSame( 'image', $d['type'] );
		$this->assertIsArray( $d['image'] );
		wp_delete_attachment( (int) get_post_thumbnail_id( $d['id'] ), true );
	}

	public function test_failed_create_deletes_uploaded_attachment() {
		add_filter( 'korkwand_is_uploaded_file', '__return_true' );
		$before = $this->attachment_count();
		wp_set_current_user( $this->sub );
		$res = $this->req( 'POST', '/boards/test/pins', array( 'type' => 'image', 'text' => str_repeat( 'a', 101 ) ), $this->image_file() );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_subscriber_cannot_patch_foreign_pin_403() {
		$pin = $this->pin_of( $this->sub );
		wp_set_current_user( $this->other );
		$res = $this->req( 'PATCH', '/pins/' . $pin->ID, array( 'x' => 500, 'y' => 300 ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 100, (int) get_post_meta( $pin->ID, '_korkwand_x', true ) );
		$this->assertSame( 100, (int) get_post_meta( $pin->ID, '_korkwand_y', true ) );
	}

	public function test_guest_cannot_patch_401() {
		$pin = $this->pin_of( $this->sub );
		$this->assertSame( 401, $this->req( 'PATCH', '/pins/' . $pin->ID, array( 'x' => 5 ) )->get_status() );
		$this->assertSame( 401, $this->req( 'DELETE', '/pins/' . $pin->ID )->get_status() );
		$this->assertSame( 'publish', get_post_status( $pin->ID ) );
	}

	public function test_owner_can_patch_and_patch_clamps_position() {
		$pin = $this->pin_of( $this->sub );
		wp_set_current_user( $this->sub );
		$res = $this->req( 'PATCH', '/pins/' . $pin->ID, array( 'x' => 99999, 'y' => -50 ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 800, $res->get_data()['x'] );
		$this->assertSame( 0, $res->get_data()['y'] );
	}

	public function test_subscriber_cannot_set_sticky() {
		$pin = $this->pin_of( $this->sub );
		wp_set_current_user( $this->sub );
		$this->req( 'PATCH', '/pins/' . $pin->ID, array( 'sticky' => true ) );
		$this->assertSame( 0, (int) get_post_meta( $pin->ID, '_korkwand_sticky', true ) );
		wp_set_current_user( $this->editor );
		$this->req( 'PATCH', '/pins/' . $pin->ID, array( 'sticky' => true ) );
		$this->assertSame( 1, (int) get_post_meta( $pin->ID, '_korkwand_sticky', true ) );
	}

	public function test_subscriber_cannot_delete_foreign_pin_403() {
		$pin = $this->pin_of( $this->sub );
		wp_set_current_user( $this->other );
		$res = $this->req( 'DELETE', '/pins/' . $pin->ID );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'publish', get_post_status( $pin->ID ) );
	}

	public function test_owner_can_delete() {
		$pin = $this->pin_of( $this->sub );
		wp_set_current_user( $this->sub );
		$res = $this->req( 'DELETE', '/pins/' . $pin->ID );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'deleted' => true ), $res->get_data() );
		$this->assertSame( 'trash', get_post_status( $pin->ID ) );
	}

	public function test_editor_can_delete_foreign_pin() {
		$pin = $this->pin_of( $this->sub );
		wp_set_current_user( $this->editor );
		$res = $this->req( 'DELETE', '/pins/' . $pin->ID );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'trash', get_post_status( $pin->ID ) );
	}

	public function test_patch_delete_404_for_non_pin_trashed_or_boardless() {
		wp_set_current_user( $this->editor );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( 404, $this->req( 'PATCH', '/pins/' . $page, array( 'x' => 1 ) )->get_status() );
		$this->assertSame( 404, $this->req( 'DELETE', '/pins/' . $page )->get_status() );
		$this->assertSame( 'publish', get_post_status( $page ) );

		$gone = $this->pin_of( $this->sub );
		wp_trash_post( $gone->ID );
		$res = $this->req( 'PATCH', '/pins/' . $gone->ID, array( 'x' => 1 ) );
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'korkwand_not_found', $res->get_data()['code'] );
		$this->assertSame( 404, $this->req( 'DELETE', '/pins/' . $gone->ID )->get_status() );

		$orphan = $this->pin_of( $this->sub );
		wp_delete_term( $this->board->term_id, 'korkwand_board' );
		$this->assertSame( 404, $this->req( 'PATCH', '/pins/' . $orphan->ID, array( 'x' => 1 ) )->get_status() );
		$this->assertSame( 404, $this->req( 'DELETE', '/pins/' . $orphan->ID )->get_status() );
		$this->assertSame( 404, $this->req( 'PATCH', '/pins/999999', array( 'x' => 1 ) )->get_status() );
	}

	public function test_rate_limit_after_10_creates_429() {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( 201, $this->create_as( $this->sub )->get_status(), "Zettel $i" );
		}
		$res = $this->create_as( $this->sub );
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( 'korkwand_rate_limited', $res->get_data()['code'] );
		$this->assertSame( 'Too many new notes – please try again later.', $res->get_data()['message'] );
		// Other user is not affected.
		$this->assertSame( 201, $this->create_as( $this->other )->get_status() );
	}

	public function test_rate_limit_precedes_upload() {
		add_filter( 'korkwand_is_uploaded_file', '__return_true' );
		for ( $i = 0; $i < 10; $i++ ) {
			$this->create_as( $this->sub );
		}
		$before = $this->attachment_count();
		$res    = $this->req( 'POST', '/boards/test/pins', array( 'type' => 'image', 'text' => 'c' ), $this->image_file() );
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_ratelimit_hit_unit() {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertTrue( \Korkwand\RateLimit::hit( 42 ) );
		}
		$this->assertFalse( \Korkwand\RateLimit::hit( 42 ) );
		// Old timestamps expire.
		set_transient( 'korkwand_rl_43', array_fill( 0, 10, time() - 3700 ), 3600 );
		$this->assertTrue( \Korkwand\RateLimit::hit( 43 ) );
	}

	public function test_notify_sends_mail_when_enabled() {
		update_term_meta( $this->board->term_id, 'notify', 1 );
		$this->assertSame( 201, $this->create_as( $this->sub, array( 'title' => 'Neu hier' ) )->get_status() );
		$mails = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertNotFalse( $mails );
		$this->assertSame( 'New note on the Korkwand', $mails->subject );
		$this->assertStringContainsString( 'Neu hier', $mails->body );
		$this->assertStringContainsString( 'Anna Beispiel', $mails->body );
		$this->assertStringContainsString( 'post.php', $mails->body );
		$this->assertSame( get_option( 'admin_email' ), $mails->to[0][0] );
	}

	public function test_no_mail_when_notify_disabled() {
		update_term_meta( $this->board->term_id, 'notify', 0 );
		$this->assertSame( 201, $this->create_as( $this->sub )->get_status() );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_delete_reports_500_when_trash_fails() {
		$pin = $this->pin_of( $this->sub );
		wp_set_current_user( $this->sub );
		add_filter( 'pre_trash_post', '__return_false' );
		$res = $this->req( 'DELETE', '/pins/' . $pin->ID );
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'korkwand_error', $res->get_data()['code'] );
		$this->assertSame( 'publish', get_post_status( $pin->ID ) );
	}
}
