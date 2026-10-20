<?php
/**
 * Builds the candidate list and the stored related-products answer for a product.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

use Vscm\WooRelatedProducts\Woo\CrossSellingRelatedProducts;
use Vscm\WooRelatedProducts\Woo\RelatedCuration;

defined( 'ABSPATH' ) || exit;

/**
 * Computes candidates for a product and, via the suggestion service, the products to store as related.
 */
class Curator {

	/**
	 * Max candidates considered/sent to the service.
	 */
	private const CANDIDATE_LIMIT = 15;

	/**
	 * Minimum candidates to reach via the recency top-up, catalogue size permitting.
	 */
	private const MIN_CANDIDATES = 12;

	/**
	 * Related products requested from the service.
	 */
	private const SERVICE_LIMIT = 4;

	/**
	 * Max characters kept per reason.
	 */
	private const REASON_MAX = 140;

	/**
	 * Taxonomy-based related products source, shared with the plugin's own filter.
	 *
	 * @var CrossSellingRelatedProducts
	 */
	private CrossSellingRelatedProducts $cross_selling;

	/**
	 * Product card / DNA hash builder.
	 *
	 * @var Dna
	 */
	private Dna $dna;

	/**
	 * Link rows repository.
	 *
	 * @var Links
	 */
	private Links $links;

	/**
	 * Generation state repository.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Daily call counter.
	 *
	 * @var Calls
	 */
	private Calls $calls;

	/**
	 * The suggestion service HTTP client.
	 *
	 * @var ServiceClient
	 */
	private ServiceClient $service;

	/**
	 * Pin/exclude overrides repository.
	 *
	 * @var RelatedCuration
	 */
	private RelatedCuration $overrides;

	/**
	 * Build.
	 *
	 * @param CrossSellingRelatedProducts $cross_selling Taxonomy-based related products source.
	 * @param Dna                         $dna           Card/DNA builder.
	 * @param Links                       $links         Link rows repository.
	 * @param State                       $state         Generation state repository.
	 * @param Calls                       $calls         Daily call counter.
	 * @param ServiceClient               $service       suggestion service HTTP client.
	 * @param RelatedCuration             $overrides     Pinned and excluded products per product.
	 */
	public function __construct( CrossSellingRelatedProducts $cross_selling, Dna $dna, Links $links, State $state, Calls $calls, ServiceClient $service, RelatedCuration $overrides ) {
		$this->cross_selling = $cross_selling;
		$this->dna           = $dna;
		$this->links         = $links;
		$this->state         = $state;
		$this->calls         = $calls;
		$this->service       = $service;
		$this->overrides     = $overrides;
	}

	/**
	 * Candidate product IDs for a product, filled in order and de-duplicated as each source is
	 * added: taxonomy results, WooCommerce's own related products, same category, same brand and,
	 * when still short of MIN_CANDIDATES, the most recently published products. Every id is
	 * published, visible in the catalog, not the product itself and not one of its pinned or
	 * excluded overrides. Filterable via `vscm_related_service_candidates`; capped at 15.
	 *
	 * @param int $product_id Product ID.
	 * @return int[]
	 */
	public function candidates( int $product_id ): array {
		$overrides_excluded = array_merge(
			array( $product_id ),
			$this->overrides->pinned( $product_id ),
			$this->overrides->excluded( $product_id )
		);

		$taxonomy_ids = $this->cross_selling->taxonomy_related( $product_id, self::CANDIDATE_LIMIT, $overrides_excluded );
		$wc_ids       = $this->wc_related_ids( $product_id );

		$ids = $this->merge_eligible( array(), array_merge( $taxonomy_ids, $wc_ids ), $overrides_excluded );

		if ( count( $ids ) < self::CANDIDATE_LIMIT ) {
			$ids = $this->fill_from_term( $product_id, 'product_cat', $ids, $overrides_excluded, self::CANDIDATE_LIMIT );
		}

		if ( count( $ids ) < self::CANDIDATE_LIMIT ) {
			$ids = $this->fill_from_term( $product_id, 'product_brand', $ids, $overrides_excluded, self::CANDIDATE_LIMIT );
		}

		if ( count( $ids ) < self::MIN_CANDIDATES ) {
			$ids = $this->fill_from_recency( $ids, $overrides_excluded, self::MIN_CANDIDATES );
		}

		/**
		 * Filters the suggestion curator's candidate product IDs before they're capped and used. The
		 * incoming order is: taxonomy results, WooCommerce's own related products, same category,
		 * same brand, then a recency top-up. The returned list is re-checked (unique ints,
		 * eligible, capped at 15) before use, so a shop can trim or replace it freely.
		 *
		 * @param int[] $ids        Candidate product IDs.
		 * @param int   $product_id Product the candidates are being built for.
		 */
		$filtered_ids = apply_filters( 'vscm_related_service_candidates', $ids, $product_id );

		$ids = $this->merge_eligible( array(), array_map( 'intval', (array) $filtered_ids ), $overrides_excluded );

		return array_slice( $ids, 0, self::CANDIDATE_LIMIT );
	}

