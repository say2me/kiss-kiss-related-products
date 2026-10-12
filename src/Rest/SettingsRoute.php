<?php
/**
 * REST route for the plugin's settings.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Rest;

use Vscm\WooRelatedProducts\Admin\Settings;
use Vscm\WooRelatedProducts\InitableInterface;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers GET/POST vscm/v1/related/settings. Extensions add their own routes to the same namespace.
 */
class SettingsRoute implements InitableInterface {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'vscm/v1';

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Build.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
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
			'/related/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_settings' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * GET /related/settings
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->settings->get() );
	}

	/**
	 * POST /related/settings
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function post_settings( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->settings->update( (array) $request->get_json_params() ) );
	}
}
