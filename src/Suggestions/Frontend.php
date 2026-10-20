<?php
/**
 * Prints the service-picked reason under each related product, when enabled.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

use Vscm\WooRelatedProducts\InitableInterface;
use Vscm\WooRelatedProducts\Woo\CrossSellingRelatedProducts;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a reason line for each related item, only inside the related-products template part,
 * only when the setting is on and the service picks served the current single product.
 */
class Frontend implements InitableInterface {

	/**
	 * Taxonomy or service related products source, used to check whether the service picks served this product.
	 *
	 * @var CrossSellingRelatedProducts
	 */
	private CrossSellingRelatedProducts $cross_selling;

	/**
	 * Link rows repository.
	 *
	 * @var Links
	 */
	private Links $links;

	/**
	 * Whether we are currently between the related-products template part hooks.
	 *
	 * @var bool
	 */
	private bool $in_related = false;

	/**
	 * The single product ID whose related section is being rendered.
	 *
	 * @var int
	 */
	private int $single_product_id = 0;

	/**
	 * Build.
	 *
	 * @param CrossSellingRelatedProducts $cross_selling Taxonomy or service related products source.
	 * @param Links                       $links         Link rows repository.
	 */
	public function __construct( CrossSellingRelatedProducts $cross_selling, Links $links ) {
		$this->cross_selling = $cross_selling;
		$this->links         = $links;
	}

	/**
	 * Init.
	 */
	public function init() {
		add_action( 'woocommerce_before_template_part', array( $this, 'maybe_start' ) );
		add_action( 'woocommerce_after_template_part', array( $this, 'maybe_end' ) );
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'print_reason' ) );
	}

	/**
	 * Enter the related-products section, when the setting is on.
	 *
	 * @param string $template_name Template name.
	 * @return void
	 */
	public function maybe_start( string $template_name ): void {
		if ( 'single-product/related.php' !== $template_name ) {
			return;
		}

		$settings = get_option( 'vscm_woo_related_products_settings' );
		$settings = is_array( $settings ) ? $settings : array();

		if ( empty( $settings['showReasons'] ) ) {
			return;
		}

		$this->in_related        = true;
		$this->single_product_id = get_queried_object_id();
	}

	/**
	 * Leave the related-products section.
	 *
	 * @param string $template_name Template name.
	 * @return void
	 */
	public function maybe_end( string $template_name ): void {
		if ( 'single-product/related.php' !== $template_name ) {
			return;
		}

		$this->in_related        = false;
		$this->single_product_id = 0;
	}

	/**
	 * Print the stored reason for the current loop item, if any.
	 *
	 * @return void
	 */
	public function print_reason(): void {
		if ( ! $this->in_related || 0 === $this->single_product_id ) {
			return;
		}

		if ( ! $this->cross_selling->picks_served( $this->single_product_id ) ) {
			return;
		}

		global $product;

		// @phpstan-ignore-next-line -- WooCommerce class; not covered by the installed WP-only stub package.
		if ( ! ( $product instanceof WC_Product ) ) {
			return;
		}

		// @phpstan-ignore-next-line -- WooCommerce method; not covered by the installed WP-only stub package.
		$item_id = $product->get_id();
		$reason  = '';

		foreach ( $this->links->for_product( $this->single_product_id ) as $row ) {
			if ( (int) $row['related_id'] === $item_id ) {
				$reason = (string) $row['reason'];
				break;
			}
		}

		if ( '' === $reason ) {
			return;
		}

		echo '<p class="vscm-related-reason">' . esc_html( $reason ) . '</p>';
	}
}
