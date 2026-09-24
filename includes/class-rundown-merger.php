<?php
/**
 * Merges an Airtable payload into an existing restaurant rundown array.
 *
 * Omitted fields stay as they are. JSON null clears a managed field.
 * A blank string is ignored so an empty Airtable cell does not wipe WordPress.
 *
 * @package BostonChefs\Airtable
 */

namespace BostonChefs\Airtable;

class Rundown_Merger {

	const MANAGED_FIELDS = array(
		'title'        => 'text',
		'blurb'        => 'textarea',
		'availability' => 'text',
		'price'        => 'text',
		'cta_text'     => 'text',
		'cta_url'      => 'url',
	);

	const PRESERVED_FIELDS = array(
		'image',
		'menu',
		'show_reservation_url',
		'reservation_url',
	);

	const IDENTITY_FIELDS = array(
		'restaurant_id',
		'rundown_term_id',
		'airtable_record_id',
	);

	const MAX_LENGTH = array(
		'text'     => 500,
		'textarea' => 5000,
		'url'      => 2048,
	);

	/**
	 * @var Rundown_Sanitizer
	 */
	private $sanitizer;

	/**
	 * @param Rundown_Sanitizer $sanitizer Field sanitizer.
	 */
	public function __construct( Rundown_Sanitizer $sanitizer ) {
		$this->sanitizer = $sanitizer;
	}

	/**
	 * Validate a JSON object and collect managed-field changes.
	 *
	 * A change value of null means the stored field should be cleared.
	 *
	 * @param array $payload Decoded JSON object.
	 * @return array
	 */
	public function parse_payload( array $payload ) {
		$allowed = array_merge( self::IDENTITY_FIELDS, array_keys( self::MANAGED_FIELDS ) );

		foreach ( array_keys( $payload ) as $key ) {
			if ( in_array( $key, self::PRESERVED_FIELDS, true ) ) {
				return $this->failure(
					'bc_airtable_preserved_field',
					sprintf( '%s is managed in WordPress and cannot be updated from Airtable.', $key ),
					$key
				);
			}

			if ( ! in_array( $key, $allowed, true ) ) {
				return $this->failure(
					'bc_airtable_unknown_field',
					sprintf( '%s is not a supported field.', $key ),
					(string) $key
				);
			}
		}

		$restaurant_id = $this->required_id( $payload, 'restaurant_id' );
		if ( is_array( $restaurant_id ) ) {
			return $restaurant_id;
		}

		$term_id = $this->required_id( $payload, 'rundown_term_id' );
		if ( is_array( $term_id ) ) {
			return $term_id;
		}

		$record_id = $this->required_record_id( $payload );
		if ( is_array( $record_id ) ) {
			return $record_id;
		}

		$changes = array();

		foreach ( self::MANAGED_FIELDS as $field => $type ) {
			if ( ! array_key_exists( $field, $payload ) ) {
				continue;
			}

			$parsed = $this->parse_managed_value( $field, $type, $payload[ $field ] );
			if ( isset( $parsed['ok'] ) && ! $parsed['ok'] ) {
				return $parsed;
			}

			if ( $parsed['apply'] ) {
				$changes[ $field ] = $parsed['value'];
			}
		}

		return array(
			'ok'                  => true,
			'restaurant_id'       => $restaurant_id,
			'rundown_term_id'     => $term_id,
			'airtable_record_id'  => $record_id,
			'changes'             => $changes,
		);
	}

	/**
	 * Apply parsed changes onto the existing rundown array.
	 *
	 * @param array $existing Current rundown meta.
	 * @param array $changes  Field map from parse_payload(). Null clears the field.
	 * @return array{rundown: array, updated_fields: string[]}
	 */
	public function apply( array $existing, array $changes ) {
		$rundown = $existing;
		$updated = array();

		foreach ( self::MANAGED_FIELDS as $field => $type ) {
			if ( ! array_key_exists( $field, $changes ) ) {
				continue;
			}

			$new_value = null === $changes[ $field ] ? '' : $changes[ $field ];
			$current   = array_key_exists( $field, $rundown ) ? $rundown[ $field ] : null;

			if ( $current === $new_value ) {
				continue;
			}

			$rundown[ $field ] = $new_value;
			$updated[]          = $field;
		}

		return array(
			'rundown'        => $rundown,
			'updated_fields' => $updated,
		);
	}

