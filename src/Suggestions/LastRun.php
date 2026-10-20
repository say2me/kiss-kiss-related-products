<?php
/**
 * Tracks the most recent suggestion build attempt, for the admin UI.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Stores/reads the last suggestion build attempt: product, status, error, timestamp.
 */
class LastRun {

	/**
	 * Option name.
	 */
	private const OPTION = 'vscm_related_service_last_run';

	/**
	 * Record a build attempt.
	 *
	 * @param int    $product_id     Product ID.
	 * @param string $status         Status: ok, empty or failed.
	 * @param string $error          Error message, on failure.
	 * @param int    $input_tokens   Input tokens spent by the call, 0 when none was made or cached.
	 * @param int    $output_tokens  Output tokens spent by the call, 0 when none was made or cached.
	 * @param int    $ms             Service round trip in milliseconds.
	 * @param bool   $cached         Whether the service answered from its cache.
	 * @return void
	 */
	public function record( int $product_id, string $status, string $error, int $input_tokens, int $output_tokens, int $ms, bool $cached ): void {
		update_option(
			self::OPTION,
			array(
				'product_id'    => $product_id,
				'status'        => $status,
				'error'         => $error,
				'at'            => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'input_tokens'  => $input_tokens,
				'output_tokens' => $output_tokens,
				'ms'            => $ms,
				'cached'        => $cached,
			)
		);
	}

	/**
	 * The last recorded attempt, with the product's current title, or null if none yet.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get(): ?array {
		$data = get_option( self::OPTION );
		if ( ! is_array( $data ) || ! isset( $data['product_id'] ) ) {
			return null;
		}

		$data['title'] = get_the_title( (int) $data['product_id'] );

		return $data;
	}
}
