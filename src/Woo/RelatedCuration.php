<?php
/**
 * Per-product pinned and excluded related products.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the products a shop owner pinned to, or excluded from, a product's related list.
 */
interface RelatedCuration {

	/**
	 * A product's pinned product IDs.
	 *
	 * @param int $id Product ID.
	 * @return int[]
	 */
	public function pinned( int $id ): array;

	/**
	 * A product's excluded product IDs.
	 *
	 * @param int $id Product ID.
	 * @return int[]
	 */
	public function excluded( int $id ): array;
}
