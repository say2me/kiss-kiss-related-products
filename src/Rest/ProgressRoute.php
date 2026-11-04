<?php
/**
 * GET vscm/v1/related/progress: the suggestions' progress and its block, for the settings page to update without a
 * reload (C-0493ee8 R3).
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Rest;

use Vscm\WooRelatedProducts\Admin\ConnectionPanel;
use Vscm\WooRelatedProducts\InitableInterface;
use Vscm\WooRelatedProducts\Suggestions\Progress;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the plugin's own tables only; the service is never called from here (R4).
 */
class ProgressRoute implements InitableInterface {

	/**
	 * The progress.
	 *
	 * @var Progress
	 */
	private Progress $progress;

	/**
	 * The card that renders the block.
	 *
	 * @var ConnectionPanel
	 */
	private ConnectionPanel $panel;

	/**
	 * Build.
	 *
	 * @param Progress        $progress The progress.
	 * @param ConnectionPanel $panel    The card that renders the block.
	 */
	public function __construct( Progress $progress, ConnectionPanel $panel ) {
		$this->progress = $progress;
		$this->panel    = $panel;
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
			SettingsRoute::NAMESPACE,
			'/related/progress',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_progress' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	/**
	 * Permission callback: the shop's managers, as /related/settings.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * GET /related/progress: the numbers and the block's HTML.
	 *
	 * @return WP_REST_Response
	 */
	public function get_progress(): WP_REST_Response {
		$read = $this->progress->read();
		return new WP_REST_Response( $read + array( 'html' => $this->panel->progress_html( $read ) ), 200 );
	}
}
