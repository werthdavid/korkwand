<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

/**
 * Cleanup: expiry, user deletion (GDPR), image cleanup, uninstall.
 */
final class Cleanup {

	private const BATCH = 100;

	public static function register(): void {
		add_action( 'korkwand_daily_cleanup', array( self::class, 'daily' ) );
		add_action( 'pre_delete_term', array( self::class, 'delete_board_pins' ), 10, 2 );
		add_action( 'before_delete_post', array( self::class, 'delete_attachment' ) );
		add_action( 'delete_user', array( self::class, 'delete_user_pins' ) );
		add_action( 'wpmu_delete_user', array( self::class, 'delete_user_pins' ) );
	}

	/** Daily job: trash expired notes, then remove notes that belong to no board. */
	public static function daily(): void {
		self::trash_expired();
		self::delete_orphans();
	}

	/**
	 * Before a board is deleted (term relationships still exist), permanently delete all of its notes
	 * including trashed ones. Images follow through the before_delete_post guard.
	 *
	 * @param int|string $term_id  Term ID.
	 * @param string     $taxonomy Taxonomy.
	 */
	public static function delete_board_pins( $term_id, $taxonomy ): int {
		if ( 'korkwand_board' !== $taxonomy ) {
			return 0;
		}
		return self::delete_matching(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- board scoping needs the board taxonomy; runs only when a board is deleted.
				'tax_query' => array(
					array(
						'taxonomy' => 'korkwand_board',
						'field'    => 'term_id',
						'terms'    => array( (int) $term_id ),
					),
				),
			)
		);
	}

	/** Safety net: permanently deletes notes that have no board at all (any status). */
	public static function delete_orphans(): int {
		return self::delete_matching(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- finds notes without any board; daily job, capped batches.
				'tax_query' => array(
					array(
						'taxonomy' => 'korkwand_board',
						'operator' => 'NOT EXISTS',
					),
				),
			)
		);
	}

	/**
	 * Permanently deletes all notes (any status) matching extra query args, in batches.
	 *
	 * @param array<string,mixed> $extra Extra get_posts() arguments.
	 */
	private static function delete_matching( array $extra ): int {
		$count  = 0;
		$failed = array();
		do {
			// get_posts() defaults to suppress_filters=true.
			$ids = get_posts(
				$extra + array(
					'post_type'      => 'korkwand_pin',
					'post_status'    => array_merge( array_values( get_post_stati() ), array( 'trash' ) ),
					'posts_per_page' => self::BATCH,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- bounded batch loop skips posts that failed to delete, so it cannot spin forever.
					'post__not_in'   => $failed,
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				if ( wp_delete_post( (int) $id, true ) ) {
					++$count;
				} else {
					$failed[] = (int) $id;
				}
			}
		} while ( count( $ids ) >= self::BATCH );
		return $count;
	}

	/**
	 * Moves expired, non-pinned notes to the trash. Returns the number moved.
	 */
	public static function trash_expired(): int {
		$boards = get_terms(
			array(
				'taxonomy'   => 'korkwand_board',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $boards ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $boards as $board ) {
			$days = Boards::settings( $board )['lifetime_days'];
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			$failed = array();
			do {
				// get_posts() defaults to suppress_filters=true.
				$ids = get_posts(
					array(
						'post_type'        => 'korkwand_pin',
						'post_status'      => 'publish',
						'posts_per_page'   => self::BATCH,
						'fields'           => 'ids',
						'orderby'          => 'ID',
						'order'            => 'ASC',
						// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- bounded batch loop skips posts that failed to delete, so it cannot spin forever.
						'post__not_in'     => $failed,
						'no_found_rows'    => true,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- board scoping needs the board taxonomy; result sets are small and capped.
						'tax_query'        => array(
							array(
								'taxonomy' => 'korkwand_board',
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
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- sticky flag / featured image lookup is stored as post meta; result sets are small and capped.
						'meta_query'       => array(
							'relation' => 'OR',
							array(
								'key'     => '_korkwand_sticky',
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'     => '_korkwand_sticky',
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
						$failed[] = (int) $id; // Do not retry endlessly.
					}
				}
			} while ( count( $ids ) >= self::BATCH );
		}
		return $count;
	}

	/**
	 * When a note is permanently deleted, remove its image. Only attachments from the plugin upload
	 * (meta `_korkwand_upload`) that are not used anywhere else are deleted.
	 * Media library images are left untouched.
	 */
	public static function delete_attachment( $post_id ): void {
		$post_id = (int) $post_id;
		if ( 'korkwand_pin' !== get_post_type( $post_id ) ) {
			return;
		}
		$thumb = (int) get_post_meta( $post_id, '_thumbnail_id', true );
		if ( $thumb <= 0 ) {
			return;
		}
		$att = get_post( $thumb );
		if ( ! $att || 'attachment' !== $att->post_type || ! get_post_meta( $thumb, '_korkwand_upload', true ) ) {
			return;
		}
		$parent = (int) $att->post_parent;
		if ( 0 !== $parent && $parent !== $post_id ) {
			return;
		}
		// Used elsewhere as a featured image (any type, all statuses incl. trash)?
		// get_posts() defaults to suppress_filters=true.
		$others = get_posts(
			array(
				'post_type'        => array_values( get_post_types() ),
				'post_status'      => array_merge( array_values( get_post_stati() ), array( 'trash' ) ),
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- single-post exclusion, query is limited to one result.
				'post__not_in'     => array( $post_id ),
				'no_found_rows'    => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- sticky flag / featured image lookup is stored as post meta; result sets are small and capped.
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
	 * Permanently delete all notes of a user (including trashed ones), also on reassign.
	 * Returns the number of deleted notes.
	 */
	public static function delete_user_pins( $user_id ): int {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return 0;
		}
		$count = self::delete_batch( $user_id, 0 );
		delete_transient( 'korkwand_rl_' . $user_id );
		return $count;
	}

	/**
	 * Deletes up to $limit notes (0 = all) and returns the count. Notes that cannot be deleted
	 * are skipped (not retried endlessly, but they do not block the following ones either).
	 */
	public static function delete_batch( int $user_id, int $limit ): int {
		$count  = 0;
		$failed = array();
		do {
			$per = $limit > 0 ? min( $limit - $count, self::BATCH ) : self::BATCH;
			// get_posts() defaults to suppress_filters=true.
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- bounded batch loop skips posts that failed to delete, so it cannot spin forever.
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

	/** True if the user still owns notes (any status, including trash). */
	public static function user_has_pins( int $user_id ): bool {
		return (bool) get_posts( self::user_pins_query( $user_id, 1 ) );
	}

	/** @return array<string,mixed> */
	private static function user_pins_query( int $user_id, int $per ): array {
		return array(
			'post_type'        => 'korkwand_pin',
			'post_status'      => array_merge( array_values( get_post_stati() ), array( 'trash' ) ),
			'author'           => $user_id,
			'posts_per_page'   => $per,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
		);
	}

	/**
	 * Uninstall (called from uninstall.php).
	 */
	public static function uninstall(): void {
		$purge = get_option( 'korkwand_delete_data_on_uninstall', '1' );

		if ( '1' === (string) $purge ) {
			// Hooks are not registered during uninstall: make sure the image cleanup runs.
			if ( ! has_action( 'before_delete_post', array( self::class, 'delete_attachment' ) ) ) {
				add_action( 'before_delete_post', array( self::class, 'delete_attachment' ) );
			}
			if ( ! post_type_exists( 'korkwand_pin' ) || ! taxonomy_exists( 'korkwand_board' ) ) {
				Model::register();
			}
			$failed = array();
			do {
				// get_posts() defaults to suppress_filters=true.
				$ids = get_posts(
					array(
						'post_type'        => 'korkwand_pin',
						'post_status'      => array_merge( array_values( get_post_stati() ), array( 'trash' ) ),
						'posts_per_page'   => self::BATCH,
						'fields'           => 'ids',
						'orderby'          => 'ID',
						'order'            => 'ASC',
						// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- bounded batch loop skips posts that failed to delete, so it cannot spin forever.
						'post__not_in'     => $failed,
						'no_found_rows'    => true,
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
					'taxonomy'   => 'korkwand_board',
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term_id ) {
					wp_delete_term( (int) $term_id, 'korkwand_board' );
				}
			}
		}

		Permissions::remove_caps();
		wp_clear_scheduled_hook( 'korkwand_daily_cleanup' );
		delete_option( 'korkwand_delete_data_on_uninstall' );

		// Rate-limit transients are keyed by user ID: delete them per key (works with and without a
		// persistent object cache and needs no global cache flush). Users are read in pages of IDs.
		$page = 1;
		do {
			$user_ids = get_users(
				array(
					'fields'  => 'ID',
					'number'  => 500,
					'paged'   => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
			foreach ( $user_ids as $user_id ) {
				delete_transient( 'korkwand_rl_' . (int) $user_id );
			}
			++$page;
		} while ( count( $user_ids ) >= 500 );
	}
}
