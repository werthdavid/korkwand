<?php
namespace NZ\Pinnwand;

defined( 'ABSPATH' ) || exit;

/**
 * REST-API `pinnwand/v1`. Hier liegt die Autorisierungsgrenze: jede Schreiboperation wird geprueft.
 * Authentifizierung: WordPress-Cookie + X-WP-Nonce (ohne gueltige Nonce gilt der Nutzer als Gast).
 */
final class Rest {

	public const NS = 'pinnwand/v1';

	public static function register(): void {
		$slug = '(?P<slug>[a-z0-9_%-]+)';

		register_rest_route(
			self::NS,
			'/boards/' . $slug . '/pins',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_pins' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'create_pin' ),
					'permission_callback' => array( self::class, 'can_create' ),
					'args'                => array(
						'type'  => array(
							'type'     => 'string',
							'enum'     => array( 'postit', 'image' ),
						),
						'title' => array( 'type' => 'string' ),
						'text'  => array( 'type' => 'string' ),
						'x'     => array( 'type' => 'integer' ),
						'y'     => array( 'type' => 'integer' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/pins/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'update_pin' ),
					'permission_callback' => array( self::class, 'can_modify' ),
					'args'                => array(
						'title'  => array( 'type' => 'string' ),
						'text'   => array( 'type' => 'string' ),
						'x'      => array( 'type' => 'integer' ),
						'y'      => array( 'type' => 'integer' ),
						'sticky' => array( 'type' => 'boolean' ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'delete_pin' ),
					'permission_callback' => array( self::class, 'can_modify' ),
				),
			)
		);
	}

	/* ---------------------------- Permissions --------------------------- */

