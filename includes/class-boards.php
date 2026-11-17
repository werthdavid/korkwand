<?php
namespace NZ\Pinnwand;

defined( 'ABSPATH' ) || exit;

/**
 * Zugriff auf Pinnwände (Terms der Taxonomie `pinboard`) und deren Einstellungen.
 */
final class Boards {

	private const DEFAULTS = array(
		'lifetime_days' => 100,
		'height'        => 700,
		'max_pins'      => 60,
		'notify'        => 0,
	);

	private const LIMITS = array(
		'lifetime_days' => array( 0, 3650 ),
		'height'        => array( 300, 3000 ),
		'max_pins'      => array( 1, 200 ),
	);

	public static function get( string $slug ): ?\WP_Term {
		if ( '' === $slug ) {
			return null;
		}
		$term = get_term_by( 'slug', $slug, 'pinboard' );
		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * @return array{lifetime_days:int,height:int,max_pins:int,notify:bool}
	 */
	public static function settings( \WP_Term $term ): array {
		$out = array();
		foreach ( self::LIMITS as $key => $limits ) {
			$raw         = get_term_meta( $term->term_id, $key, true );
			$value       = ( '' === $raw || false === $raw ) ? self::DEFAULTS[ $key ] : (int) $raw;
			$out[ $key ] = self::clamp( $value, $limits[0], $limits[1] );
		}
		$raw           = get_term_meta( $term->term_id, 'notify', true );
		$out['notify'] = '' === $raw ? (bool) self::DEFAULTS['notify'] : (bool) (int) $raw;

		return $out;
	}

	/**
	 * Speichert bereinigte und geklemmte Einstellungen; fehlende Schlüssel bleiben unverändert.
	 *
	 * @param int                  $term_id Term-ID.
	 * @param array<string, mixed> $raw     Rohdaten (z. B. $_POST).
	 */
	public static function save_settings( int $term_id, array $raw ): void {
		foreach ( self::LIMITS as $key => $limits ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			$value = self::clamp( (int) $raw[ $key ], $limits[0], $limits[1] );
			update_term_meta( $term_id, $key, $value );
		}
		if ( array_key_exists( 'notify', $raw ) ) {
			update_term_meta( $term_id, 'notify', rest_sanitize_boolean( $raw['notify'] ) ? 1 : 0 );
		}
	}

	private static function clamp( int $value, int $min, int $max ): int {
		return max( $min, min( $max, $value ) );
	}
}
