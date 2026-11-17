<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

/**
 * Rate-Limit fuer neue Zettel: max. 10 pro Nutzer in einem gleitenden 3600-s-Fenster.
 * Zustand: Transient `korkwand_rl_{user_id}` mit den Zeitstempeln der letzten Stunde.
 * Hinweis: Lesen/Schreiben ist nicht atomar; bei exakt gleichzeitigen Requests kann das Limit
 * knapp ueberschritten werden (fuer diesen Zweck akzeptiert).
 */
final class RateLimit {

	public const MAX    = 10;
	public const WINDOW = 3600;

	/**
	 * Registriert einen Versuch. false, wenn das Limit bereits erreicht ist (Versuch wird dann nicht gezaehlt).
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
