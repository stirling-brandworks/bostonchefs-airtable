<?php
/**
 * Stand-in for the theme's Boston_Chefs_Rundown_Archive, used by
 * tests/rundown-status-test.php. Same method names and return types.
 *
 * Term 214 is a live rundown building 2027. Term 900 is its 2026 archive.
 * Term 901 is an archive whose live rundown no longer exists.
 *
 * @package BostonChefs\Airtable
 */

class Boston_Chefs_Rundown_Archive {

	private static $terms = array(
		214 => array( 'archived' => false, 'year' => 2027, 'current_rundown' => null ),
		900 => array( 'archived' => true, 'year' => 2026, 'current_rundown' => 214 ),
		901 => array( 'archived' => true, 'year' => 2020, 'current_rundown' => null ),
	);

	public static function is_archived( $term_id ) {
		return isset( self::$terms[ $term_id ] ) && self::$terms[ $term_id ]['archived'];
	}

	public static function year( $term_id ) {
		return isset( self::$terms[ $term_id ] ) ? self::$terms[ $term_id ]['year'] : null;
	}

	public static function current_rundown_id( $term_id ) {
		return isset( self::$terms[ $term_id ] ) ? self::$terms[ $term_id ]['current_rundown'] : null;
	}
}
