<?php
/**
 * WordPress implementation of rundown field sanitizing.
 *
 * @package BostonChefs\Airtable
 */

namespace BostonChefs\Airtable;

class Wp_Rundown_Sanitizer implements Rundown_Sanitizer {

	/**
	 * {@inheritdoc}
	 */
	public function text( $value ) {
		return sanitize_text_field( $value );
	}

	/**
	 * {@inheritdoc}
	 */
	public function textarea( $value ) {
		return sanitize_textarea_field( $value );
	}

	/**
	 * {@inheritdoc}
	 */
	public function url( $value ) {
		return esc_url_raw( $value );
	}
}
