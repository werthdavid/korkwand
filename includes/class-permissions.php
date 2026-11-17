<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

final class Permissions {

	private const POST_ROLES = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );

	private const MOD_ROLES = array( 'editor', 'administrator' );

	private const MOD_CAPS = array(
		'korkwand_moderate',
		'edit_korkwand_pins',
		'edit_others_korkwand_pins',
		'edit_published_korkwand_pins',
		'edit_private_korkwand_pins',
		'publish_korkwand_pins',
		'read_private_korkwand_pins',
		'delete_korkwand_pins',
		'delete_others_korkwand_pins',
		'delete_published_korkwand_pins',
		'delete_private_korkwand_pins',
	);

	public static function install_caps(): void {
		foreach ( self::POST_ROLES as $name ) {
			$role = get_role( $name );
			if ( $role ) {
				$role->add_cap( 'korkwand_post' );
			}
		}
		foreach ( self::MOD_ROLES as $name ) {
			$role = get_role( $name );
			if ( $role ) {
				foreach ( self::MOD_CAPS as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	public static function remove_caps(): void {
		foreach ( array_unique( array_merge( self::POST_ROLES, self::MOD_ROLES ) ) as $name ) {
			$role = get_role( $name );
			if ( ! $role ) {
				continue;
			}
			$role->remove_cap( 'korkwand_post' );
			foreach ( self::MOD_CAPS as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	public static function can_post( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'korkwand_post' );
	}

	public static function can_moderate( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'korkwand_moderate' );
	}

	public static function can_edit( int $user_id, \WP_Post $pin ): bool {
		if ( $user_id <= 0 || 'korkwand_pin' !== $pin->post_type ) {
			return false;
		}
		if ( self::can_moderate( $user_id ) ) {
			return true;
		}
		return (int) $pin->post_author === $user_id && self::can_post( $user_id );
	}
}
