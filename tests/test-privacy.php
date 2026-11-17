<?php
/**
 * Tests fuer Datenschutz-Exporter und -Eraser.
 */
use NZ\Pinnwand\Privacy;

class NZP_Test_Privacy extends WP_UnitTestCase {

	private \WP_Term $board;
	private int $user;

	public function set_up() {
		parent::set_up();
		$id          = self::factory()->term->create( array( 'taxonomy' => 'pinboard', 'name' => 'Test' ) );
		$this->board = get_term( $id, 'pinboard' );
		$this->user  = self::factory()->user->create( array( 'role' => 'subscriber', 'user_email' => 'anna@example.test' ) );
	}

	private function pin( string $title, string $text = '' ): int {
		$id = self::factory()->post->create(
			array( 'post_type' => 'pin', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $text, 'post_author' => $this->user )
		);
		update_post_meta( $id, '_pin_type', 'postit' );
		return $id;
	}

	private function image_pin(): array {
		$att = self::factory()->attachment->create_object( array( 'file' => 'p.jpg', 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment', 'post_author' => $this->user ) );
		$id  = $this->pin( 'Bild' );
		update_post_meta( $id, '_pin_type', 'image' );
		update_post_meta( $att, '_nzp_upload', 1 );
		set_post_thumbnail( $id, $att );
		return array( $id, $att );
	}

	public function test_registered() {
		$ex = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$er = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$this->assertSame( 'Pinnwand', $ex['nz-pinnwand']['exporter_friendly_name'] );
		$this->assertSame( 'Pinnwand', $er['nz-pinnwand']['eraser_friendly_name'] );
		$this->assertIsCallable( $ex['nz-pinnwand']['callback'] );
		$this->assertIsCallable( $er['nz-pinnwand']['callback'] );
	}

	public function test_exporter_lists_pins() {
		$p = $this->pin( 'Hallo', 'Welt' );
		list( $ip, $att ) = $this->image_pin();
		$r = Privacy::exporter( 'anna@example.test', 1 );

		$this->assertTrue( $r['done'] );
		$this->assertCount( 2, $r['data'] );
		$first = $r['data'][0];
		$this->assertSame( 'nz-pinnwand', $first['group_id'] );
		$this->assertSame( 'Pinnwand', $first['group_label'] );
		$flat = array();
		foreach ( $r['data'] as $item ) {
			foreach ( $item['data'] as $d ) {
				$flat[ $item['item_id'] ][ $d['name'] ] = $d['value'];
			}
		}
		$this->assertSame( 'Hallo', $flat[ 'pin-' . $p ]['Titel'] );
		$this->assertSame( 'Welt', $flat[ 'pin-' . $p ]['Text'] );
		$this->assertNotEmpty( $flat[ 'pin-' . $p ]['Datum'] );
		$this->assertSame( wp_get_attachment_url( $att ), $flat[ 'pin-' . $ip ]['Bild'] );
	}

	public function test_exporter_unknown_email() {
		$r = Privacy::exporter( 'niemand@example.test', 1 );
		$this->assertSame( array(), $r['data'] );
		$this->assertTrue( $r['done'] );
	}

	public function test_exporter_paginates() {
		for ( $i = 0; $i < 51; $i++ ) {
			$this->pin( "P$i" );
		}
		$r1 = Privacy::exporter( 'anna@example.test', 1 );
		$this->assertCount( 50, $r1['data'] );
		$this->assertFalse( $r1['done'] );
		$r2 = Privacy::exporter( 'anna@example.test', 2 );
		$this->assertCount( 1, $r2['data'] );
		$this->assertTrue( $r2['done'] );
	}

	public function test_eraser_deletes_pins_and_images() {
		$p = $this->pin( 'Weg' );
		list( $ip, $att ) = $this->image_pin();
		$t = $this->pin( 'Papierkorb' );
		wp_trash_post( $t );

		$r = Privacy::eraser( 'anna@example.test', 1 );

		$this->assertTrue( $r['items_removed'] );
		$this->assertFalse( $r['items_retained'] );
		$this->assertSame( array(), $r['messages'] );
		$this->assertTrue( $r['done'] );
		$this->assertNull( get_post( $p ) );
		$this->assertNull( get_post( $ip ) );
		$this->assertNull( get_post( $att ) );
		$this->assertNull( get_post( $t ) );
	}

	public function test_eraser_unknown_email_and_nothing_to_erase() {
		$r = Privacy::eraser( 'niemand@example.test', 1 );
		$this->assertFalse( $r['items_removed'] );
		$this->assertTrue( $r['done'] );
		$r = Privacy::eraser( 'anna@example.test', 1 );
		$this->assertFalse( $r['items_removed'] );
		$this->assertTrue( $r['done'] );
	}

	public function test_eraser_pages_over_50() {
		for ( $i = 0; $i < 51; $i++ ) {
			$this->pin( "P$i" );
		}
		$r1 = Privacy::eraser( 'anna@example.test', 1 );
		$this->assertTrue( $r1['items_removed'] );
		$this->assertFalse( $r1['done'] );
		$r2 = Privacy::eraser( 'anna@example.test', 2 );
		$this->assertTrue( $r2['done'] );
	}
}
