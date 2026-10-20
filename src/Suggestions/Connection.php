<?php
/**
 * The site's connection to the suggestion service: the stored site, the copy check and the pause (C-063 R2–R5, R10).
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

use Vscm\WooRelatedProducts\Identity\Challenge;

defined( 'ABSPATH' ) || exit;

/**
 * One place answers "may the plugin call the service now, with which key, and if not, why".
 */
final class Connection {

	/**
	 * The stored site: site_id, key, home, environment, consent_version, connected_at; and, under `pause`, why the
	 * service is not called now and until when ({reason, until, home?}). One option, not autoloaded, so a page
	 * that asks both costs one read; a developer key with no site keeps only the pause in it.
	 */
	public const SITE_OPTION = 'vscm_related_service_site';

	/**
	 * The version of the consent text the merchant agrees to by connecting (R8.3).
	 */
	public const CONSENT_VERSION = '1';

	/**
	 * Reasons a pause carries.
	 */
	public const PAUSE_REASONS = array( 'quota', 'busy', 'down', 'refused', 'revoked', 'copy' );

	/**
	 * The stored site, or null when the merchant has not connected.
	 *
	 * @return array<string, mixed>|null
	 */
	public function site(): ?array {
		$site = $this->stored();
		if ( empty( $site['key'] ) ) {
			return null;
		}
		unset( $site['pause'] );

		return $site;
	}

	/**
	 * The key to call with: the stored site key wins over the developer filter's (R4).
	 *
	 * @param string $filter_key The key the developer filter supplies, if any.
	 * @return string
	 */
	public function key( string $filter_key = '' ): string {
		$site = $this->site();

		return null !== $site ? (string) $site['key'] : $filter_key;
	}

	/**
	 * Where the key comes from: `site`, `filter` or an empty string.
	 *
	 * @return string
	 */
	public function key_source(): string {
		if ( null !== $this->site() ) {
			return 'site';
		}

		return '' !== ( new ServiceClient() )->filter_key() ? 'filter' : '';
	}

	/**
	 * Whether there is an address and a key to call with (a copy has both, but does not call).
	 *
	 * @return bool
	 */
	public function linked(): bool {
		$client = new ServiceClient();

		return $client->is_configured() && '' !== $this->key( $client->filter_key() );
	}

	/**
	 * Whether the plugin may call the service: linked, and not a copy.
	 *
	 * @return bool
	 */
	public function connected(): bool {
		return $this->linked() && ! $this->is_copy();
	}

	/**
	 * A copy (R5): the home or the environment type differs from what was stored at connect, or the service said
	 * `site_mismatch` on this very home.
	 *
	 * @return bool
	 */
	public function is_copy(): bool {
		$site = $this->site();
		if ( null === $site ) {
			return false;
		}
		$home = self::normalise_home( home_url() );
		if ( ( $site['home'] ?? '' ) !== $home || ( $site['environment'] ?? '' ) !== wp_get_environment_type() ) {
			return true;
		}
		$pause = $this->pause();

		return null !== $pause && 'copy' === $pause['reason'] && ( $pause['home'] ?? '' ) === $home;
	}

	/**
	 * The current pause, or null.
	 *
	 * @return array{reason: string, until: int|null, home?: string}|null
	 */
	public function pause(): ?array {
		$pause = $this->stored()['pause'] ?? null;
		if ( ! is_array( $pause ) || ! in_array( $pause['reason'] ?? '', self::PAUSE_REASONS, true ) ) {
			return null;
		}
		$pause['until'] = isset( $pause['until'] ) ? (int) $pause['until'] : null;

		return $pause;
	}

	/**
	 * Whether a pause holds now: one with no end, or one whose end has not come.
	 *
	 * @return bool
	 */
	public function paused(): bool {
		$pause = $this->pause();

		return null !== $pause && ( null === $pause['until'] || $pause['until'] > time() );
	}

	/**
	 * Pause the calls, and drop the cached status so the page reads it again.
	 *
	 * @param string   $reason One of PAUSE_REASONS.
	 * @param int|null $until  When it ends; null until the merchant or the service changes something.
	 * @return void
	 */
	public function set_pause( string $reason, ?int $until ): void {
		$pause = array(
			'reason' => $reason,
			'until'  => $until,
		);
		if ( 'copy' === $reason ) {
			$pause['home'] = self::normalise_home( home_url() );
		}
		$this->store( array( 'pause' => $pause ) + $this->stored() );
		delete_transient( SiteStatus::TRANSIENT );
	}

	/**
	 * End the pause.
	 *
	 * @return void
	 */
	public function clear_pause(): void {
		$stored = $this->stored();
		if ( ! isset( $stored['pause'] ) ) {
			return;
		}
		unset( $stored['pause'] );
		$this->store( $stored );
	}

	/**
	 * Connect (R2): issue a challenge, ask the service to verify it, and store the site it answers with.
	 *
	 * @param string $plugin_version This plugin's version.
	 * @return array<string, mixed> The stored site.
	 * @throws ServiceError When the service refuses, with its reason.
	 */
	public function connect( string $plugin_version ): array {
		$challenge = new Challenge();
		try {
			$answer = ( new ServiceClient() )->connect( $challenge->issue(), self::CONSENT_VERSION, $plugin_version );
		} finally {
			$challenge->clear();
		}
		$site = array(
			'site_id'         => $answer['site_id'],
			'key'             => $answer['site_key'],
			'home'            => self::normalise_home( home_url() ),
			'environment'     => wp_get_environment_type(),
			'consent_version' => self::CONSENT_VERSION,
			'connected_at'    => time(),
		);
		// A new site starts with no pause.
		$this->store( $site );
		delete_transient( SiteStatus::TRANSIENT );

		return $site;
	}

	/**
	 * Disconnect (R3): forget the site, its pause and its cached status; no further call.
	 *
	 * @return void
	 */
	public function disconnect(): void {
		delete_option( self::SITE_OPTION );
		delete_transient( SiteStatus::TRANSIENT );
	}

	/**
	 * The option as stored.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		$stored = get_option( self::SITE_OPTION );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Write the option, never autoloaded; an empty one is removed.
	 *
	 * @param array<string, mixed> $stored What to keep.
	 * @return void
	 */
	private function store( array $stored ): void {
		if ( empty( $stored ) ) {
			delete_option( self::SITE_OPTION );
			return;
		}
		if ( ! add_option( self::SITE_OPTION, $stored, '', false ) ) {
			update_option( self::SITE_OPTION, $stored, false );
		}
	}

	/**
	 * The site a home URL names, as the service stores it: the host in lower case without a leading `www.`, a
	 * non-default port and the path, without the scheme (C-059 R3).
	 *
	 * @param string $url A home URL.
	 * @return string
	 */
	public static function normalise_home( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$host    = (string) preg_replace( '/^www\./', '', strtolower( (string) $parts['host'] ) );
		$scheme  = strtolower( (string) ( $parts['scheme'] ?? 'http' ) );
		$default = 'https' === $scheme ? 443 : 80;
		$port    = isset( $parts['port'] ) && (int) $parts['port'] !== $default ? ':' . (int) $parts['port'] : '';
		$path    = rtrim( (string) ( $parts['path'] ?? '' ), '/' );

		return $host . $port . $path;
	}
}
