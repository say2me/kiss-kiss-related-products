<?php
/**
 * Cross-selling related products based on brands and similar categories
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Woo;

use Vscm\WooRelatedProducts\InitableInterface;
use WC_Cache_Helper;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Cross Selling Related Products class.
 */
class CrossSellingRelatedProducts implements InitableInterface {

	/**
	 * Transient name prefix, suffixed with the product ID.
	 */
	private const TRANSIENT_PREFIX = 'vscm_related_';

	/**
	 * Flag to prevent shuffle when picks are returned.
	 *
	 * @var bool
	 */
	private bool $skip_shuffle = false;

	/**
	 * Ready-made related picks source.
	 *
	 * @var RelatedPicks
	 */
	private RelatedPicks $picks;

	/**
	 * Pinned/excluded products source.
	 *
	 * @var RelatedCuration
	 */
	private RelatedCuration $curation;

	/**
	 * Product IDs served by the picks source this request.
	 *
	 * @var array<int, bool>
	 */
	private array $picks_served_products = array();

	/**
	 * Build.
	 *
	 * @param RelatedPicks    $picks    Ready-made related picks source.
	 * @param RelatedCuration $curation Pinned/excluded products source.
	 */
	public function __construct( RelatedPicks $picks, RelatedCuration $curation ) {
		$this->picks    = $picks;
		$this->curation = $curation;
	}

	/**
	 * Whether the picks source served this product's related list on this request.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public function picks_served( int $product_id ): bool {
		return ! empty( $this->picks_served_products[ $product_id ] );
	}

	/**
	 * Combine the request's excluded_ids with this product's own excluded overrides.
	 *
	 * @param int   $product_id       Product ID.
	 * @param int[] $request_excluded Request-level excluded IDs.
	 * @return int[]
	 */
	private function excluded_ids_for( int $product_id, array $request_excluded ): array {
		return array_values( array_unique( array_map( 'intval', array_merge( $request_excluded, $this->curation->excluded( $product_id ) ) ) ) );
	}

	/**
	 * The product's pinned products eligible to be shown: published, visible, in stock when
	 * WooCommerce hides out-of-stock items, and not excluded.
	 *
	 * @param int   $product_id   Product ID.
	 * @param int[] $excluded_ids Excluded IDs.
	 * @return int[]
	 */
	private function eligible_pinned( int $product_id, array $excluded_ids ): array {
		$pinned = $this->curation->pinned( $product_id );
		if ( empty( $pinned ) ) {
			return array();
		}

		$hide_out_of_stock = 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' );
		$out               = array();

		foreach ( $pinned as $pinned_id ) {
			if ( in_array( $pinned_id, $excluded_ids, true ) ) {
				continue;
			}
			if ( 'publish' !== get_post_status( $pinned_id ) ) {
				continue;
			}

			// @phpstan-ignore-next-line -- WooCommerce helper; not covered by the installed WP-only stub package.
			$pinned_product = wc_get_product( $pinned_id );
			if ( false === $pinned_product || 'hidden' === $pinned_product->get_catalog_visibility() ) {
				continue;
			}
			if ( $hide_out_of_stock && ! $pinned_product->is_in_stock() ) {
				continue;
			}

			$out[] = $pinned_id;
		}

		return $out;
	}

	/**
	 * Init.
	 */
	public function init() {
		add_filter( 'woocommerce_related_products', array( $this, 'cross_selling_woocommerce_related_products' ), 10, 3 );
		add_filter( 'woocommerce_product_related_posts_shuffle', array( $this, 'maybe_skip_shuffle' ), 10, 1 );
		add_filter( 'woocommerce_output_related_products_args', array( $this, 'maybe_force_picks_order' ), 20, 1 );
		add_action( 'woocommerce_delete_product_transients', array( $this, 'invalidate_related_products_cache' ) );
		add_action( 'set_object_terms', array( $this, 'invalidate_related_products_cache_on_terms' ), 10, 4 );
		add_action( 'woocommerce_before_template_part', array( $this, 'wrap_related_products_start' ) );
		add_action( 'woocommerce_after_template_part', array( $this, 'wrap_related_products_end' ) );
	}

