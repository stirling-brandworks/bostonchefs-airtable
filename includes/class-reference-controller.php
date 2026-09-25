<?php
/**
 * REST endpoints that list restaurants and rundown terms for Airtable lookups.
 *
 * @package BostonChefs\Airtable
 */

namespace BostonChefs\Airtable;

use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

class Reference_Controller {

	const POST_TYPE = 'restaurant';
	const TAXONOMY  = 'rundown';

	/**
	 * Register GET /bostonchefs/v1/restaurants and /rundown-terms.
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
				'id'   => $restaurant->ID,
				'name' => get_the_title( $restaurant ),
				'url'  => get_permalink( $restaurant ),
			);
		}

		return new WP_REST_Response( $data, 200 );
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
}
