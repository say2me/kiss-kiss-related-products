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

	/**
	 * A page of published product IDs after an ID, in ID order: the catalogue walk reads the catalogue a page at a time
	 * (1.0.2).
	 *
	 * @param int $after The last ID of the previous page; 0 for the first.
	 * @param int $limit Page size.
	 * @return int[]
	 */
	public static function published_ids_after( int $after, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one bounded page of IDs, read once.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'product' AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d", $wpdb->posts, $after, $limit ) );

		return array_map( 'intval', $ids );
	}
}
