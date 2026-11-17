<?php
namespace NZ\Pinnwand;

defined( 'ABSPATH' ) || exit;

/**
 * Aufraeumen: Ablauf, Nutzerloeschung (DSGVO), Bild-Bereinigung, Deinstallation.
 */
final class Cleanup {

	private const BATCH = 100;

	public static function register(): void {
		add_action( 'nzp_daily_cleanup', array( self::class, 'trash_expired' ) );
		add_action( 'before_delete_post', array( self::class, 'delete_attachment' ) );
		add_action( 'delete_user', array( self::class, 'delete_user_pins' ) );
		add_action( 'wpmu_delete_user', array( self::class, 'delete_user_pins' ) );
	}

	/**
	 * Abgelaufene, nicht angeheftete Zettel in den Papierkorb. Rueckgabe: Anzahl.
	 */
	public static function trash_expired(): int {
		$boards = get_terms(
			array(
				'taxonomy'   => 'pinboard',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $boards ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $boards as $board ) {
			$days = Boards::settings( $board )['lifetime_days'];
			if ( $days <= 0 ) {
				continue;
			}
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			$failed = array();
			do {
				$ids = get_posts(
					array(
						'post_type'        => 'pin',
						'post_status'      => 'publish',
						'posts_per_page'   => self::BATCH,
						'fields'           => 'ids',
						'orderby'          => 'ID',
						'order'            => 'ASC',
						'post__not_in'     => $failed,
						'no_found_rows'    => true,
						'suppress_filters' => true,
						'tax_query'        => array(
							array(
								'taxonomy' => 'pinboard',
								'field'    => 'term_id',
								'terms'    => array( $board->term_id ),
							),
						),
						'date_query'       => array(
							array(
								'column' => 'post_date_gmt',
								'before' => $cutoff,
							),
						),
						'meta_query'       => array(
							'relation' => 'OR',
							array(
								'key'     => '_pin_sticky',
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'     => '_pin_sticky',
								'value'   => '1',
								'compare' => '!=',
							),
						),
					)
				);
				foreach ( $ids as $id ) {
					$pin = get_post( $id );
					if ( $pin && Pins::trash( $pin ) ) {
						++$count;
					} else {
						$failed[] = (int) $id; // Nicht endlos wiederholen.
					}
				}
			} while ( count( $ids ) >= self::BATCH );
		}
		return $count;
	}

	/**
	 * Beim endgueltigen Loeschen eines Zettels dessen Bild entfernen. Geloescht werden nur
	 * Attachments aus dem Plugin-Upload (Meta `_nzp_upload`), die sonst nirgends verwendet werden.
	 * Mediathek-Bilder bleiben unangetastet.
	 */
	public static function delete_attachment( $post_id ): void {
		$post_id = (int) $post_id;
		if ( 'pin' !== get_post_type( $post_id ) ) {
			return;
		}
		$thumb = (int) get_post_meta( $post_id, '_thumbnail_id', true );
		if ( $thumb <= 0 ) {
			return;
		}
		$att = get_post( $thumb );
		if ( ! $att || 'attachment' !== $att->post_type || ! get_post_meta( $thumb, '_nzp_upload', true ) ) {
			return;
		}
		$parent = (int) $att->post_parent;
		if ( 0 !== $parent && $parent !== $post_id ) {
			return;
		}
		// Anderweitig als Beitragsbild verwendet (beliebiger Typ, alle Stati inkl. Papierkorb)?
		$others = get_posts(
			array(
				'post_type'        => array_values( get_post_types() ),
				'post_status'      => array_merge( array_values( get_post_stati() ), array( 'trash' ) ),
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'post__not_in'     => array( $post_id ),
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array(
					array(
						'key'   => '_thumbnail_id',
						'value' => (string) $thumb,
					),
				),
			)
		);
		if ( $others ) {
			return;
		}
		wp_delete_attachment( $thumb, true );
	}

	/**
	 * Alle Zettel eines Nutzers (auch im Papierkorb) endgueltig loeschen, auch bei Reassign.
	 * Rueckgabe: Anzahl geloeschter Zettel.
	 */
	public static function delete_user_pins( $user_id ): int {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return 0;
		}
		$count = self::delete_batch( $user_id, 0 );
		delete_transient( 'nzp_rl_' . $user_id );
		return $count;
	}

