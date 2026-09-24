<?php
/**
 * Unit tests for the rundown payload merger. Run with: php tests/merger-test.php
 *
 * @package BostonChefs\Airtable
 */

namespace BostonChefs\Airtable\Tests;

use BostonChefs\Airtable\Rundown_Controller;
use BostonChefs\Airtable\Rundown_Merger;
use BostonChefs\Airtable\Rundown_Sanitizer;

require dirname( __DIR__ ) . '/includes/interface-rundown-sanitizer.php';
require dirname( __DIR__ ) . '/includes/class-rundown-merger.php';
require dirname( __DIR__ ) . '/includes/class-rundown-controller.php';

class Test_Sanitizer implements Rundown_Sanitizer {

	public function text( $value ) {
		return trim( strip_tags( (string) $value ) );
	}

	public function textarea( $value ) {
		return trim( strip_tags( (string) $value ) );
	}

	public function url( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '#^https?://#i', $value ) ) {
			return '';
		}

		return $value;
	}
}

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

$merger = new Rundown_Merger( new Test_Sanitizer() );

$existing = array(
	'title'                 => 'Old title',
	'blurb'                 => 'Old blurb',
	'availability'          => 'Friday',
	'price'                 => '$40',
	'cta_text'              => 'Book',
	'cta_url'               => 'https://example.com/old',
	'image'                 => 88,
	'menu'                  => 99,
	'show_reservation_url'  => true,
	'reservation_url'       => 'https://resy.com/example',
);

$base = array(
	'restaurant_id'      => 10,
	'rundown_term_id'    => 20,
	'airtable_record_id' => 'recABCDEFGHIJKLMN',
);

$parsed = $merger->parse_payload(
	$base + array(
		'title' => 'New title',
		'blurb' => '',
		'price' => null,
	)
);
expect( true === $parsed['ok'], 'valid payload parses' );
expect( 10 === $parsed['restaurant_id'], 'restaurant id is kept' );
expect( 20 === $parsed['rundown_term_id'], 'term id is kept' );
expect( 'recABCDEFGHIJKLMN' === $parsed['airtable_record_id'], 'record id is kept' );
expect( array( 'title' => 'New title', 'price' => null ) === $parsed['changes'], 'blank blurb is ignored and null price clears' );

$applied = $merger->apply( $existing, $parsed['changes'] );
expect( 'New title' === $applied['rundown']['title'], 'title is updated' );
expect( 'Old blurb' === $applied['rundown']['blurb'], 'omitted and blank fields stay' );
expect( '' === $applied['rundown']['price'], 'null clears price' );
expect( 88 === $applied['rundown']['image'], 'image is preserved' );
expect( 99 === $applied['rundown']['menu'], 'menu is preserved' );
expect( true === $applied['rundown']['show_reservation_url'], 'show reservation flag is preserved' );
expect( 'https://resy.com/example' === $applied['rundown']['reservation_url'], 'reservation url is preserved' );
expect( array( 'title', 'price' ) === $applied['updated_fields'], 'updated fields lists real changes' );

$repeat = $merger->apply( $applied['rundown'], $parsed['changes'] );
expect( array() === $repeat['updated_fields'], 'repeating the same payload changes nothing' );

$numeric = $merger->parse_payload( $base + array( 'price' => 85 ) );
expect( true === $numeric['ok'] && '85' === $numeric['changes']['price'], 'numeric price is stored as text' );

$zero = $merger->parse_payload( $base + array( 'price' => '0' ) );
expect( true === $zero['ok'] && '0' === $zero['changes']['price'], 'zero is a real value' );

$missing = $merger->parse_payload( array( 'rundown_term_id' => 1, 'airtable_record_id' => 'recABCDEFGHIJKLMN' ) );
expect( ! $missing['ok'] && 'restaurant_id' === $missing['field'], 'restaurant id is required' );

$bad_record = $merger->parse_payload( array_merge( $base, array( 'airtable_record_id' => 'not-a-record' ) ) );
expect( ! $bad_record['ok'] && 'airtable_record_id' === $bad_record['field'], 'record id must look like an Airtable id' );

$preserved = $merger->parse_payload( $base + array( 'image' => 5 ) );
expect( ! $preserved['ok'] && 'bc_airtable_preserved_field' === $preserved['code'], 'image updates are rejected' );

$menu = $merger->parse_payload( $base + array( 'menu' => 5 ) );
expect( ! $menu['ok'] && 'menu' === $menu['field'], 'menu updates are rejected' );

$reservation = $merger->parse_payload( $base + array( 'reservation_url' => 'https://example.com' ) );
expect( ! $reservation['ok'] && 'reservation_url' === $reservation['field'], 'reservation url updates are rejected' );

$unknown = $merger->parse_payload( $base + array( 'notes' => 'hello' ) );
expect( ! $unknown['ok'] && 'bc_airtable_unknown_field' === $unknown['code'], 'unknown fields are rejected' );

$bad_url = $merger->parse_payload( $base + array( 'cta_url' => 'javascript:alert(1)' ) );
expect( ! $bad_url['ok'] && 'cta_url' === $bad_url['field'], 'unsafe urls are rejected' );

$good_url = $merger->parse_payload( $base + array( 'cta_url' => 'https://example.com/order' ) );
expect( true === $good_url['ok'] && 'https://example.com/order' === $good_url['changes']['cta_url'], 'http urls are accepted' );

$spaces = $merger->parse_payload( $base + array( 'availability' => '   ' ) );
expect( true === $spaces['ok'] && array() === $spaces['changes'], 'whitespace-only values are ignored' );

$string_ids = $merger->parse_payload(
	array(
		'restaurant_id'      => '15',
		'rundown_term_id'    => '44',
		'airtable_record_id' => 'recABCDEFGHIJKLMN',
	)
);
expect( true === $string_ids['ok'] && 15 === $string_ids['restaurant_id'] && 44 === $string_ids['rundown_term_id'], 'numeric strings are accepted as ids' );

$anchored = Rundown_Controller::with_restaurant_anchor( 'https://example.com/holiday/christmas/', 'oleana' );
expect( 'https://example.com/holiday/christmas/#oleana' === $anchored, 'rundown url keeps the restaurant anchor' );

$plain = Rundown_Controller::with_restaurant_anchor( 'https://example.com/holiday/christmas/', '  ' );
expect( 'https://example.com/holiday/christmas/' === $plain, 'blank restaurant slug leaves the rundown url unchanged' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} failed\n" );
	exit( 1 );
}

echo "all passed\n";
exit( 0 );
