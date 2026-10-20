<?php
/**
 * Shared published-product lookup for the suggestions.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Published product IDs, shared by the CLI, REST and status.
 */
class Catalog {

	/**
	 * Published product IDs.
	 *
	 * @return int[]
	 */
	public static function published_ids(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		return array_map( 'intval', $ids );
	}
}