	/**
	 * Recompute a product's DNA, candidates and (when candidates exist) the suggestion service's answer,
	 * storing the result.
	 *
	 * @param int $product_id Product ID.
	 * @return array{status: string, related: int[], error: string, input_tokens: int, output_tokens: int, ms: int, cached: bool, service_error?: ServiceError}
	 */
	public function build( int $product_id ): array {
		$dna_hash = $this->dna->hash( $product_id );
		update_post_meta( $product_id, Dna::META_KEY, $dna_hash );

		$candidate_ids = $this->candidates( $product_id );

		if ( empty( $candidate_ids ) ) {
			$this->links->replace( $product_id, array(), '' );
			$this->state->set(
				$product_id,
				array(
					'dna'             => $dna_hash,
					'candidates_hash' => $this->candidates_hash( array() ),
					'provider'        => '',
					'model'           => '',
					'generated_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'status'          => 'empty',
					'input_tokens'    => 0,
					'output_tokens'   => 0,
					'ms'              => 0,
					'cached'          => false,
				)
			);

			return array(
				'status'        => 'empty',
				'related'       => array(),
				'error'         => '',
				'input_tokens'  => 0,
				'output_tokens' => 0,
				'ms'            => 0,
				'cached'        => false,
			);
		}

		$candidates_hash = $this->candidates_hash( $candidate_ids );
		$card            = $this->dna->card( $product_id );
		$candidate_cards = array_map( array( $this->dna, 'card' ), $candidate_ids );

		try {
			$response = $this->service->related( $card, $candidate_cards, self::SERVICE_LIMIT );
		} catch ( ServiceError $error ) {
			return array(
				'status'        => 'failed',
				'related'       => array(),
				'error'         => $error->getMessage(),
				'input_tokens'  => 0,
				'output_tokens' => 0,
				'ms'            => 0,
				'cached'        => false,
				// The worker reacts to what the service said (C-063 R10).
				'service_error' => $error,
			);
		}

		$model    = (string) ( $response['model'] ?? '' );
		$provider = (string) ( $response['provider'] ?? '' );
		$usage    = is_array( $response['usage'] ?? null ) ? $response['usage'] : array();
		$input    = (int) ( $usage['input_tokens'] ?? 0 );
		$output   = (int) ( $usage['output_tokens'] ?? 0 );
		$ms       = (int) ( $response['ms'] ?? 0 );
		$cached   = (bool) ( $response['cached'] ?? false );

		$this->calls->record( $provider, $model, $input, $output, $cached, $ms );

		$candidate_lookup = array_flip( $candidate_ids );
		$rows             = array();
		$related_ids      = array();
		$seen             = array();

		foreach ( (array) ( $response['related'] ?? array() ) as $item ) {
			$item = is_array( $item ) ? $item : array();
			$rid  = (int) ( $item['product_id'] ?? 0 );

			if ( $rid === $product_id || isset( $seen[ $rid ] ) ) {
				continue;
			}
			if ( ! isset( $candidate_lookup[ $rid ] ) ) {
				continue;
			}
			if ( ! $this->is_eligible( $rid ) ) {
				continue;
			}

			$seen[ $rid ]  = true;
			$rows[]        = array(
				'related_id' => $rid,
				'reason'     => $this->clean_reason( (string) ( $item['reason'] ?? '' ) ),
			);
			$related_ids[] = $rid;
		}

		$this->links->replace( $product_id, $rows, $model );

		$status = empty( $rows ) ? 'empty' : 'ok';

		$this->state->set(
			$product_id,
			array(
				'dna'             => $dna_hash,
				'candidates_hash' => $candidates_hash,
				'provider'        => $provider,
				'model'           => $model,
				'generated_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'status'          => $status,
				'input_tokens'    => $input,
				'output_tokens'   => $output,
				'ms'              => $ms,
				'cached'          => $cached,
			)
		);

		return array(
			'status'        => $status,
			'related'       => $related_ids,
			'error'         => '',
			'input_tokens'  => $input,
			'output_tokens' => $output,
			'ms'            => $ms,
			'cached'        => $cached,
		);
	}

