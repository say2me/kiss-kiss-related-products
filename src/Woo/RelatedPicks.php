<?php
/**
 * A source of ready-made related-product picks.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies related product IDs for a product, or nothing.
 */
interface RelatedPicks {

	/**
	 * Related product IDs for a product, best first.
	 *
	 * @param int   $product_id Product ID.
	 * @param int   $limit      Max related products.
	 * @param int[] $excluded   IDs to drop, including the product itself.
	 * @return int[]|null IDs, or null when there are none so the caller uses the taxonomy path.
	 */
	public function picks( int $product_id, int $limit, array $excluded ): ?array;
}
