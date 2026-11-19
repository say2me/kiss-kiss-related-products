<?php
/**
 * The products a shop manager pins to, or excludes from, a product's related list, for every shop (the owner,
 * 2026-11-16).
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Stores/reads the pinned and excluded product lists for a product.
 */
class Overrides implements RelatedCuration {

	/**
	 * Post meta key for the pinned list.
	 */
	private const PINNED_KEY = '_vscm_related_pinned';

	/**
	 * Post meta key for the excluded list.
	 */
	private const EXCLUDED_KEY = '_vscm_related_excluded';

	/**
	 * Max ids kept per list.
	 */
	private const MAX_IDS = 12;

	/**
	 * A product's pinned product IDs.
	 *
	 * @param int $id Product ID.
	 * @return int[]
	 */
	public function pinned( int $id ): array {
		return $this->read( $id, self::PINNED_KEY );
	}

	/**
	 * A product's excluded product IDs.
	 *
	 * @param int $id Product ID.
	 * @return int[]
	 */
	public function excluded( int $id ): array {
		return $this->read( $id, self::EXCLUDED_KEY );
	}

	/**
	 * Save the pinned/excluded lists. A null argument keeps the stored list.
	 *
	 * @param int        $id       Product ID.
	 * @param int[]|null $pinned   New pinned list, or null to keep the stored one.
	 * @param int[]|null $excluded New excluded list, or null to keep the stored one.
	 * @return array{pinned: int[], excluded: int[]}
	 */
	public function save( int $id, ?array $pinned, ?array $excluded ): array {
		$clean_pinned   = null === $pinned ? $this->pinned( $id ) : $this->clean_list( $id, $pinned );
		$clean_excluded = null === $excluded ? $this->excluded( $id ) : $this->clean_list( $id, $excluded );

		$clean_pinned = array_values( array_diff( $clean_pinned, $clean_excluded ) );

		$this->store( $id, self::PINNED_KEY, $clean_pinned );
		$this->store( $id, self::EXCLUDED_KEY, $clean_excluded );

		return array(
			'pinned'   => $clean_pinned,
			'excluded' => $clean_excluded,
		);
	}

	/**
	 * Read a stored list as ints.
	 *
	 * @param int    $id  Product ID.
	 * @param string $key Meta key.
	 * @return int[]
	 */
	private function read( int $id, string $key ): array {
		$value = get_post_meta( $id, $key, true );

		return is_array( $value ) ? array_map( 'intval', $value ) : array();
	}

	/**
	 * Unique ids of published products other than $id, in order, capped.
	 *
	 * @param int          $id  Product ID being edited.
	 * @param array<mixed> $ids Candidate ids.
	 * @return int[]
	 */
	private function clean_list( int $id, array $ids ): array {
		$out = array();

		foreach ( $ids as $candidate ) {
			$candidate = (int) $candidate;

			if ( $candidate === $id || in_array( $candidate, $out, true ) ) {
				continue;
			}
			if ( 'publish' !== get_post_status( $candidate ) || 'product' !== get_post_type( $candidate ) ) {
				continue;
			}

			$out[] = $candidate;
			if ( count( $out ) >= self::MAX_IDS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Store a list, deleting the meta entirely when empty.
	 *
	 * @param int    $id   Product ID.
	 * @param string $key  Meta key.
	 * @param int[]  $ids  IDs to store.
	 * @return void
	 */
	private function store( int $id, string $key, array $ids ): void {
		if ( empty( $ids ) ) {
			delete_post_meta( $id, $key );
			return;
		}

		update_post_meta( $id, $key, $ids );
	}
}