	/** @return true|\WP_Error */
	public static function can_create() {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return self::error( 'nzp_unauthorized', __( 'Bitte melde dich an.', 'nz-pinnwand' ), 401 );
		}
		if ( ! Permissions::can_post( $uid ) ) {
			return self::error( 'nzp_forbidden', __( 'Du darfst keine Zettel anlegen.', 'nz-pinnwand' ), 403 );
		}
		return true;
	}

	/** @return true|\WP_Error */
	public static function can_modify( \WP_REST_Request $request ) {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return self::error( 'nzp_unauthorized', __( 'Bitte melde dich an.', 'nz-pinnwand' ), 401 );
		}
		$pin = self::find_pin( (int) $request['id'] );
		if ( is_wp_error( $pin ) ) {
			return $pin;
		}
		if ( ! Permissions::can_edit( $uid, $pin ) ) {
			return self::error( 'nzp_forbidden', __( 'Du darfst diesen Zettel nicht ändern.', 'nz-pinnwand' ), 403 );
		}
		return true;
	}

	/* ----------------------------- Callbacks ---------------------------- */

	/** @return \WP_REST_Response|\WP_Error */
	public static function list_pins( \WP_REST_Request $request ) {
		$board = Boards::get( (string) $request['slug'] );
		if ( ! $board ) {
			return self::not_found();
		}
		$viewer = get_current_user_id();
		$pins   = array();
		foreach ( Pins::visible( $board ) as $pin ) {
			$pins[] = Pins::to_array( $pin, $viewer );
		}
		return new \WP_REST_Response(
			array(
				'board' => array(
					'slug'   => $board->slug,
					'height' => Boards::settings( $board )['height'],
				),
				'pins'  => $pins,
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function create_pin( \WP_REST_Request $request ) {
		$uid   = get_current_user_id();
		$board = Boards::get( (string) $request['slug'] );
		if ( ! $board ) {
			return self::not_found();
		}
		// `type` ist bewusst nicht "required": Wird der Body vom Host verworfen (post_max_size), meldet
		// WordPress sonst nur "Missing parameter(s): type".
		$type = (string) $request['type'];
		if ( ! in_array( $type, array( 'postit', 'image' ), true ) ) {
			if ( self::body_dropped( $request ) ) {
				return Upload::too_large_error();
			}
			return self::error( 'nzp_invalid', __( 'Ungültiger Zettel-Typ.', 'nz-pinnwand' ), 400 );
		}
		if ( ! RateLimit::hit( $uid ) ) {
			return self::error( 'nzp_rate_limited', __( 'Zu viele neue Zettel – bitte später erneut versuchen.', 'nz-pinnwand' ), 429 );
		}

		// attachment_id wird bewusst nie vom Client uebernommen.
		$data = array(
			'type' => (string) $request['type'],
			'text' => $request['text'] ?? '',
		);
		foreach ( array( 'title', 'x', 'y' ) as $key ) {
			if ( null !== $request[ $key ] ) {
				$data[ $key ] = $request[ $key ];
			}
		}

		$attachment_id = 0;
		if ( 'image' === $data['type'] ) {
			$files = $request->get_file_params();
			$file  = $files['image'] ?? null;
			if ( is_array( $file ) && isset( $file['error'] ) && ! in_array( (int) $file['error'], array( UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE ), true ) ) {
				return Upload::upload_error( (int) $file['error'] );
			}
			if ( ! is_array( $file ) || ! isset( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || '' === $file['tmp_name'] ) {
				return self::error( 'nzp_invalid', __( 'Ein Bild ist erforderlich.', 'nz-pinnwand' ), 400 );
			}
			if ( ! self::is_uploaded_file( $file ) ) {
				return self::error( 'nzp_upload', __( 'Es wurde keine Datei hochgeladen.', 'nz-pinnwand' ), 400 );
			}
			$attachment_id = Upload::handle( $file, $uid );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}
			$data['attachment_id'] = $attachment_id;
		}

		$pin = Pins::create( $board, $uid, $data );
		if ( is_wp_error( $pin ) ) {
			if ( $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
			return $pin;
		}

		if ( Boards::settings( $board )['notify'] ) {
			Notify::new_pin( $pin );
		}

		$response = new \WP_REST_Response( Pins::to_array( $pin, $uid ), 201 );
		return $response;
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function update_pin( \WP_REST_Request $request ) {
		$pin = self::find_pin( (int) $request['id'] );
		if ( is_wp_error( $pin ) ) {
			return $pin;
		}
		$uid  = get_current_user_id();
		$data = array();
		foreach ( array( 'title', 'text', 'x', 'y', 'sticky' ) as $key ) {
			if ( null !== $request[ $key ] ) {
				$data[ $key ] = $request[ $key ];
			}
		}
		$updated = Pins::update( $pin, $data, $uid );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		return new \WP_REST_Response( Pins::to_array( $updated, $uid ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function delete_pin( \WP_REST_Request $request ) {
		$pin = self::find_pin( (int) $request['id'] );
		if ( is_wp_error( $pin ) ) {
			return $pin;
		}
		if ( ! Pins::trash( $pin ) ) {
			return self::error( 'nzp_error', __( 'Der Zettel konnte nicht gelöscht werden.', 'nz-pinnwand' ), 500 );
		}
		return new \WP_REST_Response( array( 'deleted' => true ) );
	}

	/* ------------------------------ Helpers ----------------------------- */

	/**
	 * Nur veroeffentlichte Zettel mit existierender Pinnwand sind ansprechbar.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private static function find_pin( int $id ) {
		$pin = $id > 0 ? get_post( $id ) : null;
		if ( ! $pin instanceof \WP_Post || 'pin' !== $pin->post_type || 'publish' !== $pin->post_status ) {
			return self::not_found();
		}
		$terms = wp_get_object_terms( $pin->ID, 'pinboard' );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return self::not_found();
		}
		return $pin;
	}

	/**
	 * Prueft, ob die Datei ein echter HTTP-Upload ist (verhindert, dass beliebige Serverpfade
	 * als tmp_name verarbeitet werden). Filter `nzp_is_uploaded_file` (Standard: is_uploaded_file)
	 * existiert nur als Test-Naht, da PHPUnit keine echten Uploads erzeugen kann; in Produktion
	 * nicht ueberschreiben.
	 *
	 * @param array<string,mixed> $file
	 */
	private static function is_uploaded_file( array $file ): bool {
		return (bool) apply_filters( 'nzp_is_uploaded_file', is_uploaded_file( (string) $file['tmp_name'] ), $file );
	}

	/**
	 * True, wenn ein multipart-Request Inhalt angekuendigt hat, PHP ihn aber verworfen hat
	 * (post_max_size ueberschritten: $_POST und $_FILES sind leer).
	 */
	private static function body_dropped( \WP_REST_Request $request ): bool {
		$content_type = (string) $request->get_header( 'content_type' );
		return 0 === stripos( $content_type, 'multipart/form-data' )
			&& (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 0 // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			&& empty( $request->get_body_params() )
			&& empty( $request->get_file_params() );
	}

	private static function not_found(): \WP_Error {
		return self::error( 'nzp_not_found', __( 'Nicht gefunden.', 'nz-pinnwand' ), 404 );
	}

	private static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
