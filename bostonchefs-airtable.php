<?php
/**
 * Plugin Name: BostonChefs Airtable Integration
 * Description: Syncs BostonChefs Airtable Rundown data into WordPress Restaurant Rundowns through a secure custom REST API.
 * Version: 1.0.0
 * Author: Stirling Brandworks
 * Text Domain: bostonchefs-airtable
 *
 * @package BostonChefs\Airtable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/interface-rundown-sanitizer.php';
require_once __DIR__ . '/includes/class-wp-rundown-sanitizer.php';
require_once __DIR__ . '/includes/class-rundown-merger.php';
require_once __DIR__ . '/includes/class-rundown-controller.php';
require_once __DIR__ . '/includes/class-integration-user.php';

add_action(
	'application_password_did_authenticate',
	array( 'BostonChefs\Airtable\Rundown_Controller', 'mark_application_password' )
);

BostonChefs\Airtable\Integration_User::register();

register_activation_hook( __FILE__, array( 'BostonChefs\Airtable\Integration_User', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BostonChefs\Airtable\Integration_User', 'deactivate' ) );

add_action(
	'rest_api_init',
	static function () {
		$merger     = new BostonChefs\Airtable\Rundown_Merger( new BostonChefs\Airtable\Wp_Rundown_Sanitizer() );
		$controller = new BostonChefs\Airtable\Rundown_Controller( $merger );
		$controller->register();
	}
);
