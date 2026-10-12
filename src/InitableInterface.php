<?php
/**
 * Initable/Executable interface
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress plugin initable interface.
 */
interface InitableInterface {
	/**
	 * Add WP actions/filters on start
	 *
	 * @return void
	 */
	public function init();
}
