<?php
/**
 * Tests: note authors must not be enumerable through author archives / ?author=N / REST users.
 */
use Korkwand\Pins;

class Korkwand_Test_Authors extends WP_UnitTestCase {

	private \WP_Term $board;
	private int $noter;
	private int $writer;
	private ?string $redirect = null;

	public function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	public function set_up() {
		parent::set_up();
		\Korkwand\Permissions::install_caps();
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		$id           = self::factory()->term->create( array( 'taxonomy' => 'korkwand_board', 'name' => 'Test', 'slug' => 'test' ) );
		$this->board  = get_term( $id, 'korkwand_board' );
		$this->noter  = self::factory()->user->create( array( 'role' => 'korkwand_editor', 'user_login' => 'noteauthor', 'display_name' => 'Geheim Zettel' ) );
		$this->writer = self::factory()->user->create( array( 'role' => 'editor', 'user_login' => 'blogwriter', 'display_name' => 'Offen Schreiber' ) );
		Pins::create( $this->board, $this->noter, array( 'type' => 'postit', 'title' => 'Zettel', 'text' => 't' ) );
		self::factory()->post->create( array( 'post_author' => $this->writer, 'post_status' => 'publish' ) );
		$this->redirect = null;
		add_filter(
			'wp_redirect',
			function ( $location ) {
				$this->redirect = (string) $location;
				throw new \RuntimeException( 'redirect' );
			}
		);
	}

	/** Runs the front-end request cycle up to template_redirect. @return string output of template_redirect hooks */
	private function request( string $url ): void {
		$this->go_to( $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET ); // go_to() does not fill $_GET.
		try {
			do_action( 'template_redirect' );
		} catch ( \RuntimeException $e ) {
			// Captured redirect.
		}
	}

	public function test_author_query_for_note_author_is_404_without_redirect() {
		$this->request( '/?author=' . $this->noter );
		$this->assertNull( $this->redirect, 'must not redirect to /author/<nicename>/' );
		$this->assertTrue( is_404() );
	}

	public function test_pretty_author_url_of_note_author_is_404() {
		$this->request( '/author/noteauthor/' );
		$this->assertNull( $this->redirect );
		$this->assertTrue( is_404() );
		$this->assertFalse( is_author() );
	}

	public function test_no_display_name_in_404_title_or_loop() {
		$this->request( '/author/noteauthor/' );
		$this->assertStringNotContainsString( 'Geheim Zettel', wp_get_document_title() );
		$this->assertFalse( have_posts() );
	}

	public function test_user_with_public_post_keeps_archive_and_redirect() {
		$this->request( '/author/blogwriter/' );
		$this->assertTrue( is_author() );
		$this->assertFalse( is_404() );

		$this->request( '/?author=' . $this->writer );
		$this->assertNotNull( $this->redirect );
		$this->assertStringContainsString( '/author/blogwriter/', $this->redirect );
	}

	public function test_rest_users_listing_does_not_include_note_author() {
		wp_set_current_user( 0 );
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$res   = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/users' ) );
		$names = wp_list_pluck( (array) $res->get_data(), 'name' );
		$this->assertNotContains( 'Geheim Zettel', $names );
		$this->assertContains( 'Offen Schreiber', $names );
		$single = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/users/' . $this->noter ) );
		$this->assertSame( 401, $single->get_status() );
		$wp_rest_server = null;
	}

	public function test_users_sitemap_does_not_include_note_author() {
		$args = apply_filters( 'wp_sitemaps_users_query_args', array( 'has_published_posts' => get_post_types( array( 'public' => true ) ) ) );
		$ids  = ( new \WP_User_Query( $args + array( 'fields' => 'ID' ) ) )->get_results();
		$this->assertNotContains( (string) $this->noter, array_map( 'strval', $ids ) );
	}
}