	/**
	 * @param array  $payload Request body.
	 * @param string $key     restaurant_id or rundown_term_id.
	 * @return int|array
	 */
	private function required_id( array $payload, $key ) {
		if ( ! array_key_exists( $key, $payload ) || null === $payload[ $key ] || '' === $payload[ $key ] ) {
			return $this->failure(
				'bc_airtable_missing_field',
				sprintf( '%s is required.', $key ),
				$key
			);
		}

		$value = $payload[ $key ];

		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}

		if ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			return (int) $value;
		}

		return $this->failure(
			'bc_airtable_invalid_field',
			sprintf( '%s must be a positive integer.', $key ),
			$key
		);
	}

	/**
	 * @param array $payload Request body.
	 * @return string|array
	 */
	private function required_record_id( array $payload ) {
		$key = 'airtable_record_id';

		if ( ! array_key_exists( $key, $payload ) || null === $payload[ $key ] || '' === $payload[ $key ] ) {
			return $this->failure(
				'bc_airtable_missing_field',
				'airtable_record_id is required.',
				$key
			);
		}

		if ( ! is_string( $payload[ $key ] ) ) {
			return $this->failure(
				'bc_airtable_invalid_field',
				'airtable_record_id must be a string.',
				$key
			);
		}

		$record_id = trim( $payload[ $key ] );

		if ( ! preg_match( '/^rec[a-zA-Z0-9]{14}$/', $record_id ) ) {
			return $this->failure(
				'bc_airtable_invalid_field',
				'airtable_record_id must be an Airtable record ID.',
				$key
			);
		}

		return $record_id;
	}

	/**
	 * @param string $field Field name.
	 * @param string $type  text, textarea, or url.
	 * @param mixed  $value Raw JSON value.
	 * @return array
	 */
	private function parse_managed_value( $field, $type, $value ) {
		if ( null === $value ) {
			return array(
				'apply' => true,
				'value' => null,
			);
		}

		if ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
			if ( 'url' === $type ) {
				return $this->failure(
					'bc_airtable_invalid_field',
					sprintf( '%s must be a string.', $field ),
					$field
				);
			}

			$value = (string) $value;
		}

		if ( ! is_string( $value ) ) {
			return $this->failure(
				'bc_airtable_invalid_field',
				sprintf( '%s must be a string or null.', $field ),
				$field
			);
		}

		if ( '' === trim( $value ) ) {
			return array(
				'apply' => false,
				'value' => null,
			);
		}

		if ( 'url' === $type ) {
			$sanitized = $this->sanitizer->url( $value );
		} elseif ( 'textarea' === $type ) {
			$sanitized = $this->sanitizer->textarea( $value );
		} else {
			$sanitized = $this->sanitizer->text( $value );
		}

		if ( ! is_string( $sanitized ) || '' === $sanitized ) {
			return $this->failure(
				'bc_airtable_invalid_field',
				sprintf( '%s is not a valid value.', $field ),
				$field
			);
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $sanitized ) : strlen( $sanitized );
		$max    = self::MAX_LENGTH[ $type ];

		if ( $length > $max ) {
			return $this->failure(
				'bc_airtable_invalid_field',
				sprintf( '%s must be %d characters or fewer.', $field, $max ),
				$field
			);
		}

		return array(
			'apply' => true,
			'value' => $sanitized,
		);
	}

	/**
	 * @param string $code    Machine-readable error code.
	 * @param string $message Human-readable error.
	 * @param string $field   Payload key that failed.
	 * @return array
	 */
	private function failure( $code, $message, $field ) {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
			'field'   => $field,
		);
	}
}
