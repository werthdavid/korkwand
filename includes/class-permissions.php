<?php
namespace Korkwand;

defined( 'ABSPATH' ) || exit;

final class Permissions {

	/** Dedicated role that may post but not moderate. */
	public const ROLE = 'korkwand_editor';

	private const POST_ROLES = array( self::ROLE, 'editor', 'administrator' );

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

	/**
	 * Installs the "Board editor" role (read + korkwand_post only, deliberately no upload_files:
	 * uploads go through the plugin's REST endpoint) and the capabilities. Runs on activation only.
	 */
	public static function install_caps(): void {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, __( 'Board editor', 'korkwand' ), array( 'read' => true ) );
		}
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

	/**
	 * Uninstall: users with the Board editor role become subscribers, then the role and all
	 * capabilities of the plugin are removed.
	 */
	public static function remove_caps(): void {
		if ( get_role( self::ROLE ) ) {
			do {
				$user_ids = get_users(
					array(
						'role'    => self::ROLE,
						'fields'  => 'ID',
						'number'  => 500,
						'orderby' => 'ID',
					)
				);
				foreach ( $user_ids as $user_id ) {
					$user = get_userdata( (int) $user_id );
					if ( $user ) {
						// Keep any other roles; a user left without a role becomes a subscriber.
						$user->remove_role( self::ROLE );
						if ( ! $user->roles ) {
							$user->add_role( 'subscriber' );
						}
					}
				}
			} while ( count( $user_ids ) >= 500 ); // Demoted users drop out of the role query, so the next pass gets the next batch.
			remove_role( self::ROLE );
		}
		// All roles, not just the ones that get the capabilities: custom roles may have been granted them by hand.
		foreach ( wp_roles()->role_objects as $role ) {
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
