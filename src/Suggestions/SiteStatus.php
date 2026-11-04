<?php
/**
 * The site's entitlement and allowance as the service reports them, cached for at most an hour (C-063 R6).
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Reads `GET /v1/sites/me` once an hour at most; the settings page and the status route read it from here.
 */
final class SiteStatus {

	/**
	 * The cache.
	 */
	public const TRANSIENT = 'vscm_related_service_status';

	/**
	 * The last catalogue size sent: `{site_id, at}`, not autoloaded (C-9f64aa2 R1).
	 */
	public const SIZE_OPTION = 'vscm_related_service_size';

	/**
	 * At most an hour (R6.6).
	 */
	private const TTL = HOUR_IN_SECONDS;

	/**
	 * How long a failed read is remembered, so a page that polls the status does not ask a service that is down
	 * on every poll.
	 */
	private const FAILED_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * The service client.
	 *
	 * @var ServiceClient
	 */
	private ServiceClient $client;

	/**
	 * The connection.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Build.
	 *
	 * @param ServiceClient $client     The service client.
	 * @param Connection    $connection The connection.
	 */
	public function __construct( ServiceClient $client, Connection $connection ) {
		$this->client     = $client;
		$this->connection = $connection;
	}

	/**
	 * The status: the cached answer, or a fresh one when there is none or `$refresh` asks for it. Null when the
	 * site may not call the service, has no site of its own (the developer filter's key is not a site, and the
	 * service refuses it on /v1/sites/me), or the call failed; a failure the service explained pauses the
	 * connection.
	 *
	 * @param bool $refresh Whether to ask the service even when a cached answer exists.
	 * @return array<string, mixed>|null
	 */
	public function get( bool $refresh = false ): ?array {
		if ( ! $this->connection->connected() || null === $this->connection->site() ) {
			return null;
		}
		$cached = get_transient( self::TRANSIENT );
		if ( ! $refresh && is_array( $cached ) ) {
			return isset( $cached['failed'] ) ? null : $cached;
		}
		try {
			$status = $this->client->me();
		} catch ( ServiceError $error ) {
			if ( in_array( $error->error_code(), array( 'unauthorized', 'site_revoked', 'site_mismatch' ), true ) ) {
				$this->connection->set_pause(
					array(
						'unauthorized'  => 'refused',
						'site_revoked'  => 'revoked',
						'site_mismatch' => 'copy',
					)[ $error->error_code() ],
					null
				);
			}
			set_transient(
				self::TRANSIENT,
				array(
					'failed' => $error->error_code(),
					'status' => $error->http_status(),
				),
				self::FAILED_TTL
			);
			return null;
		}
		// The service answers again: a revocation it lifted is over.
		$pause = $this->connection->pause();
		if ( null !== $pause && 'revoked' === $pause['reason'] ) {
			$this->connection->clear_pause();
		}
		$status = $this->report_size( $status );
		$this->lift_quota_pause( $status );
		set_transient( self::TRANSIENT, $status, self::TTL );

		return $status;
	}

	/**
	 * End a quota pause once the service shows room: it gives `resume_at` only while the allowance is spent, so a
	 * purchase, a larger allowance or a new month all lift it here; the worker is told so it drains now (C-86c4409 R1).
	 *
	 * @param array<string, mixed> $status The status just read.
	 * @return void
	 */
	private function lift_quota_pause( array $status ): void {
		$pause = $this->connection->pause();
		if ( null === $pause || 'quota' !== $pause['reason'] || isset( $status['quota']['related']['resume_at'] ) ) {
			return;
		}
		$this->connection->clear_pause();
		/**
		 * Fires when a quota pause ends because the service shows room again.
		 */
		do_action( 'vscm_related_service_resumed' );
	}

	/**
	 * Send the catalogue size unless this site sent it in the last day (C-9f64aa2 R1–R3). The service answers with
	 * the status, which then replaces the one just read; a failed send keeps that one and is tried on the next fresh
	 * read.
	 *
	 * @param array<string, mixed> $status The status just read.
	 * @return array<string, mixed>
	 */
	private function report_size( array $status ): array {
		$site_id = (string) ( $this->connection->site()['site_id'] ?? '' );
		$sent    = get_option( self::SIZE_OPTION );
		if ( is_array( $sent ) && ( $sent['site_id'] ?? null ) === $site_id && time() - (int) ( $sent['at'] ?? 0 ) < DAY_IN_SECONDS ) {
			return $status;
		}
		try {
			// One cached count query; no product is loaded (root spec R9.1).
			$status = $this->client->report_size( (int) wp_count_posts( 'product' )->publish );
		} catch ( ServiceError $error ) {
			return $status;
		}
		update_option(
			self::SIZE_OPTION,
			array(
				'site_id' => $site_id,
				'at'      => time(),
			),
			false
		);

		return $status;
	}

	/**
	 * Whether the last status read could not reach the service: no answer at all, a server error or a body that is
	 * not the service's (C-ce3b88a R12). A refusal the service explained (a revoked site, a bad key) is not an outage.
	 *
	 * @return bool
	 */
	public function unreachable(): bool {
		$cached = get_transient( self::TRANSIENT );
		if ( ! is_array( $cached ) || ! isset( $cached['failed'] ) ) {
			return false;
		}

		return in_array( $cached['failed'], array( 'transport', 'bad_response' ), true ) || (int) ( $cached['status'] ?? 0 ) >= 500;
	}

	/**
	 * The related capability's entitlement and quota, flattened for display; null when there is no status.
	 *
	 * @param bool $refresh Whether to ask the service even when a cached answer exists.
	 * @return array{tier: string, status: string, ends_at: string|null, used: int, limit: int, resume_at: string|null, rebuild_used: int, rebuild_limit: int, rebuild_ends_at: string|null}|null
	 */
	public function related( bool $refresh = false ): ?array {
		$status = $this->get( $refresh );
		if ( null === $status ) {
			return null;
		}
		$entitlement = (array) ( $status['entitlements']['related'] ?? array() );
		$quota       = (array) ( $status['quota']['related'] ?? array() );

		return array(
			'tier'            => (string) ( $entitlement['tier'] ?? 'free' ),
			'status'          => (string) ( $entitlement['status'] ?? 'free' ),
			'ends_at'         => isset( $entitlement['ends_at'] ) ? (string) $entitlement['ends_at'] : null,
			'used'            => (int) ( $quota['used'] ?? 0 ),
			'limit'           => (int) ( $quota['limit'] ?? 0 ),
			'resume_at'       => isset( $quota['resume_at'] ) ? (string) $quota['resume_at'] : null,
			// The catalogue refresh allowance of a purchased plan (C-0493ee8 R7); 0 and null when the service gives none.
			'rebuild_used'    => (int) ( $quota['rebuild_used'] ?? 0 ),
			'rebuild_limit'   => (int) ( $quota['rebuild_limit'] ?? 0 ),
			'rebuild_ends_at' => isset( $quota['rebuild_window_ends_at'] ) ? (string) $quota['rebuild_window_ends_at'] : null,
		);
	}
}
