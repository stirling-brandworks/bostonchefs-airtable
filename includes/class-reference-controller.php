<?php
/**
 * REST endpoints that list restaurants and rundown terms for Airtable lookups.
 *
 * @package BostonChefs\Airtable
 */

namespace BostonChefs\Airtable;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Reference_Controller {

	const POST_TYPE = 'restaurant';
	const TAXONOMY  = 'rundown';

	/**
	 * Register the reference routes, including the temporary rundown export.
	 */
	public function register() {
		register_rest_route(
			'bostonchefs/v1',
			'/restaurants',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_restaurants' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			'bostonchefs/v1',
			'/rundown-terms',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_rundown_terms' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			'bostonchefs/v1',
			'/rundown-entries/(?P<term_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_rundown_entries' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Require the airtable-sync capability over an Application Password.
	 *
	 * @return true|WP_Error
	 */
	public function permissions_check() {
		if ( ! is_user_logged_in() || ! Rundown_Controller::application_password_authenticated() ) {
			return new WP_Error(
				'bc_airtable_unauthenticated',
				'Authentication with a WordPress Application Password is required.',
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( Integration_User::CAPABILITY ) ) {
			return new WP_Error(
				'bc_airtable_forbidden',
				'This user cannot read reference data.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Published restaurants, ordered by title.
	 *
	 * @return WP_REST_Response
	 */
	public function get_restaurants() {
		$restaurants = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$data = array();

		foreach ( $restaurants as $restaurant ) {
			$data[] = array(
				'id'      => $restaurant->ID,
				'name'    => $this->decode_text( get_the_title( $restaurant ) ),
				'url'     => get_permalink( $restaurant ),
				'content' => $this->paragraph_text( $restaurant->post_content ),
			);
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Plain paragraph text from the restaurant Content field.
	 *
	 * Block markup and HTML are removed. Paragraph and line breaks are kept.
	 *
	 * @param string $content Raw post_content.
	 * @return string
	 */
	private function paragraph_text( $content ) {
		$content = strip_shortcodes( (string) $content );
		$content = preg_replace( '/<!--.*?-->/s', '', $content );
		$content = preg_replace( '/<\s*br\s*\/?\s*>/i', "\n", $content );
		$content = preg_replace( '/<\/\s*(p|div|li|h[1-6])\s*>/i', "\n\n", $content );
		$content = wp_strip_all_tags( $content );
		$content = $this->decode_text( $content );
		$content = preg_replace( "/[ \t]+\n/", "\n", $content );
		$content = preg_replace( "/\n{3,}/", "\n\n", $content );

		return trim( $content );
	}

	/**
	 * @param string $text Text that may contain HTML entities.
	 * @return string
	 */
	private function decode_text( $text ) {
		return html_entity_decode( (string) $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
	}

	/**
	 * Rundown terms, including empty ones, ordered by name.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_rundown_terms() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$data = array();

		foreach ( $terms as $term ) {
			$data[] = array(
				'id'   => $term->term_id,
				'name' => $term->name,
				'slug' => $term->slug,
			);
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Temporary export of one rundown term for Airtable seeding.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_rundown_entries( WP_REST_Request $request ) {
		$term_id = absint( $request['term_id'] );
		$term    = get_term( $term_id, self::TAXONOMY );

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error(
				'bc_airtable_invalid_rundown',
				'Rundown term not found.',
				array( 'status' => 404 )
			);
		}

		$restaurants = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'tax_query'      => array(
					array(
						'taxonomy' => self::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			)
		);

		$data = array();

		foreach ( $restaurants as $restaurant ) {
			$rundown = $this->read_rundown_meta( $restaurant->ID, $term_id );

			$data[] = array(
				'restaurant_id'               => $restaurant->ID,
				'restaurant_name'             => $this->decode_text( get_the_title( $restaurant ) ),
				'rundown_term_id'             => $term_id,
				'title'                       => $this->rundown_text( $rundown, 'title' ),
				'blurb'                       => $this->rundown_text( $rundown, 'blurb' ),
				'availability'                => $this->rundown_text( $rundown, 'availability' ),
				'price'                       => $this->rundown_text( $rundown, 'price' ),
				'cta_text'                    => $this->rundown_text( $rundown, 'cta_text' ),
				'cta_url'                     => $this->rundown_text( $rundown, 'cta_url' ),
				'alternative_reservation_url' => $this->reservation_url( $rundown ),
				'show_reservation_url'        => ! empty( $rundown['show_reservation_url'] ),
			);
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Read rundown meta, unpacking a legacy double-serialized string when present.
	 *
	 * @param int $restaurant_id Restaurant post ID.
	 * @param int $term_id       Rundown term ID.
	 * @return array
	 */
	private function read_rundown_meta( $restaurant_id, $term_id ) {
		$existing = get_post_meta( $restaurant_id, 'rundown_' . $term_id, true );

		if ( is_string( $existing ) && '' !== $existing ) {
			$decoded = maybe_unserialize( $existing );
			if ( is_array( $decoded ) ) {
				$existing = $decoded;
			}
		}

		if ( ! is_array( $existing ) ) {
			return array();
		}

		return $existing;
	}

	/**
	 * Reservation links are stored as reservation_url.
	 *
	 * @param array $rundown Rundown meta.
	 * @return string
	 */
	private function reservation_url( array $rundown ) {
		if ( isset( $rundown['reservation_url'] ) ) {
			return $this->decode_text( $rundown['reservation_url'] );
		}

		if ( isset( $rundown['alternative_reservation_url'] ) ) {
			return $this->decode_text( $rundown['alternative_reservation_url'] );
		}

		return '';
	}

	/**
	 * @param array  $rundown Rundown meta.
	 * @param string $key     Meta key.
	 * @return string
	 */
	private function rundown_text( array $rundown, $key ) {
		if ( ! isset( $rundown[ $key ] ) || null === $rundown[ $key ] ) {
			return '';
		}

		return $this->decode_text( $rundown[ $key ] );
	}
}
