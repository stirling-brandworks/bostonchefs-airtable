<?php
/**
 * Least-privilege WordPress user for the Airtable rundown sync.
 *
 * @package BostonChefs\Airtable
 */

namespace BostonChefs\Airtable;

class Integration_User {

	const ROLE       = 'bc_airtable_sync';
	const CAPABILITY = 'bc_sync_rundowns';
	const USERNAME   = 'airtable-sync';
	const OPTION     = 'bc_airtable_sync_user_ready';

	/**
	 * Register role maintenance and the application-password reminder.
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'prepare' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/**
	 * Create the role and user when the plugin is activated.
	 */
	public static function activate() {
		self::ensure_role();
		self::ensure_user();
	}

	/**
	 * Remove the sync role when the plugin is deactivated.
	 *
	 * The airtable-sync account is left in place.
	 */
	public static function deactivate() {
		remove_role( self::ROLE );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( self::CAPABILITY );
		}
	}

	/**
	 * Keep the role available and create the integration user once.
	 */
	public static function prepare() {
		self::ensure_role();

		if ( get_option( self::OPTION ) ) {
			return;
		}

		self::ensure_user();
	}

	/**
	 * Role with the single rundown-sync capability.
	 */
	public static function ensure_role() {
		$role = get_role( self::ROLE );

		if ( ! $role ) {
			add_role(
				self::ROLE,
				'Airtable Sync',
				array(
					self::CAPABILITY => true,
				)
			);
		} elseif ( ! $role->has_cap( self::CAPABILITY ) ) {
			$role->add_cap( self::CAPABILITY );
		}

		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAPABILITY ) ) {
			$admin->add_cap( self::CAPABILITY );
		}
	}

	/**
	 * Create airtable-sync when the account is missing.
	 *
	 * The account password is random and unused. Airtable authenticates with
	 * an Application Password created from the user profile.
	 */
	public static function ensure_user() {
		$user = get_user_by( 'login', self::USERNAME );

		if ( $user instanceof \WP_User ) {
			$user->add_role( self::ROLE );
			update_option( self::OPTION, 1, false );
			return;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => self::USERNAME,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'user_email'   => self::email_address(),
				'display_name' => 'Airtable Sync',
				'role'         => self::ROLE,
			)
		);

		if ( ! is_wp_error( $user_id ) ) {
			update_option( self::OPTION, 1, false );
		}
	}

	/**
	 * Remind an administrator to create the Application Password Airtable stores.
	 */
	public static function admin_notice() {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		$user = get_user_by( 'login', self::USERNAME );
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		if ( ! function_exists( 'wp_is_application_passwords_available' ) || ! wp_is_application_passwords_available() ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html( 'Application Passwords are unavailable. The airtable-sync user needs HTTPS before Airtable can authenticate.' );
			echo '</p></div>';
			return;
		}

		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			return;
		}

		$passwords = \WP_Application_Passwords::get_user_application_passwords( $user->ID );
		if ( ! empty( $passwords ) ) {
			return;
		}

		$link = get_edit_user_link( $user->ID );

		echo '<div class="notice notice-warning"><p>';
		echo esc_html( 'Create an Application Password for airtable-sync and store it in Airtable as WP_APPLICATION_PASSWORD.' );
		if ( is_string( $link ) && '' !== $link ) {
			echo ' <a href="' . esc_url( $link ) . '">' . esc_html( 'Edit airtable-sync' ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * @return string
	 */
	private static function email_address() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			$host = 'localhost.local';
		}

		$email = 'airtable-sync@' . $host;
		if ( is_email( $email ) && ! email_exists( $email ) ) {
			return $email;
		}

		return 'airtable-sync+' . wp_generate_password( 8, false ) . '@' . ( is_email( 'sync@' . $host ) ? $host : 'localhost.local' );
	}
}
