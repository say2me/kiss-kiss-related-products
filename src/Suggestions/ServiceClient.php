<?php
/**
 * HTTP client for the related-products suggestion service. The only class allowed to call wp_remote_*.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the suggestion service: connect, the site's status, and related products.
 */
class ServiceClient {

	/**
	 * Default request timeout in seconds, used when the config filter does not set one.
	 */
	private const DEFAULT_TIMEOUT = 60;

	/**
	 * The production service's address: the one place it is set (C-058). Empty until there is a
	 * production service; any other service (the development stack, a test server) is set through the
	 * vscm_related_service filter, never in wp-config.php (C-cd2aa97).
	 */
	public const DEFAULT_URL = '';

	/**
	 * The plugin's slug, sent on connect.
	 */
	private const PLUGIN = 'kiss-kiss-related-products';

	/**
	 * Whether the service has a usable http(s) URL configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		$url = $this->config()['url'];

		return '' !== $url && 1 === preg_match( '#^https?://#i', $url );
	}

	/**
	 * The key the developer filter supplies, if any (C-063 R4: a code-level seam, never offered to a merchant).
	 *
	 * @return string
	 */
	public function filter_key(): string {
		return (string) $this->config()['key'];
	}

	/**
	 * Ask the service for related products.
	 *
	 * @param array<string, mixed>             $card       The requesting product's card.
	 * @param array<int, array<string, mixed>> $candidates Candidate product cards.
	 * @param int                              $limit      Max related products to return.
	 * @return array<string, mixed> The decoded response body.
	 * @throws ServiceError On any transport error, non-200 status or malformed body.
	 */
	public function related( array $card, array $candidates, int $limit ): array {
		$data = $this->call(
			'POST',
			'/v1/related',
			array(
				'product'    => $card,
				'candidates' => $candidates,
				'limit'      => $limit,
			),
			true
		);

		if ( ! isset( $data['related'] ) || ! is_array( $data['related'] ) ) {
			throw new ServiceError( 'bad_response: malformed service response body', 'bad_response', 200 );
		}

		return $data;
	}

	/**
	 * Connect the site (C-059 K1–K2): the service fetches the challenge from this site's verify route and answers
	 * with the site id and key.
	 *
	 * @param string $challenge       The challenge the verify route answers.
	 * @param string $consent_version The consent text's version the merchant agreed to.
	 * @param string $plugin_version  This plugin's version.
	 * @return array{site_id: string, site_key: string}
	 * @throws ServiceError On any failure, with the service's reason.
	 */
	public function connect( string $challenge, string $consent_version, string $plugin_version ): array {
		$data = $this->call(
			'POST',
			'/v1/sites/connect',
			array(
				'home'            => home_url(),
				'challenge'       => $challenge,
				'consent_version' => $consent_version,
				'plugin'          => self::PLUGIN,
				'plugin_version'  => $plugin_version,
			),
			false
		);

		if ( ! isset( $data['site_id'], $data['site_key'] ) || ! is_string( $data['site_id'] ) || ! is_string( $data['site_key'] ) ) {
			throw new ServiceError( 'bad_response: malformed service response body', 'bad_response', 200 );
		}

		return array(
			'site_id'  => $data['site_id'],
			'site_key' => $data['site_key'],
		);
	}

	/**
	 * The site's entitlement and quota (`GET /v1/sites/me`).
	 *
	 * @return array<string, mixed>
	 * @throws ServiceError On any failure.
	 */
	public function me(): array {
		return $this->call( 'GET', '/v1/sites/me', null, true );
	}

