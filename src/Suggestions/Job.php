<?php
/**
 * Tracks the current rebuild-all job, for the admin progress bar.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Stores/reads the current rebuild-all job record.
 */
class Job {

	/**
	 * Option name.
	 */
	private const OPTION = 'vscm_related_service_job';

	/**
	 * Cause used for rebuild-all queue rows.
	 */
	private const CAUSE = 'rebuild-all';

	/**
	 * Queue repository.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Build.
	 *
	 * @param Queue $queue Queue repository.
	 */
	public function __construct( Queue $queue ) {
		$this->queue = $queue;
	}

	/**
	 * Start a new job, replacing any existing one.
	 *
	 * @param int $total Total products queued for this job.
	 * @return array<string, mixed>
	 */
	public function start( int $total ): array {
		$job = array(
			'id'          => wp_generate_uuid4(),
			'total'       => $total,
			'started_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'finished_at' => null,
			'cancelled'   => false,
		);

		update_option( self::OPTION, $job );

		return $job;
	}

	/**
	 * Cancel the current job: freeze its remaining/failed counts and mark it finished. No-op
	 * without a job or for one already finished.
	 *
	 * @return void
	 */
	public function cancel(): void {
		$job = get_option( self::OPTION );
		if ( ! is_array( $job ) || ! empty( $job['finished_at'] ) ) {
			return;
		}

		$counts = $this->queue->cause_counts( self::CAUSE );

		$job['remaining']   = $counts['remaining'];
		$job['failed']      = $counts['failed'];
		$job['cancelled']   = true;
		$job['finished_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );

		update_option( self::OPTION, $job );
	}

	/**
	 * Clear the job record entirely, so no progress bar outlives the work it described.
	 * Distinct from cancel(), which preserves the record to show a job was cancelled.
	 *
	 * @return void
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The current job with live progress, or null if none has started.
	 *
	 * @return array<string, mixed>|null
	 */
	public function current(): ?array {
		$job = get_option( self::OPTION );
		if ( ! is_array( $job ) ) {
			return null;
		}

		$total = (int) ( $job['total'] ?? 0 );

		if ( ! empty( $job['cancelled'] ) ) {
			$remaining = (int) ( $job['remaining'] ?? 0 );
			$failed    = (int) ( $job['failed'] ?? 0 );
		} else {
			$counts    = $this->queue->cause_counts( self::CAUSE );
			$remaining = $counts['remaining'];
			$failed    = $counts['failed'];
		}

		$done    = max( 0, $total - $remaining - $failed );
		$percent = 0 === $total ? 100 : min( 100, (int) floor( 100 * ( $total - $remaining ) / $total ) );

		if ( 0 === $remaining && empty( $job['finished_at'] ) ) {
			$job['finished_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
			update_option( self::OPTION, $job );
		}

		$eta_seconds = null;
		if ( empty( $job['finished_at'] ) && ( $total - $remaining ) > 0 ) {
			$elapsed     = time() - (int) strtotime( (string) $job['started_at'] );
			$eta_seconds = (int) round( ( $elapsed / ( $total - $remaining ) ) * $remaining );
		}

		return array(
			'id'          => (string) ( $job['id'] ?? '' ),
			'total'       => $total,
			'started_at'  => $job['started_at'] ?? null,
			'finished_at' => $job['finished_at'] ?? null,
			'cancelled'   => (bool) ( $job['cancelled'] ?? false ),
			'remaining'   => $remaining,
			'failed'      => $failed,
			'done'        => $done,
			'percent'     => $percent,
			'eta_seconds' => $eta_seconds,
		);
	}
}
