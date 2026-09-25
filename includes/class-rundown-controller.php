<?php
/**
 * REST endpoint that accepts Airtable rundown updates.
 *
 * @package BostonChefs\Airtable
 */

namespace BostonChefs\Airtable;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Rundown_Controller {

	const POST_TYPE = 'restaurant';
	const TAXONOMY  = 'rundown';

	/**
	 * @var Rundown_Merger
	 */
	private $merger;

	/**
	 * Whether this request authenticated with an Application Password.
	 *
	 * @var bool
	 */
	private static $application_password_authenticated = false;

	/**
	 * @param Rundown_Merger $merger Payload merger.
	 */
	public function __construct( Rundown_Merger $merger ) {
		$this->merger = $merger;
	}

	/**
	 * Register POST /bostonchefs/v1/rundowns.
	 */
	public function register() {
		register_rest_route(
			'bostonchefs/v1',
			'/rundowns',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_rundown' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Record a successful Application Password login.
	 *
	 * Hooked to application_password_did_authenticate from the plugin file,
	 * which loads before WordPress authenticates the REST request.
	 */
	public static function mark_application_password() {
		self::$application_password_authenticated = true;
	}

	/**
	 * Whether this request authenticated with an Application Password.
	 *
	 * @return bool
	 */
	public static function application_password_authenticated() {
		return self::$application_password_authenticated;
	}

	/**
	 * Require the airtable-sync capability over an Application Password.
	 *
	 * @return true|WP_Error
	 */
	public function permissions_check() {
		if ( ! is_user_logged_in() || ! self::$application_password_authenticated ) {
			return new WP_Error(
				'bc_airtable_unauthenticated',
				'Authentication with a WordPress Application Password is required.',
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( Integration_User::CAPABILITY ) ) {
			return new WP_Error(
				'bc_airtable_forbidden',
				'This user cannot sync rundowns.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Update Airtable-managed fields on one restaurant rundown.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_rundown( WP_REST_Request $request ) {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) || $this->is_list( $payload ) ) {
			return $this->error(
				'bc_airtable_invalid_body',
				'Request body must be a JSON object.',
				400
			);
		}

		$parsed = $this->merger->parse_payload( $payload );
		if ( ! $parsed['ok'] ) {
			return $this->error( $parsed['code'], $parsed['message'], 400, $parsed['field'] );
		}

		$restaurant_id = $parsed['restaurant_id'];
		$term_id       = $parsed['rundown_term_id'];
		$record_id     = $parsed['airtable_record_id'];

		$post = get_post( $restaurant_id );
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return $this->error(
				'bc_airtable_invalid_restaurant',
				'Restaurant not found.',
				404,
				'restaurant_id'
			);
		}

		$term = get_term( $term_id, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return $this->error(
				'bc_airtable_invalid_rundown',
				'Rundown term not found.',
				404,
				'rundown_term_id'
			);
		}

		$rundown_assigned = $this->ensure_rundown_assignment( $restaurant_id, $term_id );
		if ( is_wp_error( $rundown_assigned ) ) {
			return $rundown_assigned;
		}

		$existing = $this->read_rundown_meta( $restaurant_id, $term_id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$applied        = $this->merger->apply( $existing, $parsed['changes'] );
		$record_key     = $this->record_meta_key( $term_id );
		$stored_record  = (string) get_post_meta( $restaurant_id, $record_key, true );
		$record_changed = $stored_record !== $record_id;
		$url            = $this->public_url( $post, $term );

		if ( array() === $applied['updated_fields'] && ! $record_changed ) {
			return $this->success( $restaurant_id, $term_id, $record_id, array(), $url, $rundown_assigned );
		}

		if ( array() !== $applied['updated_fields'] ) {
			$saved = $this->persist_meta( $restaurant_id, $this->rundown_meta_key( $term_id ), $applied['rundown'] );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		if ( $record_changed ) {
			$saved = $this->persist_meta( $restaurant_id, $record_key, $record_id );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		return $this->success( $restaurant_id, $term_id, $record_id, $applied['updated_fields'], $url, $rundown_assigned );
	}

	/**
	 * Assign the restaurant to the rundown when that term is missing.
	 *
	 * Other rundown terms already on the restaurant are left in place.
	 * This never removes a rundown assignment.
	 *
	 * @param int $restaurant_id Restaurant post ID.
	 * @param int $term_id       Rundown term ID.
	 * @return bool|WP_Error True when this request assigned the term, false when it was already assigned.
	 */
	private function ensure_rundown_assignment( $restaurant_id, $term_id ) {
		if ( has_term( $term_id, self::TAXONOMY, $restaurant_id ) ) {
			return false;
		}

		$assigned = wp_set_object_terms(
			$restaurant_id,
			array( $term_id ),
			self::TAXONOMY,
			true
		);

		if ( is_wp_error( $assigned ) ) {
			return $this->error(
				'bc_airtable_rundown_assignment_failed',
				'The restaurant could not be assigned to the rundown.',
				500,
				'rundown_term_id'
			);
		}

		return true;
	}

	/**
	 * Read rundown meta, unpacking a legacy double-serialized string when present.
	 *
	 * @param int $restaurant_id Restaurant post ID.
	 * @param int $term_id       Rundown term ID.
	 * @return array|WP_Error
	 */
	private function read_rundown_meta( $restaurant_id, $term_id ) {
		$existing = get_post_meta( $restaurant_id, $this->rundown_meta_key( $term_id ), true );

		if ( is_string( $existing ) && '' !== $existing ) {
			$decoded = maybe_unserialize( $existing );
			if ( is_array( $decoded ) ) {
				$existing = $decoded;
			}
		}

		if ( false === $existing || '' === $existing ) {
			return array();
		}

		if ( ! is_array( $existing ) ) {
			return $this->error(
				'bc_airtable_invalid_meta',
				'Existing rundown data is not an array and was left unchanged.',
				409
			);
		}

		return $existing;
	}

	/**
	 * @param int    $post_id  Restaurant ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $value    Value to store. Arrays are serialized by WordPress.
	 * @return true|WP_Error
	 */
	private function persist_meta( $post_id, $meta_key, $value ) {
		$result = update_post_meta( $post_id, $meta_key, $value );

		if ( false !== $result ) {
			return true;
		}

		$stored = get_post_meta( $post_id, $meta_key, true );
		if ( $stored == $value ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			return true;
		}

		return $this->error(
			'bc_airtable_save_failed',
			'The rundown could not be saved.',
			500
		);
	}

	/**
	 * @param int      $restaurant_id    Restaurant post ID.
	 * @param int      $term_id          Rundown term ID.
	 * @param string   $record_id        Airtable record ID.
	 * @param string[] $updated_fields   Fields whose stored value changed.
	 * @param string   $url              Public rundown URL for the Airtable WP URL field.
	 * @param bool     $rundown_assigned Whether this request added the rundown term.
	 * @return WP_REST_Response
	 */
	private function success( $restaurant_id, $term_id, $record_id, array $updated_fields, $url, $rundown_assigned ) {
		return new WP_REST_Response(
			array(
				'success'            => true,
				'restaurant_id'      => $restaurant_id,
				'rundown_term_id'    => $term_id,
				'rundown_assigned'   => (bool) $rundown_assigned,
				'airtable_record_id' => $record_id,
				'meta_key'           => $this->rundown_meta_key( $term_id ),
				'updated_fields'     => $updated_fields,
				'message'            => $rundown_assigned
					? 'Restaurant assigned and Rundown successfully synced.'
					: 'Rundown successfully synced.',
				'url'                => $url,
			),
			200
		);
	}

	/**
	 * Public URL of this restaurant on the rundown.
	 *
	 * Holiday rundown term links are rewritten to /holiday/{slug}/ by the theme.
	 * The restaurant slug is the anchor id on the holiday card.
	 *
	 * @param \WP_Post $post Restaurant.
	 * @param \WP_Term $term Rundown term.
	 * @return string
	 */
	private function public_url( \WP_Post $post, $term ) {
		$term_link = get_term_link( $term, self::TAXONOMY );

		if ( ! is_wp_error( $term_link ) && is_string( $term_link ) && '' !== $term_link ) {
			return self::with_restaurant_anchor( $term_link, $post->post_name );
		}

		$permalink = get_permalink( $post );

		return is_string( $permalink ) ? $permalink : '';
	}

	/**
	 * @param string $term_link        Rundown archive URL.
	 * @param string $restaurant_slug  Restaurant post_name.
	 * @return string
	 */
	public static function with_restaurant_anchor( $term_link, $restaurant_slug ) {
		$slug = trim( (string) $restaurant_slug );
		if ( '' === $slug ) {
			return $term_link;
		}

		return $term_link . '#' . $slug;
	}

	/**
	 * @param string      $code    Error code.
	 * @param string      $message Error message.
	 * @param int         $status  HTTP status.
	 * @param string|null $field   Payload key related to the error.
	 * @return WP_Error
	 */
	private function error( $code, $message, $status, $field = null ) {
		$data = array( 'status' => $status );

		if ( null !== $field && '' !== $field ) {
			$data['field'] = $field;
		}

		return new WP_Error( $code, $message, $data );
	}

	/**
	 * @param int $term_id Rundown term ID.
	 * @return string
	 */
	private function rundown_meta_key( $term_id ) {
		return 'rundown_' . $term_id;
	}

	/**
	 * Record ID is stored beside the rundown array so a WordPress admin save
	 * of the rundown form does not drop it.
	 *
	 * @param int $term_id Rundown term ID.
	 * @return string
	 */
	private function record_meta_key( $term_id ) {
		return '_bc_airtable_rundown_' . $term_id;
	}

	/**
	 * @param array $value Decoded JSON value.
	 * @return bool
	 */
	private function is_list( array $value ) {
		if ( array() === $value ) {
			return false;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