	/**
	 * Ping the service's health endpoint. Never throws.
	 *
	 * @return array{ok: bool, provider: string, model: string, ms: int, error: string}
	 */
	public function ping(): array {
		$start = microtime( true );
		try {
			$data = $this->call( 'POST', '/v1/ping', array(), true, 10 );
		} catch ( ServiceError $error ) {
			return array(
				'ok'       => false,
				'provider' => '',
				'model'    => '',
				'ms'       => 'config' === $error->error_code() ? 0 : (int) round( ( microtime( true ) - $start ) * 1000 ),
				'error'    => $error->getMessage(),
			);
		}

		return array(
			'ok'       => (bool) ( $data['ok'] ?? false ),
			'provider' => (string) ( $data['provider'] ?? '' ),
			'model'    => (string) ( $data['model'] ?? '' ),
			'ms'       => isset( $data['ms'] ) ? (int) $data['ms'] : (int) round( ( microtime( true ) - $start ) * 1000 ),
			'error'    => (string) ( $data['error'] ?? '' ),
		);
	}

	/**
	 * One request to the service. A site call carries the key and the site's home (C-063 R4); connect carries
	 * neither. Any answer but a 200 with an object becomes a ServiceError with what the service said.
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $path    Path under the service's address.
	 * @param array<string, mixed>|null $body    JSON body; null for none.
	 * @param bool                      $as_site Whether to send the key and the home.
	 * @param int|null                  $timeout Seconds; the configured timeout when null.
	 * @return array<string, mixed>
	 * @throws ServiceError On any failure.
	 */
	private function call( string $method, string $path, ?array $body, bool $as_site, ?int $timeout = null ): array {
		if ( ! $this->is_configured() ) {
			throw new ServiceError( 'config: service URL is not configured', 'config' );
		}
		$config  = $this->config();
		$headers = array( 'Content-Type' => 'application/json' );
		if ( $as_site ) {
			$headers['Authorization'] = 'Bearer ' . ( new Connection() )->key( (string) $config['key'] );
			$headers['X-Aiai-Home']   = home_url();
		}
		$args = array(
			'method'  => $method,
			'timeout' => $timeout ?? (int) $config['timeout'],
			'headers' => $headers,
		);
		if ( null !== $body ) {
			// An empty body is sent as an empty JSON object, not [].
			$args['body'] = empty( $body ) ? '{}' : (string) wp_json_encode( $body );
		}

		$response = wp_remote_request( rtrim( (string) $config['url'], '/' ) . $path, $args );

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal error message, not rendered to a browser.
			throw new ServiceError( 'transport: ' . $response->get_error_message(), 'transport' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			$error   = ( is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ) ? $data['error'] : array();
			$code    = isset( $error['code'] ) ? (string) $error['code'] : (string) $status;
			$message = isset( $error['message'] ) ? (string) $error['message'] : 'HTTP ' . $status;
			$resume  = isset( $error['resume_at'] ) ? strtotime( (string) $error['resume_at'] ) : false;
			$retry   = isset( $error['retry_after'] ) ? (int) $error['retry_after'] : (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$reason  = isset( $error['reason'] ) ? (string) $error['reason'] : '';
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal error message, not rendered to a browser.
			throw new ServiceError( $code . ': ' . $message, $code, $status, false === $resume ? null : $resume, $retry > 0 ? $retry : null, $reason );
		}

		if ( ! is_array( $data ) ) {
			throw new ServiceError( 'bad_response: malformed service response body', 'bad_response', 200 );
		}

		return $data;
	}

	/**
	 * Resolved service config: the built-in production address, then the vscm_related_service filter.
	 *
	 * @return array{url: string, key: string, timeout: int}
	 */
	private function config(): array {
		$defaults = array(
			'url'     => self::DEFAULT_URL,
			'key'     => '',
			'timeout' => self::DEFAULT_TIMEOUT,
		);

		/**
		 * Filters the suggestion service connection config.
		 *
		 * @param array{url: string, key: string, timeout: int} $defaults Default config.
		 */
		$config = apply_filters( 'vscm_related_service', $defaults );

		if ( ! is_array( $config ) ) {
			return $defaults;
		}

		return array_merge( $defaults, $config );
	}
}
