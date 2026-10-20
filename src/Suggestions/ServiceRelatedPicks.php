<?php
/**
 * The service-picked related picks.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

use Vscm\WooRelatedProducts\Admin\Settings;
use Vscm\WooRelatedProducts\Woo\RelatedPicks;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the stored suggestion links as related picks.
 */
class ServiceRelatedPicks implements RelatedPicks {

	/**
	 * The suggestion state repository.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * The suggestion link rows repository.
	 *
	 * @var Links
	 */
	private Links $links;

	/**
	 * The suggestion service HTTP client.
	 *
	 * @var ServiceClient
	 */
	private ServiceClient $service;

	/**
	 * Build.
	 *
	 * @param State         $state   suggestion state repository.
	 * @param Links         $links   suggestion link rows repository.
	 * @param ServiceClient $service suggestion service HTTP client.
	 */
	public function __construct( State $state, Links $links, ServiceClient $service ) {
		$this->state   = $state;
		$this->links   = $links;
		$this->service = $service;
	}

	/**
	 * The stored service-picked related products, dropping any that is no longer published, is
	 * hidden from the catalog, is out of stock while WooCommerce hides those, or is excluded.
	 * Returns null when the service picks are off, the state is not fresh/ok or nothing valid is left, so the
	 * caller falls back to the taxonomy path.
	 *
	 * @param int   $product_id Product ID.
	 * @param int   $limit      Max related products.
	 * @param int[] $excluded   IDs to drop, including the product itself.
	 * @return int[]|null
	 */
	public function picks( int $product_id, int $limit, array $excluded ): ?array {
		// The merchant's switch, with its default when it was never saved (C-063 R9).
		if ( empty( ( new Settings() )->get()['serviceEnabled'] ) ) {
			return null;
		}

		if ( ! $this->service->is_configured() ) {
			return null;
		}

		$state = $this->state->get( $product_id );
		if ( null === $state || 'ok' !== ( $state['status'] ?? null ) || ! $this->state->is_fresh( $product_id ) ) {
			return null;
		}

		$rows = $this->links->for_product( $product_id );
		if ( empty( $rows ) ) {
			return null;
		}

		$candidates = array();
		foreach ( $rows as $row ) {
			$related_id = (int) $row['related_id'];
			if ( ! in_array( $related_id, $excluded, true ) ) {
				$candidates[] = $related_id;
			}
		}
		$shown = $this->shown( $candidates );

		$ids = array();
		foreach ( $candidates as $related_id ) {
			if ( ! isset( $shown[ $related_id ] ) ) {
				continue;
			}
			$ids[] = $related_id;
			if ( count( $ids ) >= $limit ) {
				break;
			}
		}

		return empty( $ids ) ? null : $ids;
	}

	/**
	 * Which of the stored picks a shopper may see, in one query for all of them (C-063 R16): published, not hidden
	 * from the catalogue and search alike, and in stock when the shop hides what is out of stock. The visibility
	 * terms are WooCommerce's own, as its related-products query uses them.
	 *
	 * @param int[] $ids Product IDs.
	 * @return array<int, true> The visible ones.
	 */
	private function shown( array $ids ): array {
		global $wpdb;

		if ( empty( $ids ) ) {
			return array();
		}
		// @phpstan-ignore-next-line -- WooCommerce helper; not covered by the installed WP-only stub package.
		$terms = wc_get_product_visibility_term_ids();
		// A term WooCommerce does not have is 0, which no relationship carries: that rule then drops nothing.
		$catalog = (int) ( $terms['exclude-from-catalog'] ?? 0 );
		$search  = (int) ( $terms['exclude-from-search'] ?? 0 );
		$out     = 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ? (int) ( $terms['outofstock'] ?? 0 ) : 0;
		$ids     = array_map( 'intval', $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$visible = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one %d per id.
				'SELECT p.ID FROM %i p WHERE p.ID IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . " ) AND p.post_status = 'publish' AND p.ID NOT IN ( SELECT object_id FROM %i WHERE term_taxonomy_id IN ( %d, %d ) GROUP BY object_id HAVING COUNT( DISTINCT term_taxonomy_id ) = 2 ) AND p.ID NOT IN ( SELECT object_id FROM %i WHERE term_taxonomy_id = %d )",
				array_merge( array( $wpdb->posts ), $ids, array( $wpdb->term_relationships, $catalog, $search, $wpdb->term_relationships, $out ) )
			)
		);

		return array_fill_keys( array_map( 'intval', (array) $visible ), true );
	}
}
