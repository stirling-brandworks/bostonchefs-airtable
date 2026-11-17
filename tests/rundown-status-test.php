<?php
/**
 * Unit tests for rundown status. Run with: php tests/rundown-status-test.php
 *
 * The first checks run before the theme's archive class exists, as on a site
 * without the Boston Chefs theme. A stand-in for that class is then declared
 * and the archived cases run against it.
 *
 * @package BostonChefs\Airtable
 */

namespace BostonChefs\Airtable\Tests {

	use BostonChefs\Airtable\Rundown_Status;

	require dirname( __DIR__ ) . '/includes/class-rundown-status.php';

	$failures = 0;

	function expect( $condition, $message ) {
		global $failures;

		if ( $condition ) {
			echo "ok  {$message}\n";
			return;
		}

		$failures++;
		echo "FAIL {$message}\n";
	}

	// ---- Without the theme ----

	expect( 'current' === Rundown_Status::status( 214 ), 'without the theme every rundown is current' );
	expect( null === Rundown_Status::year( 214 ), 'without the theme there is no year' );
	expect( null === Rundown_Status::current_rundown_id( 214 ), 'without the theme there is no current rundown link' );
	expect( null === Rundown_Status::archived_sync_error( 214, "Valentine's Day" ), 'without the theme syncs are never refused' );
	expect( 'standard' === Rundown_Status::type( (object) array( 'term_id' => 214 ) ), 'without term meta the type is standard' );

	// ---- Template types ----

	expect( 'holiday' === Rundown_Status::type_from_template( 'taxonomy-holiday-rundown.php' ), 'holiday template' );
	expect( 'feature' === Rundown_Status::type_from_template( 'taxonomy-feature-rundown.php' ), 'feature template' );
	expect( 'standard' === Rundown_Status::type_from_template( '' ), 'no template is standard' );
	expect( 'standard' === Rundown_Status::type_from_template( 'taxonomy-rundown.php' ), 'other template is standard' );

	// ---- With the theme's archive class ----
	// Live rundown 214 (year 2027) and its 2026 archive, term 900.

	if ( ! class_exists( '\Boston_Chefs_Rundown_Archive' ) ) {
		require __DIR__ . '/stubs/rundown-archive-stub.php';
	}

	expect( 'current' === Rundown_Status::status( 214 ), 'live rundown is current' );
	expect( 2027 === Rundown_Status::year( 214 ), 'live rundown has the year being built' );
	expect( null === Rundown_Status::current_rundown_id( 214 ), 'live rundown has no current rundown link' );
	expect( null === Rundown_Status::archived_sync_error( 214, "Valentine's Day" ), 'sync to a live rundown is allowed' );

	expect( 'archived' === Rundown_Status::status( 900 ), 'archived rundown is archived' );
	expect( 2026 === Rundown_Status::year( 900 ), 'archived rundown keeps its year' );
	expect( 214 === Rundown_Status::current_rundown_id( 900 ), 'archived rundown points to the live rundown' );

	$refused = Rundown_Status::archived_sync_error( 900, "Valentine's Day 2026" );
	expect( is_array( $refused ) && 'bc_airtable_rundown_archived' === $refused['code'], 'sync to an archived rundown is refused' );
	expect( is_array( $refused ) && 214 === $refused['current_rundown_id'], 'refusal names the current rundown' );
	expect(
		is_array( $refused ) && "Valentine's Day 2026 is an archived rundown and cannot be changed. Sync to the current rundown (term ID 214) instead." === $refused['message'],
		'refusal message tells Airtable where to sync'
	);

	$orphan = Rundown_Status::archived_sync_error( 901, 'Old Rundown 2020' );
	expect( is_array( $orphan ) && null === $orphan['current_rundown_id'], 'archive without a live rundown is still refused' );
	expect( is_array( $orphan ) && false === strpos( $orphan['message'], 'term ID' ), 'archive without a live rundown has no redirect in the message' );

	if ( $failures > 0 ) {
		fwrite( STDERR, "{$failures} failed\n" );
		exit( 1 );
	}

	echo "all passed\n";
	exit( 0 );
}
