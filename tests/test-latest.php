<?php
/**
 * Tests for the "Latest notes" list, shortcode and block.
 */
use Korkwand\Boards;
use Korkwand\Latest;
use Korkwand\Pins;

class Korkwand_Test_Latest extends WP_UnitTestCase {

	private \WP_Term $a;
	private \WP_Term $b;
	private int $member;

	public function set_up() {
		parent::set_up();
		$this->a      = $this->board( 'alpha' );
		$this->b      = $this->board( 'beta' );
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Anna Beispiel' ) );
		wp_set_current_user( 0 );
		unset( $GLOBALS['wp_styles'], $GLOBALS['wp_scripts'] );
	}

	private function board( string $slug ): \WP_Term {
		$id = self::factory()->term->create( array( 'taxonomy' => 'korkwand_board', 'name' => $slug, 'slug' => $slug ) );
		return get_term( $id, 'korkwand_board' );
	}

	/** Creates a post-it; $age = seconds in the past. */
	private function note( \WP_Term $board, string $title, int $age = 0, array $data = array() ): int {
		$pin = Pins::create( $board, $this->member, $data + array( 'type' => 'postit', 'title' => $title, 'text' => 'body' ) );
		$this->assertInstanceOf( \WP_Post::class, $pin );
		$this->age( $pin->ID, $age );
		return $pin->ID;
	}

	private function age( int $id, int $age ): void {
		$ts = time() - $age;
		wp_update_post( array( 'ID' => $id, 'post_date' => wp_date( 'Y-m-d H:i:s', $ts ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $ts ) ) );
	}

	private function image_note( \WP_Term $board, string $caption, int $age = 0 ): int {
		$att = self::factory()->attachment->create_object( array( 'file' => 'a.jpg', 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ) );
		$pin = Pins::create( $board, $this->member, array( 'type' => 'image', 'text' => $caption, 'attachment_id' => $att ) );
		$this->age( $pin->ID, $age );
		return $pin->ID;
	}

	private function labels( string $html ): array {
		preg_match_all( '#<li class="korkwand-latest__item">(.*?)</li>#s', $html, $m );
		return array_map( static fn( $li ) => trim( wp_strip_all_tags( preg_replace( '#<time.*#s', '', $li ) ) ), $m[1] );
	}

	public function test_newest_first_across_boards() {
		$this->note( $this->a, 'oldest', 300 );
		$this->note( $this->b, 'middle', 200 );
		$this->note( $this->a, 'newest', 100 );
		$this->assertSame( array( 'newest', 'middle', 'oldest' ), $this->labels( Latest::render( array() ) ) );
	}

	public function test_board_filter() {
		$this->note( $this->a, 'in-a', 100 );
		$this->note( $this->b, 'in-b', 50 );
		$this->assertSame( array( 'in-a' ), $this->labels( Latest::render( array( 'board' => 'alpha' ) ) ) );
	}

	public function test_expired_excluded_per_board_lifetime_sticky_kept() {
		update_term_meta( $this->a->term_id, 'lifetime_days', 1 );
		update_term_meta( $this->b->term_id, 'lifetime_days', 0 );
		$this->note( $this->a, 'a-expired', 2 * DAY_IN_SECONDS );
		$this->note( $this->a, 'a-fresh', 60 );
		$this->note( $this->b, 'b-old-but-never-expires', 500 * DAY_IN_SECONDS );
		$sticky = $this->note( $this->a, 'a-sticky-old', 10 * DAY_IN_SECONDS );
		update_post_meta( $sticky, '_korkwand_sticky', 1 );
		$labels = $this->labels( Latest::render( array() ) );
		$this->assertNotContains( 'a-expired', $labels );
		$this->assertSame( array( 'a-fresh', 'a-sticky-old', 'b-old-but-never-expires' ), $labels );
	}

	public function test_sticky_does_not_float_to_top() {
		$old = $this->note( $this->a, 'old-sticky', 1000 );
		$this->note( $this->a, 'newer', 10 );
		update_post_meta( $old, '_korkwand_sticky', 1 );
		$this->assertSame( array( 'newer', 'old-sticky' ), $this->labels( Latest::render( array( 'board' => 'alpha' ) ) ) );
	}

	public function test_trashed_notes_excluded() {
		$id = $this->note( $this->a, 'gone' );
		wp_trash_post( $id );
		$this->assertStringNotContainsString( 'gone', Latest::render( array() ) );
	}

