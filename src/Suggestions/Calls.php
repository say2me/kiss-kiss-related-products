<?php
/**
 * Daily counter of suggestion service calls.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks how many service calls were made today (UTC), and their tokens, for the merchant tools.
 */
class Calls {

	/**
	 * Option name.
	 */
	private const OPTION = 'vscm_related_service_calls';


	/**
	 * Number of calls made today (UTC).
	 *
	 * @return int
	 */
	public function today(): int {
		return (int) ( $this->today_data()['count'] ?? 0 );
	}

	/**
	 * Total input/output tokens spent today, across every provider/model.
	 *
	 * @return array{input: int, output: int}
	 */
	public function today_tokens(): array {
		$input  = 0;
		$output = 0;

		foreach ( $this->today_by_model() as $tokens ) {
			$input  += $tokens['input'];
			$output += $tokens['output'];
		}

		return array(
			'input'  => $input,
			'output' => $output,
		);
	}

	/**
	 * Today's input/output tokens, keyed by `provider/model`.
	 *
	 * @return array<string, array{input: int, output: int}>
	 */
	public function today_by_model(): array {
		$tokens = $this->today_data()['tokens'] ?? array();
		$tokens = is_array( $tokens ) ? $tokens : array();

		$by_model = array();
		foreach ( $tokens as $key => $value ) {
			$value                     = is_array( $value ) ? $value : array();
			$by_model[ (string) $key ] = array(
				'input'  => (int) ( $value['input'] ?? 0 ),
				'output' => (int) ( $value['output'] ?? 0 ),
			);
		}

		return $by_model;
	}

	/**
	 * Number of today's calls that were answered from the service's cache.
	 *
	 * @return int
	 */
	public function today_cached(): int {
		return (int) ( $this->today_data()['cached'] ?? 0 );
	}

	/**
	 * Record one call, resetting the daily counters when the UTC date has rolled over. A cached
	 * answer still counts as a call and is tallied separately, but adds no tokens: it cost
	 * nothing. The all-time `totals`, keyed `provider/model`, survive the daily reset and are
	 * built from non-cached calls only, for the same reason.
	 *
	 * @param string $provider Provider id, e.g. `openai`.
	 * @param string $model    Model id.
	 * @param int    $input    Input tokens spent, 0 when cached.
	 * @param int    $output   Output tokens spent, 0 when cached.
	 * @param bool   $cached   Whether the service answered from its cache.
	 * @param int    $ms       Service round trip in milliseconds, 0 when cached.
	 * @return void
	 */
	public function record( string $provider, string $model, int $input, int $output, bool $cached, int $ms ): void {
		$date = $this->today_date();
		$data = get_option( self::OPTION );
		$data = is_array( $data ) ? $data : array();

		$totals = is_array( $data['totals'] ?? null ) ? $data['totals'] : array();

		if ( ( $data['date'] ?? '' ) !== $date ) {
			$data = array(
				'date'  => $date,
				'count' => 0,
			);
		}

		$data['totals'] = $totals;
		$data['count']  = (int) ( $data['count'] ?? 0 ) + 1;

		if ( $cached ) {
			$data['cached'] = (int) ( $data['cached'] ?? 0 ) + 1;
		} else {
			$key      = $provider . '/' . $model;
			$tokens   = is_array( $data['tokens'] ?? null ) ? $data['tokens'] : array();
			$existing = is_array( $tokens[ $key ] ?? null ) ? $tokens[ $key ] : array();

			$tokens[ $key ] = array(
				'input'  => (int) ( $existing['input'] ?? 0 ) + $input,
				'output' => (int) ( $existing['output'] ?? 0 ) + $output,
			);

			$data['tokens'] = $tokens;

			$existing_totals        = is_array( $data['totals'][ $key ] ?? null ) ? $data['totals'][ $key ] : array();
			$data['totals'][ $key ] = array(
				'calls'  => (int) ( $existing_totals['calls'] ?? 0 ) + 1,
				'input'  => (int) ( $existing_totals['input'] ?? 0 ) + $input,
				'output' => (int) ( $existing_totals['output'] ?? 0 ) + $output,
				'ms'     => (int) ( $existing_totals['ms'] ?? 0 ) + $ms,
			);
		}

		update_option( self::OPTION, $data );
	}

	/**
	 * All-time call totals, keyed `provider/model`. Survives the daily reset in `record()`.
	 *
	 * @return array<string, array{calls: int, input: int, output: int, ms: int}>
	 */
	public function totals(): array {
		$data   = get_option( self::OPTION );
		$data   = is_array( $data ) ? $data : array();
		$totals = is_array( $data['totals'] ?? null ) ? $data['totals'] : array();

		$out = array();
		foreach ( $totals as $key => $value ) {
			$value                = is_array( $value ) ? $value : array();
			$out[ (string) $key ] = array(
				'calls'  => (int) ( $value['calls'] ?? 0 ),
				'input'  => (int) ( $value['input'] ?? 0 ),
				'output' => (int) ( $value['output'] ?? 0 ),
				'ms'     => (int) ( $value['ms'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * Today's date in UTC, Y-m-d.
	 *
	 * @return string
	 */
	private function today_date(): string {
		return gmdate( 'Y-m-d' );
	}

	/**
	 * The stored option, or an empty array when absent or from a previous UTC day.
	 *
	 * @return array<string, mixed>
	 */
	private function today_data(): array {
		$data = get_option( self::OPTION );
		if ( ! is_array( $data ) || ( $data['date'] ?? '' ) !== $this->today_date() ) {
			return array();
		}

		return $data;
	}
}
