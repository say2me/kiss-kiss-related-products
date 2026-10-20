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
			set_transient( self::TRANSIENT, array( 'failed' => $error->error_code() ), self::FAILED_TTL );
			return null;
		}
		// The service answers again: a revocation it lifted is over.
		$pause = $this->connection->pause();
		if ( null !== $pause && 'revoked' === $pause['reason'] ) {
			$this->connection->clear_pause();
		}
		set_transient( self::TRANSIENT, $status, self::TTL );

		return $status;
	}

	/**
	 * The related capability's entitlement and quota, flattened for display; null when there is no status.
	 *
	 * @param bool $refresh Whether to ask the service even when a cached answer exists.
	 * @return array{tier: string, status: string, ends_at: string|null, used: int, limit: int, resume_at: string|null}|null
	 */
	public function related( bool $refresh = false ): ?array {
		$status = $this->get( $refresh );
		if ( null === $status ) {
			return null;
		}
		$entitlement = (array) ( $status['entitlements']['related'] ?? array() );
		$quota       = (array) ( $status['quota']['related'] ?? array() );

		return array(
			'tier'      => (string) ( $entitlement['tier'] ?? 'free' ),
			'status'    => (string) ( $entitlement['status'] ?? 'free' ),
			'ends_at'   => isset( $entitlement['ends_at'] ) ? (string) $entitlement['ends_at'] : null,
			'used'      => (int) ( $quota['used'] ?? 0 ),
			'limit'     => (int) ( $quota['limit'] ?? 0 ),
			'resume_at' => isset( $quota['resume_at'] ) ? (string) $quota['resume_at'] : null,
		);
	}
}
