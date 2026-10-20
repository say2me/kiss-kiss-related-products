<?php
/**
 * Stores and reads the per-product suggestion state.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Generation state: {dna, candidates_hash, provider, model, generated_at, status}.
 */
class State {

	/**
	 * Post meta key.
	 */
	private const META_KEY = '_vscm_service_state';

	/**
	 * Read the stored state.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, mixed>|null
	 */
	public function get( int $product_id ): ?array {
		$state = get_post_meta( $product_id, self::META_KEY, true );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * Store the state.
	 *
	 * @param int                  $product_id Product ID.
	 * @param array<string, mixed> $state      State.
	 * @return void
	 */
	public function set( int $product_id, array $state ): void {
		update_post_meta( $product_id, self::META_KEY, $state );
	}

	/**
	 * Fresh: status is ok or empty, and the stored state's dna matches the current DNA meta.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public function is_fresh( int $product_id ): bool {
		$state = $this->get( $product_id );
		if ( null === $state ) {
			return false;
		}

		if ( ! in_array( $state['status'] ?? null, array( 'ok', 'empty' ), true ) ) {
			return false;
		}

		$dna = get_post_meta( $product_id, Dna::META_KEY, true );

		return isset( $state['dna'] ) && $state['dna'] === $dna;
	}

	/**
	 * How many of the given products are fresh, in constant memory.
	 *
	 * Walks the ids in batches: primes the batch's postmeta in one query, counts with is_fresh()
	 * (the only definition of "fresh"), then releases the batch from the object cache so memory
	 * does not grow with the catalogue.
	 *
	 * @param array<int, int> $ids Product IDs.
	 * @return int
	 */
	public function count_fresh( array $ids ): int {
		/**
		 * Filters how many products one freshness batch loads; lower it on a constrained host.
		 *
		 * @param int $size Batch size, default 1000.
		 */
		$size  = max( 1, (int) apply_filters( 'vscm_related_fresh_batch', 1000 ) );
		$fresh = 0;

		foreach ( array_chunk( array_map( 'intval', $ids ), $size ) as $batch ) {
			update_meta_cache( 'post', $batch );

			foreach ( $batch as $product_id ) {
				if ( $this->is_fresh( $product_id ) ) {
					++$fresh;
				}
			}

			if ( function_exists( 'wp_cache_delete_multiple' ) ) {
				wp_cache_delete_multiple( $batch, 'post_meta' );
			} else {
				foreach ( $batch as $product_id ) {
					wp_cache_delete( $product_id, 'post_meta' );
				}
			}
		}

		return $fresh;
	}

	/**
	 * Delete the stored state.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function delete( int $product_id ): void {
		delete_post_meta( $product_id, self::META_KEY );
	}
}
