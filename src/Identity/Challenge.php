<?php
/**
 * The connect challenge.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * The one-time value a site sends when it connects to the suggestion service, and answers on its verify route while
 * the service fetches it back. One at a time, valid for ten minutes, in one option that is not autoloaded.
 */
class Challenge {

	/**
	 * The option holding `{value, issued_at}`.
	 */
	public const OPTION = 'vscm_aiai_challenge';

	/**
	 * Seconds a challenge stays valid.
	 */
	public const TTL = 600;

	/**
	 * The clock.
	 *
	 * @var callable(): int
	 */
	private $now;

	/**
	 * Build.
	 *
	 * @param callable|null $now The clock, for tests; `time` by default.
	 */
	public function __construct( ?callable $now = null ) {
		$this->now = $now ?? 'time';
	}

	/**
	 * Draw a new challenge, replacing any earlier one.
	 *
	 * @return string 32 letters and digits.
	 */
	public function issue(): string {
		$value = wp_generate_password( 32, false, false );
		update_option(
			self::OPTION,
			array(
				'value'     => $value,
				'issued_at' => ( $this->now )(),
			),
			false
		);
		return $value;
	}

	/**
	 * Whether `$value` is the challenge this site issued, less than TTL seconds ago.
	 *
	 * @param string $value The challenge to check.
	 * @return bool
	 */
	public function matches( string $value ): bool {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || ! isset( $stored['value'], $stored['issued_at'] ) || '' === $value ) {
			return false;
		}
		return ( $this->now )() - (int) $stored['issued_at'] < self::TTL && hash_equals( (string) $stored['value'], $value );
	}

	/**
	 * Forget the challenge.
	 *
	 * @return void
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}
}
