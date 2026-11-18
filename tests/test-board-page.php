<?php
/**
 * Tests for the board page (explicit page_id, auto detection, URL resolution).
 */
use Korkwand\Boards;

class Korkwand_Test_Board_Page extends WP_UnitTestCase {

	private \WP_Term $board;

	public function set_up() {
		parent::set_up();
		$id          = self::factory()->term->create( array( 'taxonomy' => 'korkwand_board', 'name' => 'Brett', 'slug' => 'brett' ) );
		$this->board = get_term( $id, 'korkwand_board' );
	}

	private function page( string $content, array $extra = array() ): int {
		return self::factory()->post->create( $extra + array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $content ) );
	}

	private function auto(): int {
		return (int) get_term_meta( $this->board->term_id, 'auto_page_id', true );
	}

	public function test_page_id_setting_default_and_clamp() {
		$this->assertSame( 0, Boards::settings( $this->board )['page_id'] );
		Boards::save_settings( $this->board->term_id, array( 'page_id' => '-5' ) );
		$this->assertSame( 0, Boards::settings( $this->board )['page_id'] );
		Boards::save_settings( $this->board->term_id, array( 'page_id' => '42' ) );
		$this->assertSame( 42, Boards::settings( $this->board )['page_id'] );
	}

	public function test_page_url_null_without_page() {
		$this->assertNull( Boards::page_url( $this->board ) );
	}

	public function test_page_url_explicit_page() {
		$p = $this->page( 'x' );
		Boards::save_settings( $this->board->term_id, array( 'page_id' => (string) $p ) );
		$this->assertSame( get_permalink( $p ), Boards::page_url( $this->board ) );
	}

	public function test_page_url_ignores_unpublished_explicit_page() {
		$p = $this->page( 'x', array( 'post_status' => 'draft' ) );
		Boards::save_settings( $this->board->term_id, array( 'page_id' => (string) $p ) );
		$this->assertNull( Boards::page_url( $this->board ) );
	}

	public function test_explicit_wins_over_auto() {
		$auto = $this->page( '[korkwand board="brett"]' );
		$expl = $this->page( 'y' );
		Boards::save_settings( $this->board->term_id, array( 'page_id' => (string) $expl ) );
		$this->assertSame( get_permalink( $expl ), Boards::page_url( $this->board ) );
		$this->assertSame( $auto, $this->auto() );
	}

	public function test_auto_detect_shortcode() {
		$p = $this->page( 'Hallo [korkwand board="brett"] Ende' );
		$this->assertSame( $p, $this->auto() );
		$this->assertSame( get_permalink( $p ), Boards::page_url( $this->board ) );
	}

	public function test_auto_detect_block() {
		$p = $this->page( '<!-- wp:korkwand/board {"board":"brett"} /-->' );
		$this->assertSame( $p, $this->auto() );
	}

	public function test_auto_detect_block_nested_in_group() {
		$p = $this->page( '<!-- wp:group --><div class="wp-block-group"><!-- wp:korkwand/board {"board":"brett"} /--></div><!-- /wp:group -->' );
		$this->assertSame( $p, $this->auto() );
	}

	public function test_auto_detect_ignores_other_slug_and_drafts() {
		$this->page( '[korkwand board="other"]' );
		$this->page( '[korkwand board="brett"]', array( 'post_status' => 'draft' ) );
		$this->assertSame( 0, $this->auto() );
	}

	public function test_auto_detect_not_overwritten_while_old_page_valid() {
		$first = $this->page( '[korkwand board="brett"]' );
		$this->page( '[korkwand board="brett"]' );
		$this->assertSame( $first, $this->auto() );
	}

	public function test_auto_detect_replaced_when_old_page_no_longer_contains_board() {
		$first  = $this->page( '[korkwand board="brett"]' );
		$second = $this->page( 'nothing yet' );
		wp_update_post( array( 'ID' => $first, 'post_content' => 'edited' ) );
		$this->assertSame( 0, $this->auto() );
		wp_update_post( array( 'ID' => $second, 'post_content' => '[korkwand board="brett"]' ) );
		$this->assertSame( $second, $this->auto() );
	}

	public function test_auto_detect_replaced_when_old_page_unpublished_by_direct_meta() {
		$first = $this->page( '[korkwand board="brett"]' );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $first ) );
		clean_post_cache( $first );
		$second = $this->page( '[korkwand board="brett"]' );
		$this->assertSame( $second, $this->auto() );
	}

	public function test_auto_not_stored_when_explicit_page_set() {
		$expl = $this->page( 'y' );
		Boards::save_settings( $this->board->term_id, array( 'page_id' => (string) $expl ) );
		$this->page( '[korkwand board="brett"]' );
		$this->assertSame( 0, $this->auto() );
	}

	public function test_auto_cleared_on_trash() {
		$p = $this->page( '[korkwand board="brett"]' );
		wp_trash_post( $p );
		$this->assertSame( 0, $this->auto() );
		$this->assertNull( Boards::page_url( $this->board ) );
	}

	public function test_auto_cleared_on_unpublish() {
		$p = $this->page( '[korkwand board="brett"]' );
		wp_update_post( array( 'ID' => $p, 'post_status' => 'draft' ) );
		$this->assertSame( 0, $this->auto() );
	}

	public function test_auto_cleared_on_delete() {
		$p = $this->page( '[korkwand board="brett"]' );
		wp_delete_post( $p, true );
		$this->assertSame( 0, $this->auto() );
	}

	public function test_works_for_public_custom_post_type() {
		register_post_type( 'kw_custom', array( 'public' => true ) );
		$p = $this->page( '[korkwand board="brett"]', array( 'post_type' => 'kw_custom' ) );
		$this->assertSame( $p, $this->auto() );
		unregister_post_type( 'kw_custom' );
	}

	public function test_revisions_are_ignored() {
		$p = $this->page( 'plain' );
		wp_update_post( array( 'ID' => $p, 'post_content' => '[korkwand board="brett"]' ) );
		$this->assertSame( $p, $this->auto() );
	}
}
