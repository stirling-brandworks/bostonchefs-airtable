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
	 * Register the reference routes.
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
	 * Archived years are listed with status "archived" so Airtable can keep
	 * them apart from the rundowns editors are building now.
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
			$data[] = $this->describe_rundown( $term );
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * One rundown term as Airtable reads it.
	 *
	 * @param \WP_Term $term Rundown term.
	 * @return array
	 */
	private function describe_rundown( $term ) {
		$term_id = (int) $term->term_id;
		$status  = Rundown_Status::status( $term_id );

		// Archived rundowns return a 404 to the public, so they have no page to link.
		$url = null;
		if ( Rundown_Status::CURRENT === $status ) {
			$link = get_term_link( $term, self::TAXONOMY );
			$url  = is_wp_error( $link ) ? null : $link;
		}

		return array(
			'id'                 => $term_id,
			'name'               => $this->decode_text( $term->name ),
			'slug'               => $term->slug,
			'status'             => $status,
			'year'               => Rundown_Status::year( $term_id ),
			'current_rundown_id' => Rundown_Status::current_rundown_id( $term_id ),
			'type'               => Rundown_Status::type( $term ),
			'restaurant_count'   => (int) $term->count,
			'url'                => $url,
		);
	}
}
