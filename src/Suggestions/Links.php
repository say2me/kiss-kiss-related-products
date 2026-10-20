<?php
/**
 * Repository for the {prefix}vscm_related_links table.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the generated related-product links.
 */
class Links {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'vscm_related_links';
	}

	/**
	 * Replace all link rows for a product with a new ordered set.
	 *
	 * @param int                                                $product_id Product ID.
	 * @param array<int, array{related_id: int, reason: string}> $rows       Rows in order, position 0..n.
	 * @param string                                             $model      Model identifier.
	 * @return void
	 */
	public function replace( int $product_id, array $rows, string $model ): void {
		global $wpdb;

		$table = $this->table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE product_id = %d', $table, $product_id ) );

		foreach ( array_values( $rows ) as $position => $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
			$wpdb->insert(
				$table,
				array(
					'product_id'   => $product_id,
					'related_id'   => (int) $row['related_id'],
					'position'     => $position,
					'reason'       => (string) $row['reason'],
					'model'        => $model,
					'generated_at' => $now,
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Link rows for a product, in position order.
	 *
	 * @param int $product_id Product ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_product( int $product_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT related_id, position, reason, model, generated_at FROM %i WHERE product_id = %d ORDER BY position', $this->table(), $product_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Product IDs whose links reference the given related product.
	 *
	 * @param int $related_id Related product ID.
	 * @return int[]
	 */
	public function referrers( int $related_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT product_id FROM %i WHERE related_id = %d ORDER BY product_id', $this->table(), $related_id ) );

		return array_map( 'intval', $ids );
	}

	/**
	 * Delete a product's own link rows.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function delete_product( int $product_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE product_id = %d', $this->table(), $product_id ) );
	}

	/**
	 * Delete every link row pointing at a product.
	 *
	 * @param int $related_id Related product ID.
	 * @return void
	 */
	public function delete_related( int $related_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE related_id = %d', $this->table(), $related_id ) );
	}

	/**
	 * Count of distinct products that have link rows.
	 *
	 * @return int
	 */
	public function count_products(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT product_id) FROM %i', $this->table() ) );
	}
}