	/**
	 * Prevent shuffle when picks are being used. Returns false and clears the flag when
	 * set, otherwise returns the incoming shuffle value unchanged.
	 *
	 * @param bool $should_shuffle Whether to shuffle the related products.
	 * @return bool
	 */
	public function maybe_skip_shuffle( bool $should_shuffle ): bool {
		if ( $this->skip_shuffle ) {
			$this->skip_shuffle = false;
			return false;
		}
		return $should_shuffle;
	}

	/**
	 * On the single-product template, WooCommerce re-sorts the fetched related IDs with
	 * `wc_products_array_orderby( ..., 'rand', 'desc' )` after `wc_get_related_products()`
	 * returns, which scrambles the picked order a second time (the shuffle flag above only stops
	 * the shuffle inside `wc_get_related_products()` itself). When picks would serve this
	 * product, force a stable, non-reversing orderby/order here so that second pass is a no-op;
	 * otherwise leave the args untouched so the taxonomy/disabled paths keep today's randomised
	 * order.
	 *
	 * @param array<string, mixed> $args Related products template args.
	 * @return array<string, mixed>
	 */
	public function maybe_force_picks_order( array $args ): array {
		global $product;

		// @phpstan-ignore-next-line -- WooCommerce class; not covered by the installed WP-only stub package.
		if ( ! ( $product instanceof WC_Product ) ) {
			return $args;
		}

		$settings = get_option( 'vscm_woo_related_products_settings' );
		$settings = is_array( $settings ) ? $settings : array();

		if ( isset( $settings['enableFeature'] ) && ! $settings['enableFeature'] ) {
			return $args;
		}

		// @phpstan-ignore-next-line -- WooCommerce method; not covered by the installed WP-only stub package.
		$product_id     = $product->get_id();
		$posts_per_page = (int) ( $args['posts_per_page'] ?? 4 );
		// @phpstan-ignore-next-line -- WooCommerce method; not covered by the installed WP-only stub package.
		$request_excluded = array_merge( array( $product_id ), (array) $product->get_upsell_ids() );
		$excluded_ids     = $this->excluded_ids_for( $product_id, $request_excluded );

		$pinned_shown = array_slice( $this->eligible_pinned( $product_id, $excluded_ids ), 0, $posts_per_page );
		$fill_limit   = $posts_per_page - count( $pinned_shown );

		$picks_used = false;
		if ( $fill_limit > 0 ) {
			$fill_excluded = array_values( array_unique( array_merge( $excluded_ids, $pinned_shown ) ) );
			$picks_used    = null !== $this->picks->picks( $product_id, $fill_limit, $fill_excluded );
		}

		if ( $picks_used || ! empty( $pinned_shown ) ) {
			$args['orderby'] = 'none';
			$args['order']   = 'asc';
		}

		return $args;
	}

