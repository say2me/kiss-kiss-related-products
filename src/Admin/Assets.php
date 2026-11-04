<?php
/**
 * The Kiss Kiss admin styles: the design tokens and the components (C-ce3b88a R1–R4), and the Suggestions card's
 * progress updater (C-0493ee8).
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Admin;

use Vscm\WooRelatedProducts\InitableInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the two stylesheets and the progress script on every admin request, so an extension's screen can depend on
 * them, and enqueues none: each of our screens enqueues what it uses on its own hook, and no other screen loads them.
 */
class Assets implements InitableInterface {

	/**
	 * The components; it depends on TOKENS.
	 */
	public const HANDLE = 'kisskiss-admin';

	/**
	 * The design tokens and the fonts' @font-face.
	 */
	public const TOKENS = 'kisskiss-tokens';

	/**
	 * The Suggestions card's progress updater (C-0493ee8 R3).
	 */
	public const PROGRESS = 'vscm-related-progress';

	/**
	 * Init.
	 */
	public function init() {
		// Before the screens' own enqueues, which run at the default priority.
		add_action( 'admin_enqueue_scripts', array( $this, 'register' ), 5 );
	}

	/**
	 * Register the handles.
	 *
	 * @return void
	 */
	public function register(): void {
		$base    = plugin_dir_url( dirname( __DIR__ ) ) . 'assets/design/';
		$version = VSCM_WOO_VERSION;

		wp_register_style( self::TOKENS, $base . 'tokens.css', array(), $version );
		wp_register_style( self::HANDLE, $base . 'admin.css', array( self::TOKENS ), $version );
		wp_register_script( self::PROGRESS, plugin_dir_url( dirname( __DIR__ ) ) . 'assets/js/progress.js', array(), $version, true );
		wp_localize_script(
			self::PROGRESS,
			'vscmProgress',
			array(
				'url'   => esc_url_raw( rest_url( 'vscm/v1/related/progress' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
