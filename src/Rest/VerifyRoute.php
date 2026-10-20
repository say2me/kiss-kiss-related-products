<?php
/**
 * The verify route the suggestion service fetches while a site connects.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Rest;

use Vscm\WooRelatedProducts\Identity\Challenge;
use Vscm\WooRelatedProducts\InitableInterface;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers GET aiai/v1/verify/{challenge}: 200 with the challenge while this site keeps it, 404
 * otherwise. Open to anyone, as the service calls it without credentials; it reads one option and stores nothing.
 */
class VerifyRoute implements InitableInterface {

	/**
	 * REST namespace of the suggestion service's routes.
	 */
	public const NAMESPACE = 'aiai/v1';

	/**
	 * The challenge store.
	 *
	 * @var Challenge
	 */
	private Challenge $challenge;

	/**
	 * Build.
	 *
	 * @param Challenge $challenge The challenge store.
	 */
	public function __construct( Challenge $challenge ) {
		$this->challenge = $challenge;
	}

	/**
	 * Init.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/verify/(?P<challenge>[A-Za-z0-9]{32})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'verify' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /verify/{challenge}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify( WP_REST_Request $request ) {
		$value = (string) $request->get_param( 'challenge' );
		if ( ! $this->challenge->matches( $value ) ) {
			return new WP_Error( 'aiai_unknown_challenge', 'No such challenge.', array( 'status' => 404 ) );
		}
		return new WP_REST_Response( array( 'challenge' => $value ) );
	}
}
