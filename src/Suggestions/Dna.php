<?php
/**
 * Builds the product "card" sent to the suggestion service, and its change-detection hash.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Product card builder and DNA hash.
 */
class Dna {

	/**
	 * Post meta key storing the DNA hash.
	 */
	public const META_KEY = '_vscm_service_dna';

	/**
	 * Build the product card, the exact data sent to the suggestion service.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, mixed>
	 */
	public function card( int $product_id ): array {
		// @phpstan-ignore-next-line -- WooCommerce helper; not covered by the installed WP-only stub package.
		$product = wc_get_product( $product_id );

		if ( false === $product ) {
			return array(
				'id'       => $product_id,
				'name'     => $this->fallback_name( $product_id ),
				'price'    => null,
				'in_stock' => false,
			);
		}

		$price = $product->get_price();
		$price = ( '' === $price || null === $price ) ? null : (float) $price;
		$name  = $this->clip( (string) $product->get_name(), 200 );

		$card = array(
			'id'          => $product_id,
			'name'        => '' === $name ? $this->fallback_name( $product_id ) : $name,
			'categories'  => $this->term_names( $product_id, 'product_cat', 10 ),
			'brand'       => $this->first_term_name( $product_id, 'product_brand' ),
			'tags'        => $this->term_names( $product_id, 'product_tag', 10 ),
			'attributes'  => $this->attributes( $product ),
			'price'       => $price,
			'price_band'  => null === $price ? '' : $this->price_band( $price ),
			'description' => $this->description( $product ),
			'in_stock'    => (bool) $product->is_in_stock(),
		);

		// The service rejects empty strings; leave empty optional fields out of the card.
		foreach ( array( 'brand', 'price_band', 'description' ) as $key ) {
			if ( '' === $card[ $key ] ) {
				unset( $card[ $key ] );
			}
		}

		return $card;
	}

	/**
	 * Name used when a product has none.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private function fallback_name( int $product_id ): string {
		return 'Product ' . $product_id;
	}

	/**
	 * Trim and cap a string.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	private function clip( string $text, int $max ): string {
		$text = trim( $text );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * SHA1 of the canonical JSON of the card plus catalog visibility.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public function hash( int $product_id ): string {
		$data               = $this->card( $product_id );
		$data['visibility'] = $this->visibility( $product_id );

		return sha1( (string) wp_json_encode( $this->ksort_recursive( $data ) ) );
	}

	/**
	 * Catalog visibility: visible, catalog, search or hidden.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private function visibility( int $product_id ): string {
		// @phpstan-ignore-next-line -- WooCommerce helper; not covered by the installed WP-only stub package.
		$product = wc_get_product( $product_id );

		if ( false === $product ) {
			return 'hidden';
		}

		return $product->get_catalog_visibility();
	}

	/**
	 * Term names for a taxonomy, capped.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param int    $limit      Max terms.
	 * @return string[]
	 */
	private function term_names( int $product_id, string $taxonomy, int $limit ): array {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_slice( $this->clean_strings( wp_list_pluck( $terms, 'name' ) ), 0, $limit );
	}

	/**
	 * Non-empty strings, each capped at 200 characters.
	 *
	 * @param array<mixed> $values Values.
	 * @return string[]
	 */
	private function clean_strings( array $values ): array {
		$out = array();
		foreach ( $values as $value ) {
			$value = is_scalar( $value ) ? $this->clip( (string) $value, 200 ) : '';
			if ( '' !== $value ) {
				$out[] = $value;
			}
		}

		return $out;
	}

	/**
	 * First term name for a taxonomy, or ''.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $taxonomy   Taxonomy slug.
	 * @return string
	 */
	private function first_term_name( int $product_id, string $taxonomy ): string {
		$names = $this->term_names( $product_id, $taxonomy, 1 );
		return $names[0] ?? '';
	}

	/**
	 * Visible attributes as {name, values[]}, capped at 10 attributes.
	 *
	 * @param mixed $product WC_Product instance; not stubbed, so left untyped.
	 * @return array<int, array{name: string, values: string[]}>
	 */
	private function attributes( mixed $product ): array {
		$out = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute->get_visible() ) {
				continue;
			}

			if ( $attribute->is_taxonomy() ) {
				$terms  = $attribute->get_terms();
				$values = is_array( $terms ) ? wp_list_pluck( $terms, 'name' ) : array();
				// @phpstan-ignore-next-line -- WooCommerce helper; not covered by the installed WP-only stub package.
				$name = wc_attribute_label( $attribute->get_name() );
			} else {
				$values = $attribute->get_options();
				$name   = $attribute->get_name();
			}

			$name = $this->clip( (string) $name, 200 );
			if ( '' === $name ) {
				continue;
			}

			$out[] = array(
				'name'   => $name,
				'values' => $this->clean_strings( (array) $values ),
			);

			if ( count( $out ) >= 10 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Short description, falling back to content; stripped and capped at 300 chars.
	 *
	 * @param mixed $product WC_Product instance; not stubbed, so left untyped.
	 * @return string
	 */
	private function description( mixed $product ): string {
		$text = $product->get_short_description();
		if ( '' === trim( (string) $text ) ) {
			$text = $product->get_description();
		}

		$text = strip_shortcodes( (string) $text );
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/\s+/', ' ', $text ) ?? '';
		$text = trim( $text );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, 300 );
		}

		return substr( $text, 0, 300 );
	}

	/**
	 * Price band bucket for a known price.
	 *
	 * @param float $price Price.
	 * @return string
	 */
	private function price_band( float $price ): string {
		if ( $price < 10 ) {
			return 'under-10';
		}
		if ( $price < 25 ) {
			return '10-25';
		}
		if ( $price < 50 ) {
			return '25-50';
		}
		if ( $price < 100 ) {
			return '50-100';
		}
		if ( $price < 250 ) {
			return '100-250';
		}
		return '250-plus';
	}

	/**
	 * Recursively ksort an array (and its array values) for canonical JSON encoding.
	 *
	 * @param array<mixed> $data Data.
	 * @return array<mixed>
	 */
	private function ksort_recursive( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->ksort_recursive( $value );
			}
		}
		ksort( $data );
		return $data;
	}
}
