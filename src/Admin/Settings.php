<?php
/**
 * Plugin settings: reads/sanitizes/stores the vscm_woo_related_products_settings option.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Settings option repository.
 */
class Settings {

	/**
	 * Option name.
	 */
	private const OPTION = 'vscm_woo_related_products_settings';

	/**
	 * The settings this plugin owns, and anything an extension registers beside them.
	 *
	 * Each field is `array( 'default' => mixed, 'sanitize' => callable( mixed ): mixed )`. Only what
	 * is listed here is ever accepted, returned or stored.
	 *
	 * @return array<string, array{default: mixed, sanitize: callable}>
	 */
	public function fields(): array {
		$fields = array(
			'customCssClass' => array(
				'default'  => '',
				'sanitize' => array( self::class, 'sanitize_css_classes' ),
			),
			'enableFeature'  => array(
				'default'  => true,
				'sanitize' => array( self::class, 'sanitize_boolean' ),
			),
			// The merchant's pause of the stored suggestions (C-063 R9): on once connected.
			'serviceEnabled' => array(
				'default'  => true,
				'sanitize' => array( self::class, 'sanitize_boolean' ),
			),
		);

		/**
		 * Lets an extension register settings of its own.
		 *
		 * @param array<string, array{default: mixed, sanitize: callable}> $fields Key => default and sanitiser.
		 *
		 * @var mixed $filtered What a callback returned: nothing guarantees it is still an array.
		 */
		$filtered = apply_filters( 'vscm_related_products_settings_fields', $fields );

		// A filter is a public extension point: any callback may return a non-array, so narrow it here.
		return is_array( $filtered ) ? $filtered : array();
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array_map(
			static function ( array $field ) {
				return $field['default'];
			},
			$this->fields()
		);
	}

	/**
	 * Sanitiser: a space-separated list of CSS classes.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_css_classes( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return implode( ' ', array_filter( array_map( 'sanitize_html_class', explode( ' ', $value ) ) ) );
	}

	/**
	 * Sanitiser: a boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function sanitize_boolean( $value ): bool {
		// @phpstan-ignore-next-line -- WordPress REST API function, type inference not covered.
		return rest_sanitize_boolean( $value );
	}

	/**
	 * Sanitiser: a non-negative integer.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_unsigned_int( $value ): int {
		return max( 0, (int) $value );
	}

	/**
	 * The stored option merged over the defaults, restricted to the registered keys: an option
	 * written by an older version (its provider and model keys), or by an extension that is no longer
	 * active, must not leak back out.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? $stored : array();

		$defaults = $this->defaults();

		return array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
	}

	/**
	 * Sanitize registered keys present in the input; unknown keys and absent keys are dropped.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input ): array {
		$out = array();

		foreach ( $this->fields() as $key => $field ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = call_user_func( $field['sanitize'], $input[ $key ] );
			}
		}

		return $out;
	}

	/**
	 * Sanitize and merge into the stored option; keys not submitted keep their stored value.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> The updated settings, as get().
	 */
	public function update( array $input ): array {
		$sanitized = $this->sanitize( $input );

		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? $stored : array();
		$merged = array_merge( $stored, $sanitized );

		if ( $stored !== $merged ) {
			update_option( self::OPTION, $merged );
		}

		return $this->get();
	}
}
