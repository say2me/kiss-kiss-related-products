<?php
/**
 * Exception thrown by ServiceClient on any transport error, non-200 status or malformed body.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * A suggestion service call failure, with what the service said about it (C-063 R10): its error code, the HTTP
 * status, and when to try again (`resume_at` for a spent allowance, `retry_after` for a busy or restarting service).
 */
class ServiceError extends RuntimeException {

	/**
	 * The service's error code (`quota_exhausted`, `busy`, ...), or `transport`, `config`, `bad_response`.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * The HTTP status, 0 when no answer came.
	 *
	 * @var int
	 */
	private int $http_status;

	/**
	 * When a spent allowance comes back, as a Unix time.
	 *
	 * @var int|null
	 */
	private ?int $resume_at;

	/**
	 * Seconds to wait before trying again.
	 *
	 * @var int|null
	 */
	private ?int $retry_after;

	/**
	 * The service's detail, such as why a connect's verification failed.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Build.
	 *
	 * @param string   $message     Message, "<code>: <text>".
	 * @param string   $error_code  The service's error code.
	 * @param int      $http_status HTTP status, 0 for none.
	 * @param int|null $resume_at   When the allowance resumes.
	 * @param int|null $retry_after Seconds to wait.
	 * @param string   $reason      The service's detail.
	 */
	public function __construct( string $message, string $error_code = '', int $http_status = 0, ?int $resume_at = null, ?int $retry_after = null, string $reason = '' ) {
		parent::__construct( $message );
		$this->error_code  = $error_code;
		$this->http_status = $http_status;
		$this->resume_at   = $resume_at;
		$this->retry_after = $retry_after;
		$this->reason      = $reason;
	}

	/**
	 * The service's detail, empty when it gave none.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * The service's error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * The HTTP status, 0 when no answer came.
	 *
	 * @return int
	 */
	public function http_status(): int {
		return $this->http_status;
	}

	/**
	 * When a spent allowance comes back.
	 *
	 * @return int|null
	 */
	public function resume_at(): ?int {
		return $this->resume_at;
	}

	/**
	 * Seconds to wait before trying again.
	 *
	 * @return int|null
	 */
	public function retry_after(): ?int {
		return $this->retry_after;
	}
}
