<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

/**
 * Rate limit for new notes: max. 10 per user in a sliding 3600 s window.
 * State: transient `korkwand_rl_{user_id}` with the timestamps of the last hour.
 * Note: reading/writing is not atomic; with exactly simultaneous requests the limit may
 * be slightly exceeded (accepted for this purpose).
 */
final class RateLimit {

	public const MAX    = 10;
	public const WINDOW = 3600;

	/**
	 * Registers an attempt. false if the limit has already been reached (the attempt is then not counted).
	 */
	public static function hit( int $user_id ): bool {
		$key = 'korkwand_rl_' . $user_id;
		$now = time();
		$raw = get_transient( $key );
		$log = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $ts ) {
				if ( is_int( $ts ) && $ts > $now - self::WINDOW ) {
					$log[] = $ts;
				}
			}
		}
		if ( count( $log ) >= self::MAX ) {
			return false;
		}
		$log[] = $now;
		set_transient( $key, $log, self::WINDOW );
		return true;
	}
}
