<?php
/**
 * Tests fuer den Bild-Upload.
 */
use Korkwand\Upload;

class Korkwand_Test_Upload extends WP_UnitTestCase {

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

	public function test_upload_is_flagged_as_plugin_upload() {
		$id = Upload::handle( $this->file_from( DIR_TESTDATA . '/images/test-image.png', 'a.png', 'image/png' ), $this->user );
		$this->assertIsInt( $id );
		$this->assertSame( '1', get_post_meta( $id, '_korkwand_upload', true ) );
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
		$this->assertSame( 'korkwand_upload', $r->get_error_code() );
		$this->assertSame( 400, $r->get_error_data()['status'] );
		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_rejects_svg() {
		$before = $this->attachment_count();
		$r      = Upload::handle( $this->file_from( __DIR__ . '/fixtures/image.svg', 'image.svg', 'image/svg+xml' ), $this->user );
		$this->assertWPError( $r );
		$this->assertSame( 'korkwand_upload', $r->get_error_code() );
		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_rejects_svg_renamed_to_png() {
		$r = Upload::handle( $this->file_from( __DIR__ . '/fixtures/image.svg', 'image.png', 'image/png' ), $this->user );
		$this->assertWPError( $r );
		$this->assertSame( 'korkwand_upload', $r->get_error_code() );
	}

	public function test_rejects_over_8mb() {
		$before       = $this->attachment_count();
		$file         = $this->file_from( DIR_TESTDATA . '/images/test-image.png', 'gross.png', 'image/png' );
		$file['size'] = 8388609;
		$r            = Upload::handle( $file, $this->user );
		$this->assertWPError( $r );
		$this->assertSame( 'korkwand_upload', $r->get_error_code() );
		$this->assertSame( 400, $r->get_error_data()['status'] );
		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_rejects_upload_error_code() {
		$file          = $this->file_from( DIR_TESTDATA . '/images/test-image.png', 'x.png', 'image/png' );
		$file['error'] = UPLOAD_ERR_INI_SIZE;
		$r             = Upload::handle( $file, $this->user );
		$this->assertWPError( $r );
		$this->assertSame( 'korkwand_upload', $r->get_error_code() );
	}

	public function test_rejects_gif() {
		$r = Upload::handle( $this->file_from( DIR_TESTDATA . '/images/test-image.gif', 'a.gif', 'image/gif' ), $this->user );
		$this->assertWPError( $r );
	}

	private function upload_files(): array {
		$dir = wp_get_upload_dir();
		$out = array();
		if ( is_dir( $dir['basedir'] ) ) {
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir['basedir'], \FilesystemIterator::SKIP_DOTS ) ) as $f ) {
				$out[] = $f->getPathname();
			}
		}
		sort( $out );
		return $out;
	}

	public function test_rejects_corrupt_image_when_reencode_fails_and_leaves_nothing() {
		$cases  = array(
			'trunc.png' => array( 'test-image.png', 400, 'image/png' ),
			'trunc.jpg' => array( 'test-image.jpg', 600, 'image/jpeg' ),
		);
		foreach ( $cases as $name => $c ) {
			$file = $this->file_from( DIR_TESTDATA . '/images/' . $c[0], $name, $c[2] );
			file_put_contents( $file['tmp_name'], substr( file_get_contents( $file['tmp_name'] ), 0, $c[1] ) );
			$file['size'] = filesize( $file['tmp_name'] );
			$this->assertNotFalse( getimagesize( $file['tmp_name'] ), 'Fixture muss getimagesize bestehen' );
			$before_att   = $this->attachment_count();
			$before_files = $this->upload_files();
			$r            = Upload::handle( $file, $this->user );
			$this->assertWPError( $r, $name );
			$this->assertSame( 'korkwand_upload', $r->get_error_code() );
			$this->assertSame( 400, $r->get_error_data()['status'] );
			$this->assertSame( $before_att, $this->attachment_count() );
			$this->assertSame( $before_files, $this->upload_files(), 'Nichts in uploads/ zurueckgelassen' );
		}
	}

