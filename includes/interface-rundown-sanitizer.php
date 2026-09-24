<?php
/**
 * Sanitizes Airtable-managed rundown values before they are stored.
 *
 * @package BostonChefs\Airtable
 */

namespace BostonChefs\Airtable;

interface Rundown_Sanitizer {

	/**
	 * Sanitize a single-line text value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function text( $value );

	/**
	 * Sanitize a multi-line text value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function textarea( $value );

	/**
	 * Sanitize a URL.
	 *
	 * @param string $value Raw value.
	 * @return string Empty when the URL is not allowed.
	 */
	public function url( $value );
}
