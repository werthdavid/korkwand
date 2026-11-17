<?php
/**
 * Tests fuer den Bild-Upload.
 */
use NZ\Pinnwand\Upload;

class NZP_Test_Upload extends WP_UnitTestCase {

	private int $user;

	public function set_up() {
		parent::set_up();
		$this->user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/** Kopiert eine Fixture in eine Temp-Datei (wp_handle_sideload verschiebt sie). */
	private function file_from( string $source, string $name, ?string $type = null ): array {
		$tmp = wp_tempnam( $name );
		copy( $source, $tmp );
		return array(
			'name'     => $name,
			'type'     => $type ?? 'application/octet-stream',
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => filesize( $tmp ),
		);
	}

	private function attachment_count(): int {
		return (int) ( new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		) )->found_posts;
	}

	private function cleanup( $id ): void {
		if ( is_int( $id ) ) {
			wp_delete_attachment( $id, true );
		}
	}

	public function test_accepts_jpeg_and_strips_exif() {
		$file = $this->file_from( DIR_TESTDATA . '/images/2004-07-22-DSC_0008.jpg', 'foto.jpg', 'image/jpeg' );
		$this->assertStringContainsString( "Exif\0\0", file_get_contents( $file['tmp_name'] ), 'Fixture muss EXIF enthalten' );
		$id = Upload::handle( $file, $this->user );
		$this->assertIsInt( $id );
		$path = get_attached_file( $id );
		$this->assertStringNotContainsString( "Exif\0\0", file_get_contents( $path ) );
		$this->assertSame( $this->user, (int) get_post( $id )->post_author );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $id ) );
		$this->cleanup( $id );
	}

	public function test_jpeg_orientation_is_applied_before_stripping() {
		$file = $this->file_from( DIR_TESTDATA . '/images/test-image-rotated-90ccw.jpg', 'hoch.jpg', 'image/jpeg' );
		$src  = getimagesize( $file['tmp_name'] );
		$id   = Upload::handle( $file, $this->user );
		$this->assertIsInt( $id );
		$size = getimagesize( get_attached_file( $id ) );
		// EXIF-Orientation 6 (90 Grad): Breite und Hoehe vertauscht.
		$this->assertSame( $src[1], $size[0] );
		$this->assertSame( $src[0], $size[1] );
		$this->cleanup( $id );
	}

	public function test_accepts_png_and_webp() {
		foreach ( array( 'test-image.png' => 'image/png', 'test-image.webp' => 'image/webp' ) as $f => $mime ) {
			$id = Upload::handle( $this->file_from( DIR_TESTDATA . '/images/' . $f, $f, $mime ), $this->user );
			$this->assertIsInt( $id, $f );
			$this->assertSame( $mime, get_post_mime_type( $id ) );
			$this->cleanup( $id );
		}
	}

	public function test_rejects_php_disguised_as_jpg() {
		$before = $this->attachment_count();
		$r      = Upload::handle( $this->file_from( __DIR__ . '/fixtures/fake.jpg', 'fake.jpg', 'image/jpeg' ), $this->user );
		$this->assertWPError( $r );
		$this->assertSame( 'nzp_upload', $r->get_error_code() );
		$this->assertSame( 400, $r->get_error_data()['status'] );
		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_rejects_svg() {
		$before = $this->attachment_count();
		$r      = Upload::handle( $this->file_from( __DIR__ . '/fixtures/image.svg', 'image.svg', 'image/svg+xml' ), $this->user );
		$this->assertWPError( $r );
		$this->assertSame( 'nzp_upload', $r->get_error_code() );
		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_rejects_svg_renamed_to_png() {
		$r = Upload::handle( $this->file_from( __DIR__ . '/fixtures/image.svg', 'image.png', 'image/png' ), $this->user );
		$this->assertWPError( $r );
		$this->assertSame( 'nzp_upload', $r->get_error_code() );
	}

	public function test_rejects_over_8mb() {
		$before       = $this->attachment_count();
		$file         = $this->file_from( DIR_TESTDATA . '/images/test-image.png', 'gross.png', 'image/png' );
		$file['size'] = 8388609;
		$r            = Upload::handle( $file, $this->user );
		$this->assertWPError( $r );
		$this->assertSame( 'nzp_upload', $r->get_error_code() );
		$this->assertSame( 400, $r->get_error_data()['status'] );
		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_rejects_upload_error_code() {
		$file          = $this->file_from( DIR_TESTDATA . '/images/test-image.png', 'x.png', 'image/png' );
		$file['error'] = UPLOAD_ERR_INI_SIZE;
		$r             = Upload::handle( $file, $this->user );
		$this->assertWPError( $r );
		$this->assertSame( 'nzp_upload', $r->get_error_code() );
	}

	public function test_rejects_gif() {
		$r = Upload::handle( $this->file_from( DIR_TESTDATA . '/images/test-image.gif', 'a.gif', 'image/gif' ), $this->user );
		$this->assertWPError( $r );
	}
}
