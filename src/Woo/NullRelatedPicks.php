<?php
/**
 * Related picks source that has no picks.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Never has picks, so related products come from the taxonomy path.
 */
class NullRelatedPicks implements RelatedPicks {

	/**
	 * No picks.
	 *
	 * @param int   $product_id Product ID.
	 * @param int   $limit      Max related products.
	 * @param int[] $excluded   IDs to drop.
	 * @return int[]|null
	 */
	public function picks( int $product_id, int $limit, array $excluded ): ?array {
		return null;
	}
}