	public function test_count_clamped_and_limits() {
		for ( $i = 0; $i < 25; $i++ ) {
			$this->note( $this->a, 'n' . $i, 1000 - $i );
		}
		$this->assertCount( 5, $this->labels( Latest::render( array() ) ) );
		$this->assertCount( 1, $this->labels( Latest::render( array( 'count' => 0 ) ) ) );
		$this->assertCount( 1, $this->labels( Latest::render( array( 'count' => '-3' ) ) ) );
		$this->assertCount( 20, $this->labels( Latest::render( array( 'count' => 99 ) ) ) );
		$this->assertCount( 3, $this->labels( Latest::render( array( 'count' => '3' ) ) ) );
	}

	public function test_label_rules() {
		$this->note( $this->a, 'Post-it title', 400 );
		$this->image_note( $this->a, 'My caption', 300 );
		$this->image_note( $this->a, '', 200 );
		$labels = $this->labels( Latest::render( array() ) );
		$this->assertSame( array( 'Foto', 'My caption', 'Post-it title' ), array_map( static fn( $l ) => 'Photo' === $l ? 'Foto' : $l, $labels ) );
	}

	public function test_label_truncated_multibyte_with_ellipsis() {
		$long = str_repeat( 'Ä', 60 );
		$this->image_note( $this->a, $long . 'xyz', 10 );
		$this->image_note( $this->a, str_repeat( 'ö', 60 ), 20 );
		$labels = $this->labels( Latest::render( array() ) );
		$this->assertSame( str_repeat( 'Ä', 59 ) . '…', $labels[0] );
		$this->assertSame( 60, mb_strlen( $labels[0] ) );
		$this->assertSame( str_repeat( 'ö', 60 ), $labels[1] );
	}