	/**
	 * Loescht bis zu $limit Zettel (0 = alle) und gibt die Anzahl zurueck. Nicht loeschbare Zettel
	 * werden uebersprungen (nicht endlos wiederholt, blockieren aber auch nicht die nachfolgenden).
	 */
	public static function delete_batch( int $user_id, int $limit ): int {
		$count  = 0;
		$failed = array();
		do {
			$per = $limit > 0 ? min( $limit - $count, self::BATCH ) : self::BATCH;
			$ids = get_posts( self::user_pins_query( $user_id, $per ) + array( 'post__not_in' => $failed ) );
			foreach ( $ids as $id ) {
				if ( wp_delete_post( (int) $id, true ) ) {
					++$count;
				} else {
					$failed[] = (int) $id;
				}
			}
		} while ( count( $ids ) >= $per && ( 0 === $limit || $count < $limit ) );
		return $count;
	}

	/** True, wenn der Nutzer noch Zettel (beliebiger Status, auch Papierkorb) besitzt. */
	public static function user_has_pins( int $user_id ): bool {
		return (bool) get_posts( self::user_pins_query( $user_id, 1 ) );
	}

	/** @return array<string,mixed> */
	private static function user_pins_query( int $user_id, int $per ): array {
		return array(
			'post_type'        => 'pin',
			'post_status'      => array_merge( array_values( get_post_stati() ), array( 'trash' ) ),
			'author'           => $user_id,
			'posts_per_page'   => $per,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		);
	}

	/**
	 * Deinstallation (aus uninstall.php).
	 */
	public static function uninstall(): void {
		global $wpdb;

		$purge = get_option( 'nzp_delete_data_on_uninstall', '1' );

		if ( '1' === (string) $purge ) {
			// Hooks sind bei der Deinstallation nicht registriert: Bild-Bereinigung sicherstellen.
			if ( ! has_action( 'before_delete_post', array( self::class, 'delete_attachment' ) ) ) {
				add_action( 'before_delete_post', array( self::class, 'delete_attachment' ) );
			}
			if ( ! post_type_exists( 'pin' ) || ! taxonomy_exists( 'pinboard' ) ) {
				Model::register();
			}
			$failed = array();
			do {
				$ids = get_posts(
					array(
						'post_type'        => 'pin',
						'post_status'      => array_merge( array_values( get_post_stati() ), array( 'trash' ) ),
						'posts_per_page'   => self::BATCH,
						'fields'           => 'ids',
						'orderby'          => 'ID',
						'order'            => 'ASC',
						'post__not_in'     => $failed,
						'no_found_rows'    => true,
						'suppress_filters' => true,
					)
				);
				foreach ( $ids as $id ) {
					if ( ! wp_delete_post( (int) $id, true ) ) {
						$failed[] = (int) $id;
					}
				}
			} while ( count( $ids ) >= self::BATCH );

			$terms = get_terms(
				array(
					'taxonomy'   => 'pinboard',
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term_id ) {
					wp_delete_term( (int) $term_id, 'pinboard' );
				}
			}
		}

		Permissions::remove_caps();
		wp_clear_scheduled_hook( 'nzp_daily_cleanup' );
		delete_option( 'nzp_delete_data_on_uninstall' );
		delete_option( 'nzp_version' );

		// Rate-Limit-Transients (inkl. Timeout-Eintraege).
		$like = $wpdb->esc_like( '_transient_nzp_rl_' ) . '%';
		$tmo  = $wpdb->esc_like( '_transient_timeout_nzp_rl_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $tmo ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_flush();
	}
}