	/**
	 * WooCommerce's own related products, computed with this plugin's filter removed so the
	 * result reflects WooCommerce's algorithm rather than this feature recursing into itself.
	 * `wc_get_related_products()` orders its result by `RAND()`, so the ids arrive in a
	 * different order on every call; sort them by the same date/ID tie-breaker as the other
	 * sources so this block's contribution to the candidate list is reproducible. Membership is
	 * unchanged, only the order.
	 *
	 * @param int $product_id Product ID.
	 * @return int[]
	 */
	private function wc_related_ids( int $product_id ): array {
		remove_filter( 'woocommerce_related_products', array( $this->cross_selling, 'cross_selling_woocommerce_related_products' ), 10 );
		// @phpstan-ignore-next-line -- WooCommerce helper; not covered by the installed WP-only stub package.
		$ids = wc_get_related_products( $product_id, self::CANDIDATE_LIMIT );
		add_filter( 'woocommerce_related_products', array( $this->cross_selling, 'cross_selling_woocommerce_related_products' ), 10, 3 );

		return $this->sort_by_date_id_desc( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Sort product IDs by publish date, newest first; ties break by ID, newest first. Ties are
	 * the norm in imported catalogues; without an explicit tie-breaker the order is unspecified
	 * and a shopper's related block can reorder for no reason.
	 *
	 * @param int[] $ids Product IDs to sort.
	 * @return int[]
	 */
	private function sort_by_date_id_desc( array $ids ): array {
		$dates = array();
		foreach ( $ids as $id ) {
			$post         = get_post( $id );
			$dates[ $id ] = $post ? $post->post_date : '';
		}

		usort(
			$ids,
			static function ( int $a, int $b ) use ( $dates ): int {
				$cmp = $dates[ $b ] <=> $dates[ $a ];
				return 0 !== $cmp ? $cmp : $b <=> $a;
			}
		);

		return $ids;
	}

	/**
	 * Append ids to a list, skipping ones already present, excluded/overridden or ineligible.
	 *
	 * @param int[]   $ids          Existing, already-filtered candidate IDs.
	 * @param mixed[] $new_ids      Candidate IDs to add.
	 * @param int[]   $excluded_ids IDs to drop: the product itself plus its pinned/excluded overrides.
	 * @return int[]
	 */
	private function merge_eligible( array $ids, array $new_ids, array $excluded_ids ): array {
		foreach ( $new_ids as $new_id ) {
			$new_id = (int) $new_id;

			if ( in_array( $new_id, $ids, true ) || in_array( $new_id, $excluded_ids, true ) ) {
				continue;
			}
			if ( ! $this->is_eligible( $new_id ) ) {
				continue;
			}

			$ids[] = $new_id;
		}

		return $ids;
	}

	/**
	 * Fill from a taxonomy (category/brand) by fetching with a growing margin to account for
	 * ids that turn out ineligible. Loops, widening the fetch each round, until the list is
	 * full or the source is exhausted.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $taxonomy     Taxonomy slug.
	 * @param int[]  $ids          Current candidate IDs.
	 * @param int[]  $excluded_ids IDs to exclude.
	 * @param int    $target_count Target candidate count.
	 * @return int[]
	 */
	private function fill_from_term( int $product_id, string $taxonomy, array $ids, array $excluded_ids, int $target_count ): array {
		$margin   = 0;
		$id_count = count( $ids );

		while ( $id_count < $target_count ) {
			$needed     = $target_count - $id_count;
			$fetch_size = $needed + $margin;
			$term_ids   = $this->same_term_ids( $product_id, $taxonomy, array_merge( $excluded_ids, $ids ), $fetch_size );

			if ( empty( $term_ids ) ) {
				break;
			}

			$before_count  = count( $ids );
			$ids           = $this->merge_eligible( $ids, $term_ids, $excluded_ids );
			$added_count   = count( $ids ) - $before_count;
			$fetched_count = count( $term_ids );

			$margin  += $fetched_count - $added_count;
			$id_count = count( $ids );

			if ( $fetched_count < $fetch_size ) {
				break;
			}
		}

		return $ids;
	}

	/**
	 * Fill from recency by fetching with a growing margin to account for ids that turn out
	 * ineligible. Loops, widening the fetch each round, until the list is full or the source is
	 * exhausted.
	 *
	 * @param int[] $ids          Current candidate IDs.
	 * @param int[] $excluded_ids IDs to exclude.
	 * @param int   $target_count Target candidate count.
	 * @return int[]
	 */
	private function fill_from_recency( array $ids, array $excluded_ids, int $target_count ): array {
		$margin   = 0;
		$id_count = count( $ids );

		while ( $id_count < $target_count ) {
			$needed     = $target_count - $id_count;
			$fetch_size = $needed + $margin;
			$recent     = $this->recent_ids( array_merge( $excluded_ids, $ids ), $fetch_size );

			if ( empty( $recent ) ) {
				break;
			}

			$before_count  = count( $ids );
			$ids           = $this->merge_eligible( $ids, $recent, $excluded_ids );
			$added_count   = count( $ids ) - $before_count;
			$fetched_count = count( $recent );

			$margin  += $fetched_count - $added_count;
			$id_count = count( $ids );

			if ( $fetched_count < $fetch_size ) {
				break;
			}
		}

		return $ids;
	}

	/**
	 * Published products sharing any of the product's terms in the given taxonomy.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $taxonomy     Taxonomy slug (`product_cat` or `product_brand`).
	 * @param int[]  $excluded_ids IDs to exclude from the query.
	 * @param int    $limit        Max results.
	 * @return int[]
	 */
	private function same_term_ids( int $product_id, string $taxonomy, array $excluded_ids, int $limit ): array {
		if ( $limit <= 0 ) {
			return array();
		}

		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'fields'                 => 'ids',
				'post_type'              => 'product',
				'post_status'            => 'publish',
				// The excluded products are cut below rather than with post__not_in, which Plugin Check
				// flags as a slow query (as the engine does since C-067): that many more keeps $limit.
				'posts_per_page'         => $limit + count( $excluded_ids ),
				// Ties are the norm in imported catalogues; without an explicit tie-breaker the
				// order is unspecified and a shopper's related block can reorder for no reason.
				'orderby'                => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'tax_query'              => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'id',
						'terms'    => wp_list_pluck( $terms, 'term_id' ),
					),
				),
			)
		);

		return $this->without( (array) $ids, $excluded_ids, $limit );
	}

	/**
	 * The ids in their order, without the excluded ones, at most $limit.
	 *
	 * @param array<int|string> $ids          Query result.
	 * @param int[]             $excluded_ids IDs to leave out.
	 * @param int               $limit        Max results.
	 * @return int[]
	 */
	private function without( array $ids, array $excluded_ids, int $limit ): array {
		$excluded = array_flip( array_map( 'intval', $excluded_ids ) );
		$kept     = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! isset( $excluded[ $id ] ) ) {
				$kept[] = $id;
			}
		}

		return array_slice( $kept, 0, $limit );
	}

	/**
	 * The most recently published products, newest first.
	 *
	 * @param int[] $excluded_ids IDs to exclude from the query.
	 * @param int   $limit        Max results.
	 * @return int[]
	 */
	private function recent_ids( array $excluded_ids, int $limit ): array {
		if ( $limit <= 0 ) {
			return array();
		}

		$ids = get_posts(
			array(
				'fields'                 => 'ids',
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'posts_per_page'         => $limit + count( $excluded_ids ),
				'orderby'                => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return $this->without( (array) $ids, $excluded_ids, $limit );
	}

	/**
	 * Published and visible in the catalog.
	 *
	 * @param int $id Product ID.
	 * @return bool
	 */
	private function is_eligible( int $id ): bool {
		if ( 'publish' !== get_post_status( $id ) ) {
			return false;
		}

		// @phpstan-ignore-next-line -- WooCommerce helper; not covered by the installed WP-only stub package.
		$product = wc_get_product( $id );
		if ( false === $product ) {
			return false;
		}

		return 'hidden' !== $product->get_catalog_visibility();
	}

	/**
	 * SHA1 of the sorted candidate IDs.
	 *
	 * @param int[] $ids Candidate IDs.
	 * @return string
	 */
	private function candidates_hash( array $ids ): string {
		$ids = array_map( 'intval', $ids );
		sort( $ids );

		return sha1( implode( ',', $ids ) );
	}

	/**
	 * Strip HTML and control characters, collapse whitespace, cap at 140 characters.
	 *
	 * @param string $reason Raw reason text.
	 * @return string
	 */
	private function clean_reason( string $reason ): string {
		$reason = wp_strip_all_tags( $reason );
		$reason = sanitize_text_field( $reason );
		$reason = trim( (string) preg_replace( '/\s+/', ' ', $reason ) );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $reason, 0, self::REASON_MAX );
		}

		return substr( $reason, 0, self::REASON_MAX );
	}
}
