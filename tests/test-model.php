<?php
/**
 * Tests fuer Datenmodell und Pinnwand-Einstellungen.
 */
class NZP_Test_Model extends WP_UnitTestCase {

	public function test_cpt_and_taxonomy_registered() {
		$this->assertTrue( post_type_exists( 'pin' ) );
		$this->assertTrue( taxonomy_exists( 'pinboard' ) );
	}

	public function test_settings_defaults() {
		$term = $this->factory()->term->create_and_get( array( 'taxonomy' => 'pinboard' ) );
		$this->assertSame(
			array(
				'lifetime_days' => 100,
				'height'        => 700,
				'max_pins'      => 60,
				'notify'        => false,
			),
			\NZ\Pinnwand\Boards::settings( $term )
		);
	}

	public function test_settings_are_clamped() {
		$term = $this->factory()->term->create_and_get( array( 'taxonomy' => 'pinboard' ) );
		\NZ\Pinnwand\Boards::save_settings(
			$term->term_id,
			array(
				'lifetime_days' => '-5',
				'height'        => '99999',
				'max_pins'      => '0',
				'notify'        => '1',
			)
		);
		$this->assertSame(
			array(
				'lifetime_days' => 0,
				'height'        => 3000,
				'max_pins'      => 1,
				'notify'        => true,
			),
			\NZ\Pinnwand\Boards::settings( $term )
		);
	}

	public function test_get_unknown_board_returns_null() {
		$this->assertNull( \NZ\Pinnwand\Boards::get( 'gibtsnicht' ) );
	}

	public function test_get_known_board_returns_term() {
		$id = $this->factory()->term->create( array( 'taxonomy' => 'pinboard', 'slug' => 'kueche' ) );
		$this->assertSame( $id, \NZ\Pinnwand\Boards::get( 'kueche' )->term_id );
	}
}