	public function test_label_escaped() {
		$id = $this->note( $this->a, 'x' );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'A &lt;script&gt;alert(1)&lt;/script&gt;' ) );
		$html = Latest::render( array() );
		$this->assertStringContainsString( 'A &lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_date_in_time_element_and_toggle() {
		$id   = $this->note( $this->a, 'dated', 3600 );
		$ts   = get_post_datetime( $id, 'date', 'gmt' )->getTimestamp();
		$html = Latest::render( array() );
		$this->assertStringContainsString( '<time datetime="' . wp_date( 'c', $ts ) . '"', $html );
		$this->assertStringContainsString( '>' . wp_date( get_option( 'date_format' ), $ts ) . '</time>', $html );
		$off = Latest::render( array( 'show_date' => '0' ) );
		$this->assertStringNotContainsString( '<time', $off );
		$this->assertStringContainsString( 'dated', $off );
		$this->assertStringNotContainsString( '<time', Latest::render( array( 'show_date' => false ) ) );
	}

	public function test_guest_sees_no_author_member_does() {
		$this->note( $this->a, 'authored' );
		$this->assertStringNotContainsString( 'Anna Beispiel', Latest::render( array() ) );
		$this->assertStringNotContainsString( 'korkwand-latest__author', Latest::render( array() ) );
		wp_set_current_user( $this->member );
		$html = Latest::render( array() );
		$this->assertStringContainsString( '<span class="korkwand-latest__author">Anna Beispiel</span>', $html );
	}

	public function test_link_uses_explicit_page_then_auto_page_else_plain_text() {
		$id = $this->note( $this->a, 'linked' );
		$this->assertStringNotContainsString( '<a ', Latest::render( array() ) );
		$this->assertStringContainsString( 'linked', Latest::render( array() ) );

		$auto = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '[korkwand board="alpha"]' ) );
		$html = Latest::render( array() );
		$this->assertStringContainsString( '<a href="' . esc_url( get_permalink( $auto ) ) . '#korkwand-note-' . $id . '">linked</a>', $html );

		$expl = self::factory()->post->create( array( 'post_type' => 'page' ) );
		Boards::save_settings( $this->a->term_id, array( 'page_id' => (string) $expl ) );
		$html = Latest::render( array() );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $expl ) ) . '#korkwand-note-' . $id . '"', $html );
	}

	public function test_empty_state() {
		$html = Latest::render( array() );
		$this->assertStringContainsString( 'class="korkwand-latest"', $html );
		$this->assertStringContainsString( 'Nothing on the board yet.', $html );
		$this->assertStringNotContainsString( 'korkwand-latest__item', $html );
	}

	public function test_markup_structure() {
		$this->note( $this->a, 'm' );
		$html = Latest::render( array() );
		$this->assertMatchesRegularExpression( '#^<div class="korkwand-latest"><ul class="korkwand-latest__list"><li class="korkwand-latest__item">.*</li></ul></div>$#s', $html );
	}

	public function test_unknown_board_hint_only_for_admin() {
		$this->assertSame( '', Latest::render( array( 'board' => 'nope' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = Latest::render( array( 'board' => 'nope' ) );
		$this->assertStringContainsString( 'korkwand-error', $html );
		$this->assertStringContainsString( 'nope', $html );
	}

	public function test_shortcode() {
		$this->note( $this->a, 'via-shortcode', 10 );
		$this->note( $this->b, 'other', 5 );
		$html = do_shortcode( '[korkwand_latest board="alpha" count="3" show_date="0"]' );
		$this->assertSame( array( 'via-shortcode' ), $this->labels( $html ) );
		$this->assertStringNotContainsString( '<time', $html );
	}

	public function test_block_registered_and_renders_with_wrapper() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'korkwand/latest' ) );
		$this->note( $this->a, 'via-block', 10 );
		$html = do_blocks( '<!-- wp:korkwand/latest {"board":"alpha","count":2,"showDate":false,"align":"wide"} /-->' );
		$this->assertStringContainsString( 'wp-block-korkwand-latest', $html );
		$this->assertStringContainsString( 'alignwide', $html );
		$this->assertStringContainsString( 'via-block', $html );
		$this->assertStringNotContainsString( '<time', $html );
		$json = json_decode( file_get_contents( KORKWAND_DIR . 'blocks/latest/block.json' ), true );
		$this->assertSame( 3, $json['apiVersion'] );
		$this->assertSame( 'korkwand-latest-style', $json['style'] );
		$this->assertSame( array( 'korkwand-latest-style' ), WP_Block_Type_Registry::get_instance()->get_registered( 'korkwand/latest' )->style_handles );
	}

	public function test_block_renders_nothing_for_unknown_board_and_guest() {
		$this->assertSame( '', trim( do_blocks( '<!-- wp:korkwand/latest {"board":"nope"} /-->' ) ) );
	}

	public function test_block_defaults() {
		for ( $i = 0; $i < 7; $i++ ) {
			$this->note( $this->a, 'n' . $i, 100 - $i );
		}
		$html = do_blocks( '<!-- wp:korkwand/latest /-->' );
		$this->assertCount( 5, $this->labels( $html ) );
		$this->assertStringContainsString( '<time', $html );
	}

	public function test_assets_enqueued_only_when_rendered() {
		$this->assertFalse( wp_style_is( 'korkwand-latest-style', 'enqueued' ) );
		Latest::render( array( 'board' => 'nope' ) );
		$this->assertFalse( wp_style_is( 'korkwand-latest-style', 'enqueued' ) );
		Latest::render( array() );
		$this->assertTrue( wp_style_is( 'korkwand-latest-style', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'korkwand-config', 'enqueued' ) );
		$this->assertFileExists( KORKWAND_DIR . 'assets/css/latest.css' );
	}

	public function test_query_count_does_not_grow_per_note() {
		global $wpdb;
		for ( $i = 0; $i < 3; $i++ ) {
			$this->note( $this->a, 'a' . $i, 100 - $i );
			$this->note( $this->b, 'b' . $i, 100 - $i );
		}
		wp_cache_flush();
		$before = $wpdb->num_queries;
		Latest::render( array( 'count' => 20 ) );
		$few = $wpdb->num_queries - $before;
		for ( $i = 0; $i < 10; $i++ ) {
			$this->note( $this->a, 'x' . $i, 50 - $i );
			$this->note( $this->b, 'y' . $i, 50 - $i );
		}
		wp_cache_flush();
		$before = $wpdb->num_queries;
		Latest::render( array( 'count' => 20 ) );
		$many = $wpdb->num_queries - $before;
		// 20 more notes must not add per-note queries (tolerance: cache-dependent option loads).
		$this->assertLessThanOrEqual( $few + 3, $many );
	}

	public function test_german_translations_shipped_for_editor_and_php() {
		$json = glob( KORKWAND_DIR . 'languages/korkwand-de_DE-*.json' );
		$found = array();
		foreach ( $json as $file ) {
			$data = json_decode( file_get_contents( $file ), true );
			if ( 'blocks/latest/edit.js' === $data['source'] ) {
				$found = $data['locale_data']['messages'];
			}
		}
		$this->assertSame( array( 'Alle Pinnwände' ), $found['All boards'] ?? null );
		$this->assertSame( array( 'Datum anzeigen' ), $found['Show date'] ?? null );
		$po = file_get_contents( KORKWAND_DIR . 'languages/korkwand-de_DE.po' );
		$this->assertStringContainsString( "msgid \"Nothing on the board yet.\"\nmsgstr \"Noch nichts auf der Pinnwand.\"", $po );
		$this->assertStringContainsString( "msgid \"Photo\"\nmsgstr \"Foto\"", $po );
		$this->assertStringContainsString( "msgstr \"Pinnwand-Seite\"", $po );
	}
}
