<?php
/**
 * Tracks product/term changes during a request and queues new suggestions on shutdown.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

use Vscm\WooRelatedProducts\InitableInterface;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Collects touched products/terms through the request and evaluates them once on shutdown,
 * so the DNA reflects the final saved state.
 */
class ChangeTracker implements InitableInterface {

	/**
	 * Taxonomies whose term changes affect a product's DNA.
	 */
	private const DNA_TAXONOMIES = array( 'product_cat', 'product_brand', 'product_tag', 'product_visibility' );

	/**
	 * Taxonomies whose deleted terms backlog their published members (C-055 R5).
	 */
	private const NEIGHBOUR_TAXONOMIES = array( 'product_brand', 'product_cat' );

	/**
	 * Product IDs touched this request.
	 *
	 * @var array<int, true>
	 */
	private array $touched = array();

	/**
	 * Product ID => post status before the first change seen this request.
	 *
	 * @var array<int, string>
	 */
	private array $initial_status = array();

	/**
	 * Init.
	 */
	public function init() {
		add_action( 'save_post_product', array( $this, 'track_id' ) );
		add_action( 'woocommerce_update_product', array( $this, 'track_id' ) );
		add_action( 'woocommerce_new_product', array( $this, 'track_id' ) );
		add_action( 'wp_trash_post', array( $this, 'track_id' ) );
		add_action( 'before_delete_post', array( $this, 'track_id' ) );
		add_action( 'transition_post_status', array( $this, 'track_transition' ), 10, 3 );
		add_action( 'set_object_terms', array( $this, 'track_object_terms' ), 10, 4 );
		add_action( 'pre_delete_term', array( $this, 'track_deleted_term' ), 10, 2 );
		add_action( 'shutdown', array( $this, 'evaluate' ) );
	}

	/**
	 * Mark a product ID as touched this request.
	 *
	 * @param int $id Post/product ID.
	 * @return void
	 */
	public function track_id( $id ): void {
		$id = (int) $id;
		if ( 'product' !== get_post_type( $id ) ) {
			return;
		}
		$this->touched[ $id ] = true;
	}

	/**
	 * Record the status a product had before this request's first change.
	 *
	 * @param string $new_status New post status.
	 * @param string $old_status Old post status.
	 * @param mixed  $post       Post object.
	 * @return void
	 */
	public function track_transition( string $new_status, string $old_status, $post ): void {
		if ( ! ( $post instanceof WP_Post ) || 'product' !== $post->post_type ) {
			return;
		}

		$id = (int) $post->ID;

		$this->touched[ $id ] = true;
		if ( ! isset( $this->initial_status[ $id ] ) ) {
			$this->initial_status[ $id ] = $old_status;
		}
	}

	/**
	 * Track a product whose DNA-relevant taxonomy terms changed.
	 *
	 * @param int    $object_id Object ID.
	 * @param mixed  $terms     Term IDs or slugs.
	 * @param mixed  $tt_ids    Term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy slug.
	 * @return void
	 */
	public function track_object_terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
		$object_id = (int) $object_id;
		if ( 'product' !== get_post_type( $object_id ) ) {
			return;
		}
		if ( ! in_array( $taxonomy, self::DNA_TAXONOMIES, true ) && 0 !== strpos( (string) $taxonomy, 'pa_' ) ) {
			return;
		}
		$this->touched[ $object_id ] = true;
	}

	/**
	 * A brand/category term is about to be deleted: copy its published members into the backlog now,
	 * before the term relationships disappear. One set-based statement, so nothing proportional to the
	 * term's size is held in PHP; the worker queues them later, 500 per run, with cause 'terms' (C-055 R5).
	 * Renaming or otherwise editing a term queues nothing (R4).
	 *
	 * @param int    $term     Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function track_deleted_term( $term, string $taxonomy ): void {
		if ( ! in_array( $taxonomy, self::NEIGHBOUR_TAXONOMIES, true ) ) {
			return;
		}
		if ( ( new Queue() )->backlog_term( (int) $term, $taxonomy ) > 0 ) {
			/** This action is documented in src/Suggestions/Queue.php */
			do_action( 'vscm_related_service_queued', array(), 'terms' );
		}
	}

	/**
	 * Evaluate every touched product/term once, and queue new suggestions accordingly.
	 *
	 * @return void
	 */
	public function evaluate(): void {
		if ( empty( $this->touched ) ) {
			return;
		}

		$dna   = new Dna();
		$links = new Links();
		$queue = new Queue();

		$own_causes        = array();
		$removal_ids       = array();
		$neighbour_changed = array();
		$neighbour_removed = array();

		foreach ( array_keys( $this->touched ) as $id ) {
			$id     = (int) $id;
			$status = get_post_status( $id );

			if ( false === $status || 'publish' !== $status || $this->is_hidden( $id ) ) {
				$removal_ids[ $id ] = true;
				$neighbour_removed  = array_merge( $neighbour_removed, $links->referrers( $id ) );
				$links->delete_product( $id );
				$links->delete_related( $id );
				$queue->remove( $id );
				continue;
			}

			$prev_status = $this->initial_status[ $id ] ?? $status;
			$new_hash    = $dna->hash( $id );

			if ( 'publish' !== $prev_status ) {
				// A new product queues only itself (C-055 R1): its neighbours pick it up on their own
				// next generation, so nothing here grows with its category or brand.
				update_post_meta( $id, Dna::META_KEY, $new_hash );
				$own_causes[ $id ] = 'new';
				continue;
			}

			$old_hash = get_post_meta( $id, Dna::META_KEY, true );
			if ( $new_hash !== $old_hash ) {
				update_post_meta( $id, Dna::META_KEY, $new_hash );
				$own_causes[ $id ] = 'updated';
				$neighbour_changed = array_merge( $neighbour_changed, $links->referrers( $id ) );
			}
		}

		$neighbour_causes = array();
		foreach (
			array(
				'neighbour-changed' => $neighbour_changed,
				'neighbour-removed' => $neighbour_removed,
			) as $cause => $ids
		) {
			foreach ( array_unique( $ids ) as $id ) {
				$id = (int) $id;
				if ( isset( $own_causes[ $id ] ) || isset( $removal_ids[ $id ] ) || isset( $neighbour_causes[ $id ] ) ) {
					continue;
				}
				$neighbour_causes[ $id ] = $cause;
			}
		}

		$buckets = array();
		foreach ( $own_causes + $neighbour_causes as $id => $cause ) {
			$buckets[ $cause ][] = $id;
		}

		foreach ( $buckets as $cause => $ids ) {
			$queue->enqueue( $ids, $cause );
		}
	}

	/**
	 * Whether a product's catalog visibility is 'hidden'.
	 *
	 * @param int $id Product ID.
	 * @return bool
	 */
	private function is_hidden( int $id ): bool {
		// @phpstan-ignore-next-line -- WooCommerce helper; not covered by the installed WP-only stub package.
		$product = wc_get_product( $id );
		if ( false === $product ) {
			return false;
		}
		return 'hidden' === $product->get_catalog_visibility();
	}
}
