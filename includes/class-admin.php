<?php
namespace NZ\Pinnwand;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-Bereich: Listenspalten, Pinnwand-Einstellungen, Plugin-Einstellungen.
 */
final class Admin {

	private const NONCE_ACTION = 'nzp_board_settings';
	private const NONCE_FIELD  = 'nzp_board_nonce';
	private const OPTION       = 'nzp_delete_data_on_uninstall';

	public static function register(): void {
		add_filter( 'manage_pin_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_pin_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'manage_edit-pinboard_columns', array( self::class, 'board_columns' ) );
		add_filter( 'manage_pinboard_custom_column', array( self::class, 'board_column' ), 10, 3 );
		add_action( 'pinboard_add_form_fields', array( self::class, 'add_fields' ) );
		add_action( 'pinboard_edit_form_fields', array( self::class, 'edit_fields' ) );
		add_action( 'created_pinboard', array( self::class, 'save_term' ) );
		add_action( 'edited_pinboard', array( self::class, 'save_term' ) );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
	}

	public static function register_settings(): void {
		register_setting(
			'nzp_settings',
			self::OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => static fn( $v ) => '1' === $v ? '1' : '0',
				'default'           => '1',
			)
		);
	}

	/**
	 * @param array<string,string> $cols Spalten.
	 * @return array<string,string>
	 */
	public static function columns( array $cols ): array {
		$cols['nzp_type']    = __( 'Typ', 'nz-pinnwand' );
		$cols['nzp_sticky']  = __( 'Sticky', 'nz-pinnwand' );
		$cols['nzp_expires'] = __( 'Läuft ab am', 'nz-pinnwand' );
		return $cols;
	}

	public static function column( string $col, int $post_id ): void {
		$pin = get_post( $post_id );
		if ( ! $pin instanceof \WP_Post ) {
			return;
		}
		switch ( $col ) {
			case 'nzp_type':
				echo 'image' === get_post_meta( $post_id, '_pin_type', true )
					? esc_html__( 'Bild', 'nz-pinnwand' )
					: esc_html__( 'Post-it', 'nz-pinnwand' );
				break;
			case 'nzp_sticky':
				echo (int) get_post_meta( $post_id, '_pin_sticky', true )
					? esc_html__( 'Ja', 'nz-pinnwand' )
					: '–';
				break;
			case 'nzp_expires':
				$terms = wp_get_object_terms( $post_id, 'pinboard' );
				if ( is_wp_error( $terms ) || ! $terms || ! $terms[0] instanceof \WP_Term ) {
					echo '–';
					break;
				}
				$exp = Pins::expires_at( $pin, $terms[0] );
				echo $exp
					? esc_html( wp_date( get_option( 'date_format' ), $exp->getTimestamp() ) )
					: esc_html__( 'nie', 'nz-pinnwand' );
				break;
		}
	}

	/**
	 * @param array<string,string> $cols Spalten.
	 * @return array<string,string>
	 */
	public static function board_columns( array $cols ): array {
		$cols['nzp_shortcode'] = __( 'Shortcode', 'nz-pinnwand' );
		return $cols;
	}

	public static function board_column( string $content, string $col, int $term_id ): string {
		if ( 'nzp_shortcode' !== $col ) {
			return $content;
		}
		$term = get_term( $term_id, 'pinboard' );
		if ( ! $term instanceof \WP_Term ) {
			return $content;
		}
		return '<code>' . esc_html( '[pinnwand board="' . $term->slug . '"]' ) . '</code>';
	}

	public static function add_fields( $taxonomy = '' ): void {
		$s = array(
			'lifetime_days' => 100,
			'height'        => 700,
			'max_pins'      => 60,
			'notify'        => false,
		);
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<div class="form-field">
			<label for="nzp-lifetime"><?php esc_html_e( 'Laufzeit in Tagen', 'nz-pinnwand' ); ?></label>
			<input type="number" name="lifetime_days" id="nzp-lifetime" min="0" max="3650" value="<?php echo esc_attr( (string) $s['lifetime_days'] ); ?>">
			<p><?php esc_html_e( '0 = Zettel laufen nie ab.', 'nz-pinnwand' ); ?></p>
		</div>
		<div class="form-field">
			<label for="nzp-height"><?php esc_html_e( 'Höhe in Pixel', 'nz-pinnwand' ); ?></label>
			<input type="number" name="height" id="nzp-height" min="300" max="3000" value="<?php echo esc_attr( (string) $s['height'] ); ?>">
		</div>
		<div class="form-field">
			<label for="nzp-max-pins"><?php esc_html_e( 'Maximale Anzahl Zettel', 'nz-pinnwand' ); ?></label>
			<input type="number" name="max_pins" id="nzp-max-pins" min="1" max="200" value="<?php echo esc_attr( (string) $s['max_pins'] ); ?>">
		</div>
		<div class="form-field">
			<label for="nzp-notify">
				<input type="checkbox" name="notify" id="nzp-notify" value="1">
				<?php esc_html_e( 'E-Mail bei neuem Zettel', 'nz-pinnwand' ); ?>
			</label>
		</div>
		<?php
	}

	public static function edit_fields( \WP_Term $term ): void {
		$s = Boards::settings( $term );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<tr class="form-field">
			<th scope="row"><label for="nzp-lifetime"><?php esc_html_e( 'Laufzeit in Tagen', 'nz-pinnwand' ); ?></label></th>
			<td>
				<input type="number" name="lifetime_days" id="nzp-lifetime" min="0" max="3650" value="<?php echo esc_attr( (string) $s['lifetime_days'] ); ?>">
				<p class="description"><?php esc_html_e( '0 = Zettel laufen nie ab.', 'nz-pinnwand' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="nzp-height"><?php esc_html_e( 'Höhe in Pixel', 'nz-pinnwand' ); ?></label></th>
			<td><input type="number" name="height" id="nzp-height" min="300" max="3000" value="<?php echo esc_attr( (string) $s['height'] ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="nzp-max-pins"><?php esc_html_e( 'Maximale Anzahl Zettel', 'nz-pinnwand' ); ?></label></th>
			<td><input type="number" name="max_pins" id="nzp-max-pins" min="1" max="200" value="<?php echo esc_attr( (string) $s['max_pins'] ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'E-Mail bei neuem Zettel', 'nz-pinnwand' ); ?></th>
			<td>
				<label for="nzp-notify">
					<input type="checkbox" name="notify" id="nzp-notify" value="1" <?php checked( $s['notify'] ); ?>>
					<?php esc_html_e( 'Benachrichtigung senden', 'nz-pinnwand' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	public static function save_term( $term_id ): void {
		$term_id = (int) $term_id;
		$nonce   = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term || 'pinboard' !== $term->taxonomy ) {
			return;
		}
		$raw = array( 'notify' => '0' );
		foreach ( array( 'lifetime_days', 'height', 'max_pins', 'notify' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$raw[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		Boards::save_settings( $term_id, $raw );
	}

	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=pin',
			__( 'Einstellungen', 'nz-pinnwand' ),
			__( 'Einstellungen', 'nz-pinnwand' ),
			'manage_options',
			'nzp-settings',
			array( self::class, 'settings_page' )
		);
	}

	public static function settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$value = get_option( self::OPTION, '1' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pinnwand – Einstellungen', 'nz-pinnwand' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'nzp_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Deinstallation', 'nz-pinnwand' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>" value="0">
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>" value="1" <?php checked( '1', $value ); ?>>
								<?php esc_html_e( 'Beim Löschen des Plugins alle Zettel und Bilder entfernen', 'nz-pinnwand' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