	public function test_rejects_too_many_pixels() {
		// PNG-Header mit 6000x5000 (30 MP) ohne Bilddaten; scheitert vor jedem Decode.
		$png  = "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . pack( 'NNCCCCC', 6000, 5000, 8, 2, 0, 0, 0 ) . pack( 'N', 0 );
		$tmp  = wp_tempnam( 'big.png' );
		file_put_contents( $tmp, $png );
		$file = array( 'name' => 'big.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize( $tmp ) );
		$r    = Upload::handle( $file, $this->user );
		$this->assertWPError( $r );
		$this->assertSame( 'korkwand_upload', $r->get_error_code() );
		$this->assertSame( 400, $r->get_error_data()['status'] );
	}

	public function test_insert_failure_removes_moved_file() {
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		$before_att   = $this->attachment_count();
		$before_files = $this->upload_files();
		$r            = Upload::handle( $this->file_from( DIR_TESTDATA . '/images/test-image.png', 'ok.png', 'image/png' ), $this->user );
		remove_filter( 'wp_insert_post_empty_content', '__return_true' );
		$this->assertWPError( $r );
		$this->assertSame( 'korkwand_upload', $r->get_error_code() );
		$this->assertSame( 400, $r->get_error_data()['status'] );
		$this->assertSame( $before_att, $this->attachment_count() );
		$this->assertSame( $before_files, $this->upload_files(), 'Verschobene Datei wurde geloescht' );
	}

	public function test_ini_and_form_size_errors_map_to_too_large_message() {
		foreach ( array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ) as $code ) {
			$file          = $this->file_from( DIR_TESTDATA . '/images/test-image.png', 'x.png', 'image/png' );
			$file['error'] = $code;
			$r             = Upload::handle( $file, $this->user );
			$this->assertWPError( $r );
			$this->assertSame( 'Das Bild ist zu groß (maximal 8 MB).', $r->get_error_message() );
		}
		$file          = $this->file_from( DIR_TESTDATA . '/images/test-image.png', 'x.png', 'image/png' );
		$file['error'] = UPLOAD_ERR_PARTIAL;
		$this->assertStringContainsString( 'fehlgeschlagen', Upload::handle( $file, $this->user )->get_error_message() );
	}

	public function test_effective_limit_is_min_of_8mb_and_host_limit() {
		$this->assertSame( min( 8388608, wp_max_upload_size() ), Upload::max_bytes() );
		$low = static fn() => 2097152;
		add_filter( 'upload_size_limit', $low );
		$this->assertSame( 2097152, Upload::max_bytes() );
		$file         = $this->file_from( DIR_TESTDATA . '/images/test-image.png', 'x.png', 'image/png' );
		$file['size'] = 3000000;
		$r            = Upload::handle( $file, $this->user );
		remove_filter( 'upload_size_limit', $low );
		$this->assertWPError( $r );
		$this->assertSame( 'Das Bild ist zu groß (maximal 2 MB).', $r->get_error_message() );
		add_filter( 'upload_size_limit', static fn() => 1572864 );
		$this->assertSame( 'Das Bild ist zu groß (maximal ' . number_format_i18n( 1.5, 1 ) . ' MB).', Upload::too_large_error()->get_error_message() );
		remove_all_filters( 'upload_size_limit' );
		add_filter( 'upload_size_limit', static fn() => 99999999999 );
		$this->assertSame( 8388608, Upload::max_bytes() );
		remove_all_filters( 'upload_size_limit' );
	}

	public function test_stored_file_and_title_do_not_reveal_original_name() {
		$file = $this->file_from( DIR_TESTDATA . '/images/test-image.png', 'Max Mustermann Urlaub 2026.png', 'image/png' );
		$id   = Upload::handle( $file, $this->user );
		$this->assertIsInt( $id );
		$this->assertMatchesRegularExpression( '/^korkwand-[A-Za-z0-9]{12}(-\d+)?\.png$/', basename( get_attached_file( $id ) ) );
		$this->assertSame( 'Pinnwand-Bild', get_post( $id )->post_title );
		$this->assertStringNotContainsString( 'Mustermann', wp_json_encode( get_post( $id ) ) . get_attached_file( $id ) . wp_json_encode( wp_get_attachment_metadata( $id ) ) );
		$this->cleanup( $id );
	}

	public function test_jpeg_gets_jpg_extension_after_rename() {
		$id = Upload::handle( $this->file_from( DIR_TESTDATA . '/images/test-image.jpg', 'IMG_0001.JPEG', 'image/jpeg' ), $this->user );
		$this->assertIsInt( $id );
		$this->assertMatchesRegularExpression( '/^korkwand-[A-Za-z0-9]{12}\.jpg$/', basename( get_attached_file( $id ) ) );
		$this->cleanup( $id );
	}

	public function test_plugin_upload_attachment_page_answers_404() {
		$id = Upload::handle( $this->file_from( DIR_TESTDATA . '/images/test-image.png', 'a.png', 'image/png' ), $this->user );
		$this->assertTrue( has_action( 'template_redirect', array( Upload::class, 'block_attachment_page' ) ) !== false );
		$this->go_to( get_attachment_link( $id ) );
		$this->assertTrue( is_attachment() );
		Upload::block_attachment_page();
		$this->assertTrue( is_404() );
		$this->cleanup( $id );
	}

	public function test_library_attachment_page_is_left_alone() {
		$att = self::factory()->attachment->create_object( array( 'file' => 'lib.jpg', 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ) );
		$this->go_to( get_attachment_link( $att ) );
		Upload::block_attachment_page();
		$this->assertFalse( is_404() );
	}
}