	/**
	 * Delete a single product's related-products cache.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function invalidate_related_products_cache( $product_id ) {
		delete_transient( self::TRANSIENT_PREFIX . (int) $product_id );
	}

	/**
	 * Delete a product's related-products cache when its brand or category terms change.
	 *
	 * @param int            $object_id Object ID.
	 * @param int[]|string[] $terms     Term IDs or slugs.
	 * @param int[]          $tt_ids    Term taxonomy IDs.
	 * @param string         $taxonomy  Taxonomy slug.
	 * @return void
	 */
	public function invalidate_related_products_cache_on_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( in_array( $taxonomy, array( 'product_brand', 'product_cat' ), true ) ) {
			delete_transient( self::TRANSIENT_PREFIX . (int) $object_id );
		}
	}

	/**
	 * Build the cache key for the current inputs, so a different limit or exclusion list misses.
	 *
	 * @param \WP_Term[]|false $categories      Product categories.
	 * @param \WP_Term[]|false $product_brands  Product brands.
	 * @param int              $posts_per_page  Limit.
	 * @param int[]            $excluded_ids    Excluded IDs.
	 * @param int[]            $exclude_tt_ids  Excluded visibility term_taxonomy_ids.
	 * @return string
	 */
	private function build_related_products_cache_key( $categories, $product_brands, int $posts_per_page, array $excluded_ids, array $exclude_tt_ids ): string {
		return md5(
			(string) wp_json_encode(
				array(
					is_array( $product_brands ) ? wp_list_pluck( $product_brands, 'term_id' ) : array(),
					is_array( $categories ) ? wp_list_pluck( $categories, 'term_id' ) : array(),
					$posts_per_page,
					array_values( $excluded_ids ),
					array_values( $exclude_tt_ids ),
				)
			)
		);
	}

	/**
	 * Read the cached related-product IDs, if the stored entry is for the same inputs and product-cache version.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $cache_key  Cache key from build_related_products_cache_key().
	 * @return int[]|null Cached IDs, or null on a miss.
	 */
	private function get_cached_related_products( int $product_id, string $cache_key ): ?array {
		$cached = get_transient( self::TRANSIENT_PREFIX . $product_id );
		if ( ! is_array( $cached ) || ! isset( $cached['v'], $cached['k'], $cached['ids'] ) ) {
			return null;
		}
		// @phpstan-ignore-next-line -- WooCommerce helper; not covered by the installed WP-only stub package.
		if ( WC_Cache_Helper::get_transient_version( 'product' ) !== $cached['v'] || $cache_key !== $cached['k'] ) {
			return null;
		}
		return array_map( 'intval', (array) $cached['ids'] );
	}

	/**
	 * Store the computed related-product IDs for this product and cache key.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $cache_key  Cache key from build_related_products_cache_key().
	 * @param int[]  $ids        Related product IDs.
	 * @return void
	 */
	private function store_related_products_cache( int $product_id, string $cache_key, array $ids ): void {
		set_transient(
			self::TRANSIENT_PREFIX . $product_id,
			array(
				// @phpstan-ignore-next-line -- WooCommerce helper; not covered by the installed WP-only stub package.
				'v'   => WC_Cache_Helper::get_transient_version( 'product' ),
				'k'   => $cache_key,
				'ids' => $ids,
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Filter related products based on brands and similar categories, or, when the picks source
	 * has any, those picks.
	 *
	 * @param int[]                $related_posts Array of related product IDs.
	 * @param int                  $product_id    Current product ID.
	 * @param array<string, mixed> $args          Query arguments.
	 * @return int[] Filtered array of related product IDs.
	 */
	public function cross_selling_woocommerce_related_products( $related_posts, $product_id, $args ) {
		$settings = get_option( 'vscm_woo_related_products_settings' );
		$settings = is_array( $settings ) ? $settings : array();

		if ( isset( $settings['enableFeature'] ) && ! $settings['enableFeature'] ) {
			$this->skip_shuffle = false;
			return array_map( 'intval', (array) $related_posts );
		}

		$product_id       = (int) $product_id;
		$posts_per_page   = (int) ( $args['limit'] ?? 4 );
		$request_excluded = array_merge( array( $product_id ), (array) ( $args['excluded_ids'] ?? array() ) );
		$excluded_ids     = $this->excluded_ids_for( $product_id, $request_excluded );

		$pinned_shown  = array_slice( $this->eligible_pinned( $product_id, $excluded_ids ), 0, $posts_per_page );
		$fill_excluded = array_values( array_unique( array_merge( $excluded_ids, $pinned_shown ) ) );
		$fill_limit    = $posts_per_page - count( $pinned_shown );

		$picks_served = false;
		$fill         = array();

		if ( $fill_limit > 0 ) {
			$picked = $this->picks->picks( $product_id, $fill_limit, $fill_excluded );
			if ( null !== $picked ) {
				$fill         = $picked;
				$picks_served = true;
			}

			if ( ! $picks_served ) {
				$fill = $this->taxonomy_path( $product_id, $fill_limit, $fill_excluded, array_map( 'intval', (array) $related_posts ) );
			}
		}

		$this->picks_served_products[ $product_id ] = $picks_served;
		$this->skip_shuffle                         = $picks_served || ! empty( $pinned_shown );

		return array_slice( array_merge( $pinned_shown, $fill ), 0, $posts_per_page );
	}

	/**
	 * The taxonomy-based path exactly as before: cached brand/category results, falling back to
	 * the incoming list.
	 *
	 * @param int   $product_id     Product ID.
	 * @param int   $posts_per_page Limit.
	 * @param int[] $excluded_ids   Excluded IDs, including the pinned ones already shown.
	 * @param int[] $related_posts  Incoming related product IDs, used as the final fallback.
	 * @return int[]
	 */
	private function taxonomy_path( int $product_id, int $posts_per_page, array $excluded_ids, array $related_posts ): array {
		$categories     = get_the_terms( $product_id, 'product_cat' );
		$product_brands = get_the_terms( $product_id, 'product_brand' );
		$exclude_tt_ids = $this->exclude_visibility_tt_ids();

		$cache_key = $this->build_related_products_cache_key( $categories, $product_brands, $posts_per_page, $excluded_ids, $exclude_tt_ids );
		$cached    = $this->get_cached_related_products( $product_id, $cache_key );
		if ( null !== $cached ) {
			return $cached;
		}

		$ids = $this->taxonomy_related( $product_id, $posts_per_page, $excluded_ids );
		if ( ! empty( $ids ) ) {
			$this->store_related_products_cache( $product_id, $cache_key, $ids );
			return $ids;
		}

		// Last resort: the incoming list, minus the pinned and excluded products.
		return array_slice( array_values( array_diff( $related_posts, $excluded_ids ) ), 0, $posts_per_page );
	}

	/**
	 * Brand/category taxonomy related products, uncached. Shared by the public filter and by
	 * other callers that need a higher limit.
	 *
	 * @param int   $product_id   Product ID.
	 * @param int   $limit        Max results.
	 * @param int[] $excluded_ids Excluded IDs.
	 * @return int[]
	 */
	public function taxonomy_related( int $product_id, int $limit, array $excluded_ids ): array {
		$categories     = get_the_terms( $product_id, 'product_cat' );
		$product_brands = get_the_terms( $product_id, 'product_brand' );
		$exclude_tt_ids = $this->exclude_visibility_tt_ids();
		// The excluded products are removed here rather than with post__not_in, which Plugin Check
		// flags as a slow query (C-067). Fetching that many more keeps $limit picks after the cut.
		$excluded_ids    = array_values( array_unique( array_map( 'intval', $excluded_ids ) ) );
		$base_query_args = array(
			'fields'                 => 'ids',
			'post_type'              => 'product',
			'posts_per_page'         => $limit + count( $excluded_ids ),
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'post_status'            => 'publish',
			// Ties are the norm in imported catalogues; without an explicit tie-breaker the order
			// is unspecified and a shopper's related block can reorder for no reason.
			'orderby'                => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
		);

		// Brand-based fallback.
		if ( is_array( $product_brands ) && ! empty( $product_brands ) ) {
			$brand_tax_query = array(
				'relation' => 'AND',
				array(
					'taxonomy' => 'product_brand',
					'field'    => 'id',
					'terms'    => wp_list_pluck( $product_brands, 'term_id' ),
				),
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'id',
					'terms'    => ( is_array( $categories ) && isset( $categories[0]->term_id ) ) ? $categories[0]->term_id : array(),
					'operator' => 'NOT IN',
				),
			);
			if ( ! empty( $exclude_tt_ids ) ) {
				$brand_tax_query[] = array(
					'taxonomy' => 'product_visibility',
					'field'    => 'term_taxonomy_id',
					'terms'    => $exclude_tt_ids,
					'operator' => 'NOT IN',
				);
			}
			$brand_query_args = array_merge(
				$base_query_args,
				array(
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					'tax_query' => $brand_tax_query,
				)
			);
			$brand_results    = $this->without( get_posts( $brand_query_args ), $excluded_ids, $limit );
			if ( ! empty( $brand_results ) ) {
				return $brand_results;
			}
		}

		// Category-based fallback.
		if ( is_array( $categories ) && ! empty( $categories ) ) {
			$categories = array_values( $categories );
			array_shift( $categories );
			if ( ! empty( $categories ) ) {
				$category_tax_query = array(
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'id',
						'terms'    => wp_list_pluck( $categories, 'term_id' ),
					),
				);
				if ( ! empty( $exclude_tt_ids ) ) {
					$category_tax_query[] = array(
						'taxonomy' => 'product_visibility',
						'field'    => 'term_taxonomy_id',
						'terms'    => $exclude_tt_ids,
						'operator' => 'NOT IN',
					);
				}
				$category_query_args = array_merge(
					$base_query_args,
					array(
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						'tax_query' => $category_tax_query,
					)
				);
				$category_results    = $this->without( get_posts( $category_query_args ), $excluded_ids, $limit );
				if ( ! empty( $category_results ) ) {
					return $category_results;
				}
			}
		}

		return array();
	}

	/**
	 * Query results without the excluded products, in their order, at most $limit.
	 *
	 * @param array<int|string> $ids          Post IDs from get_posts().
	 * @param int[]             $excluded_ids Excluded IDs.
	 * @param int               $limit        Max results.
	 * @return int[]
	 */
	private function without( array $ids, array $excluded_ids, int $limit ): array {
		return array_slice( array_values( array_diff( array_map( 'intval', $ids ), $excluded_ids ) ), 0, $limit );
	}

	/**
	 * Visibility term_taxonomy_ids to exclude: always exclude-from-catalog, plus out-of-stock
	 * when WooCommerce is set to hide it.
	 *
	 * @return int[]
	 */
	private function exclude_visibility_tt_ids(): array {
		// @phpstan-ignore-next-line -- WooCommerce helper; not covered by the installed WP-only stub package.
		$visibility_term_ids = wc_get_product_visibility_term_ids();
		$exclude_tt_ids      = array();

		if ( ! empty( $visibility_term_ids['exclude-from-catalog'] ) ) {
			$exclude_tt_ids[] = $visibility_term_ids['exclude-from-catalog'];
		}
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! empty( $visibility_term_ids['outofstock'] ) ) {
			$exclude_tt_ids[] = $visibility_term_ids['outofstock'];
		}

		return $exclude_tt_ids;
	}

	/**
	 * Wrap related products section if customCssClass is set.
	 *
	 * @param string $template_name Template name.
	 * @return void
	 */
	public function wrap_related_products_start( string $template_name ): void {
		if ( 'single-product/related.php' !== $template_name ) {
			return;
		}

		$settings = get_option( 'vscm_woo_related_products_settings' );
		if ( ! is_array( $settings ) || empty( $settings['customCssClass'] ) ) {
			return;
		}

		$custom_css_class = $settings['customCssClass'];
		?>
		<div class="vscm-related-products <?php echo esc_attr( $custom_css_class ); ?>">
		<?php
	}

	/**
	 * Close wrapper for related products section.
	 *
	 * @param string $template_name Template name.
	 * @return void
	 */
	public function wrap_related_products_end( string $template_name ): void {
		if ( 'single-product/related.php' !== $template_name ) {
			return;
		}

		$settings = get_option( 'vscm_woo_related_products_settings' );
		if ( ! is_array( $settings ) || empty( $settings['customCssClass'] ) ) {
			return;
		}

		?>
		</div>
		<?php
	}
}
