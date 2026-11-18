<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

/**
 * Admin area: list columns, board settings, plugin settings.
 */
final class Admin {

	private const NONCE_ACTION = 'korkwand_board_settings';
	private const NONCE_FIELD  = 'korkwand_board_nonce';
	private const OPTION       = 'korkwand_delete_data_on_uninstall';

	public static function register(): void {
		add_filter( 'manage_korkwand_pin_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_korkwand_pin_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'manage_edit-korkwand_board_columns', array( self::class, 'board_columns' ) );
		add_filter( 'manage_korkwand_board_custom_column', array( self::class, 'board_column' ), 10, 3 );
		add_action( 'korkwand_board_add_form_fields', array( self::class, 'add_fields' ) );
		add_action( 'korkwand_board_edit_form_fields', array( self::class, 'edit_fields' ) );
		add_action( 'created_korkwand_board', array( self::class, 'save_term' ) );
		add_action( 'edited_korkwand_board', array( self::class, 'save_term' ) );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
	}

	public static function register_settings(): void {
		register_setting(
			'korkwand_settings',
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
		$cols['korkwand_type']    = __( 'Type', 'korkwand' );
		$cols['korkwand_sticky']  = __( 'Sticky', 'korkwand' );
		$cols['korkwand_expires'] = __( 'Expires on', 'korkwand' );
		return $cols;
	}

	public static function column( string $col, int $post_id ): void {
		$pin = get_post( $post_id );
		if ( ! $pin instanceof \WP_Post ) {
			return;
		}
		switch ( $col ) {
			case 'korkwand_type':
				echo 'image' === get_post_meta( $post_id, '_korkwand_type', true )
					? esc_html__( 'Image', 'korkwand' )
					: esc_html__( 'Post-it', 'korkwand' );
				break;
			case 'korkwand_sticky':
				echo (int) get_post_meta( $post_id, '_korkwand_sticky', true )
					? esc_html__( 'Yes', 'korkwand' )
					: '–';
				break;
			case 'korkwand_expires':
				$terms = wp_get_object_terms( $post_id, 'korkwand_board' );
				if ( is_wp_error( $terms ) || ! $terms || ! $terms[0] instanceof \WP_Term ) {
					echo '–';
					break;
				}
				$exp = Pins::expires_at( $pin, $terms[0] );
				echo $exp
					? esc_html( wp_date( get_option( 'date_format' ), $exp->getTimestamp() ) )
					: esc_html__( 'never', 'korkwand' );
				break;
		}
	}

	/**
	 * @param array<string,string> $cols Spalten.
	 * @return array<string,string>
	 */
	public static function board_columns( array $cols ): array {
		$cols['korkwand_shortcode'] = __( 'Shortcode', 'korkwand' );
		return $cols;
	}

	public static function board_column( string $content, string $col, int $term_id ): string {
		if ( 'korkwand_shortcode' !== $col ) {
			return $content;
		}
		$term = get_term( $term_id, 'korkwand_board' );
		if ( ! $term instanceof \WP_Term ) {
			return $content;
		}
		return '<code>' . esc_html( '[korkwand board="' . $term->slug . '"]' ) . '</code>';
	}

	public static function add_fields( $taxonomy = '' ): void {
		$s = array(
			'lifetime_days' => 100,
			'height'        => 700,
			'max_pins'      => 60,
			'notify'        => false,
			'page_id'       => 0,
		);
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<div class="form-field">
			<label for="korkwand-lifetime"><?php esc_html_e( 'Lifetime in days', 'korkwand' ); ?></label>
			<input type="number" name="lifetime_days" id="korkwand-lifetime" min="0" max="3650" value="<?php echo esc_attr( (string) $s['lifetime_days'] ); ?>">
			<p><?php esc_html_e( '0 = notes never expire.', 'korkwand' ); ?></p>
		</div>
		<div class="form-field">
			<label for="korkwand-height"><?php esc_html_e( 'Height in pixels', 'korkwand' ); ?></label>
			<input type="number" name="height" id="korkwand-height" min="300" max="3000" value="<?php echo esc_attr( (string) $s['height'] ); ?>">
		</div>
		<div class="form-field">
			<label for="korkwand-max-pins"><?php esc_html_e( 'Maximum number of notes', 'korkwand' ); ?></label>
			<input type="number" name="max_pins" id="korkwand-max-pins" min="1" max="200" value="<?php echo esc_attr( (string) $s['max_pins'] ); ?>">
		</div>
		<div class="form-field">
			<label for="korkwand-page-id"><?php esc_html_e( 'Board page', 'korkwand' ); ?></label>
			<?php self::page_dropdown( (int) $s['page_id'] ); ?>
			<p><?php esc_html_e( 'Page that shows this board. Used for links from the latest notes list.', 'korkwand' ); ?></p>
		</div>
		<div class="form-field">
			<label for="korkwand-notify">
				<input type="checkbox" name="notify" id="korkwand-notify" value="1">
				<?php esc_html_e( 'Email on new note', 'korkwand' ); ?>
			</label>
		</div>
		<?php
	}

	public static function edit_fields( \WP_Term $term ): void {
		$s = Boards::settings( $term );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<tr class="form-field">
			<th scope="row"><label for="korkwand-lifetime"><?php esc_html_e( 'Lifetime in days', 'korkwand' ); ?></label></th>
			<td>
				<input type="number" name="lifetime_days" id="korkwand-lifetime" min="0" max="3650" value="<?php echo esc_attr( (string) $s['lifetime_days'] ); ?>">
				<p class="description"><?php esc_html_e( '0 = notes never expire.', 'korkwand' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="korkwand-height"><?php esc_html_e( 'Height in pixels', 'korkwand' ); ?></label></th>
			<td><input type="number" name="height" id="korkwand-height" min="300" max="3000" value="<?php echo esc_attr( (string) $s['height'] ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="korkwand-max-pins"><?php esc_html_e( 'Maximum number of notes', 'korkwand' ); ?></label></th>
			<td><input type="number" name="max_pins" id="korkwand-max-pins" min="1" max="200" value="<?php echo esc_attr( (string) $s['max_pins'] ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="korkwand-page-id"><?php esc_html_e( 'Board page', 'korkwand' ); ?></label></th>
			<td>
				<?php self::page_dropdown( (int) $s['page_id'] ); ?>
				<p class="description"><?php esc_html_e( 'Page that shows this board. Used for links from the latest notes list.', 'korkwand' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Email on new note', 'korkwand' ); ?></th>
			<td>
				<label for="korkwand-notify">
					<input type="checkbox" name="notify" id="korkwand-notify" value="1" <?php checked( $s['notify'] ); ?>>
					<?php esc_html_e( 'Send notification', 'korkwand' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	private static function page_dropdown( int $selected ): void {
		wp_dropdown_pages(
			array(
				'name'              => 'page_id',
				'id'                => 'korkwand-page-id',
				'selected'          => $selected,
				'show_option_none'  => __( '— automatic —', 'korkwand' ),
				'option_none_value' => '0',
			)
		);
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
		if ( ! $term instanceof \WP_Term || 'korkwand_board' !== $term->taxonomy ) {
			return;
		}
		$raw = array( 'notify' => '0' );
		foreach ( array( 'lifetime_days', 'height', 'max_pins', 'page_id', 'notify' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$raw[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		Boards::save_settings( $term_id, $raw );
	}

	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=korkwand_pin',
			__( 'Settings', 'korkwand' ),
			__( 'Settings', 'korkwand' ),
			'manage_options',
			'korkwand-settings',
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
			<h1><?php esc_html_e( 'Korkwand – Settings', 'korkwand' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'korkwand_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'korkwand' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>" value="0">
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>" value="1" <?php checked( '1', $value ); ?>>
								<?php esc_html_e( 'Remove all notes and images when the plugin is deleted', 'korkwand' ); ?>
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
