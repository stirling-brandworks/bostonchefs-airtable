<?php
/**
 * Year and archive status of a rundown term, read from the Boston Chefs theme.
 *
 * The theme's rundown archiving (Boston_Chefs_Rundown_Archive) stores a year on
 * each rundown. "Archive & Start New Year" copies the live rundown into a new
 * archived term, for example "Valentine's Day 2026", and empties the live one.
 * An archived term records the live rundown it came from.
 *
 * Without the theme's archive code, every rundown reads as current with no year.
 *
 * @package BostonChefs\Airtable
 */

namespace BostonChefs\Airtable;

class Rundown_Status {

	const CURRENT  = 'current';
	const ARCHIVED = 'archived';

	const TYPE_HOLIDAY  = 'holiday';
	const TYPE_FEATURE  = 'feature';
	const TYPE_STANDARD = 'standard';

	/**
	 * Whether the theme's rundown archive code is loaded.
	 *
	 * @return bool
	 */
	private static function archive_available() {
		return class_exists( '\Boston_Chefs_Rundown_Archive' );
	}

	/**
	 * @param int $term_id Rundown term ID.
	 * @return bool
	 */
	public static function is_archived( $term_id ) {
		return self::archive_available() && \Boston_Chefs_Rundown_Archive::is_archived( (int) $term_id );
	}

	/**
	 * @param int $term_id Rundown term ID.
	 * @return string current or archived.
	 */
	public static function status( $term_id ) {
		return self::is_archived( $term_id ) ? self::ARCHIVED : self::CURRENT;
	}

	/**
	 * The rundown's year. For an archived term this is the year it holds.
	 * For a current rundown it is the year being built, and is null until set.
	 *
	 * @param int $term_id Rundown term ID.
	 * @return int|null
	 */
	public static function year( $term_id ) {
		if ( ! self::archive_available() ) {
			return null;
		}

		return \Boston_Chefs_Rundown_Archive::year( (int) $term_id );
	}

	/**
	 * The live rundown an archived term was copied from. Null for a current rundown.
	 *
	 * @param int $term_id Rundown term ID.
	 * @return int|null
	 */
	public static function current_rundown_id( $term_id ) {
		if ( ! self::is_archived( $term_id ) ) {
			return null;
		}

		return \Boston_Chefs_Rundown_Archive::current_rundown_id( (int) $term_id );
	}

	/**
	 * Display type from the rundown's template setting.
	 *
	 * @param string $template Template file, such as taxonomy-holiday-rundown.php.
	 * @return string holiday, feature, or standard.
	 */
	public static function type_from_template( $template ) {
		switch ( (string) $template ) {
			case 'taxonomy-holiday-rundown.php':
				return self::TYPE_HOLIDAY;
			case 'taxonomy-feature-rundown.php':
				return self::TYPE_FEATURE;
			default:
				return self::TYPE_STANDARD;
		}
	}

	/**
	 * @param \WP_Term $term Rundown term.
	 * @return string holiday, feature, or standard.
	 */
	public static function type( $term ) {
		$template = class_exists( '\WP_Term_Meta' ) ? \WP_Term_Meta::get( $term, 'template' ) : '';

		return self::type_from_template( is_string( $template ) ? $template : '' );
	}

	/**
	 * Error details for a sync aimed at an archived rundown, or null when the
	 * rundown is current.
	 *
	 * An archived rundown is a closed year. The theme also removes any
	 * restaurant newly assigned to one, so a write there would report success
	 * and not appear on any page.
	 *
	 * @param int    $term_id   Rundown term ID.
	 * @param string $term_name Rundown name, for the message.
	 * @return array|null array( 'code', 'message', 'current_rundown_id' ) or null.
	 */
	public static function archived_sync_error( $term_id, $term_name ) {
		if ( ! self::is_archived( $term_id ) ) {
			return null;
		}

		$current_id = self::current_rundown_id( $term_id );
		$message    = sprintf( '%s is an archived rundown and cannot be changed.', $term_name );

		if ( $current_id ) {
			$message .= sprintf( ' Sync to the current rundown (term ID %d) instead.', $current_id );
		}

		return array(
			'code'               => 'bc_airtable_rundown_archived',
			'message'            => $message,
			'current_rundown_id' => $current_id,
		);
	}
}
