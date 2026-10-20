<?php
/**
 * Drains the suggestion queue via Action Scheduler.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

use Vscm\WooRelatedProducts\InitableInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and runs batches of Curator::build() against the queue.
 */
class Worker implements InitableInterface {

	/**
	 * Action Scheduler hook.
	 */
	public const HOOK = 'vscm_related_service_drain';

	/**
	 * Action Scheduler group.
	 */
	public const GROUP = 'vscm-related-service';

	/**
	 * Option that pauses the scheduled drain.
	 */
	private const PAUSED_OPTION = 'vscm_related_service_paused';

	/**
	 * Products processed per scheduled batch.
	 */
	private const BATCH_PRODUCTS = 5;

	/**
	 * Seconds budget per scheduled batch.
	 */
	private const BATCH_SECONDS = 20;

	/**
	 * Queue repository.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Curator.
	 *
	 * @var Curator
	 */
	private Curator $curator;


	/**
	 * Generation state repository.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Last build attempt repository.
	 *
	 * @var LastRun
	 */
	private LastRun $last_run;

	/**
	 * The connection: whether to call, and the pause the service's answers set (C-063 R10).
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Build.
	 *
	 * @param Queue      $queue    Queue repository.
	 * @param Curator    $curator  Curator.
	 * @param State      $state      Generation state repository.
	 * @param LastRun    $last_run   Last build attempt repository.
	 * @param Connection $connection The connection.
	 */
	public function __construct( Queue $queue, Curator $curator, State $state, LastRun $last_run, Connection $connection ) {
		$this->queue      = $queue;
		$this->curator    = $curator;
		$this->state      = $state;
		$this->last_run   = $last_run;
		$this->connection = $connection;
	}

	/**
	 * Init.
	 */
	public function init() {
		add_action( 'vscm_related_service_queued', array( $this, 'maybe_schedule' ) );
		add_action( self::HOOK, array( $this, 'handle_drain' ) );
	}

	/**
	 * Schedule one async drain action, unless one is already pending.
	 *
	 * @return void
	 */
	public function maybe_schedule(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		// as_has_scheduled_action() (Action Scheduler 3.3+) answers the same question in 2 queries instead of
		// as_next_scheduled_action()'s 6, and this runs on every enqueue and term deletion (C-055).
		$pending = function_exists( 'as_has_scheduled_action' )
			? as_has_scheduled_action( self::HOOK, array(), self::GROUP )
			: false !== as_next_scheduled_action( self::HOOK, array(), self::GROUP );
		if ( $pending ) {
			return;
		}

		as_schedule_single_action( time(), self::HOOK, array(), self::GROUP );
	}

	/**
	 * Action Scheduler handler: processes one batch, then re-schedules: when the service paused the calls, for the
	 * end of the pause; otherwise at once if rows remain. Does nothing while the merchant paused the drain.
	 *
	 * @return void
	 */
	public function handle_drain(): void {
		if ( get_option( self::PAUSED_OPTION ) ) {
			return;
		}

		$result = $this->run( self::BATCH_PRODUCTS, self::BATCH_SECONDS );

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( 'paused' === $result['stopped'] ) {
			$pause = $this->connection->pause();
			if ( null !== $pause && null !== $pause['until'] ) {
				as_schedule_single_action( max( time(), $pause['until'] ), self::HOOK, array(), self::GROUP );
			}
			return;
		}

		if ( $result['remaining'] > 0 ) {
			as_schedule_single_action( time(), self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Process up to $max_products queue rows, stopping earlier on the seconds budget, a pause the service asked
	 * for, or an empty queue.
	 *
	 * @param int $max_products Max products to process.
	 * @param int $max_seconds  Max seconds to spend.
	 * @return array{processed: int, failed: int, remaining: int, stopped: string}
	 */
	public function run( int $max_products, int $max_seconds ): array {
		// Each run first queues at most 500 products of deleted terms (C-055 R5).
		$this->queue->move_backlog();

		$deadline  = microtime( true ) + $max_seconds;
		$processed = 0;
		$failed    = 0;
		$stopped   = 'max';

		// No call while the service asked to wait, the key was refused or the site is a copy (C-063 R5, R10). A
		// pause whose time has come ends here.
		if ( $this->connection->paused() || ! $this->connection->connected() ) {
			return $this->summary( 0, 0, 'paused' );
		}
		$this->connection->clear_pause();

		for ( $i = 0; $i < $max_products; $i++ ) {
			if ( microtime( true ) >= $deadline ) {
				$stopped = 'max';
				break;
			}

			$ids = $this->queue->next( 1 );
			if ( empty( $ids ) ) {
				$stopped = 'empty';
				break;
			}

			$product_id = $ids[0];
			$result     = $this->curator->build( $product_id );

			$this->last_run->record( $product_id, $result['status'], $result['error'], $result['input_tokens'], $result['output_tokens'], $result['ms'], $result['cached'] );

			if ( 'failed' === $result['status'] && isset( $result['service_error'] ) && $this->react( $result['service_error'] ) ) {
				$stopped = 'paused';
				break;
			}

			if ( 'failed' === $result['status'] ) {
				++$failed;
				$attempts = $this->queue->fail( $product_id, $result['error'] );
				if ( $attempts >= 3 ) {
					$this->mark_failed_state( $product_id );
				}
				continue;
			}

			++$processed;
			$this->queue->remove( $product_id );
		}

		return $this->summary( $processed, $failed, $stopped );
	}

	/**
	 * The service's answer that stops the whole run rather than counting against one product (C-063 R10): the
	 * pause it sets, and when the drain comes back. Returns false for an answer about this product alone.
	 *
	 * @param ServiceError $error What the service said.
	 * @return bool
	 */
	private function react( ServiceError $error ): bool {
		$code  = $error->error_code();
		$retry = $error->retry_after();
		switch ( $code ) {
			case 'quota_exhausted':
				$this->connection->set_pause( 'quota', $error->resume_at() ?? time() + HOUR_IN_SECONDS );
				return true;
			case 'rate_limited':
			case 'busy':
				$this->connection->set_pause( 'busy', time() + max( 1, $retry ?? 60 ) );
				return true;
			case 'unavailable':
			case 'transport':
				$this->connection->set_pause( 'down', time() + max( 60, $retry ?? 60 ) );
				return true;
			case 'unauthorized':
				$this->connection->set_pause( 'refused', null );
				return true;
			case 'site_revoked':
				$this->connection->set_pause( 'revoked', null );
				return true;
			case 'site_mismatch':
				$this->connection->set_pause( 'copy', null );
				return true;
		}
		// A 5xx with no code of its own is the service down as well.
		if ( $error->http_status() >= 500 ) {
			$this->connection->set_pause( 'down', time() + max( 60, $retry ?? 60 ) );
			return true;
		}

		return false;
	}

	/**
	 * A run's result.
	 *
	 * @param int    $processed Products picked.
	 * @param int    $failed    Products that failed.
	 * @param string $stopped   Why the run ended.
	 * @return array{processed: int, failed: int, remaining: int, stopped: string}
	 */
	private function summary( int $processed, int $failed, string $stopped ): array {
		return array(
			'processed' => $processed,
			'failed'    => $failed,
			'remaining' => $this->queue->count() + $this->queue->backlog_count(),
			'stopped'   => $stopped,
		);
	}

	/**
	 * Mark a product's stored state as failed, keeping any other fields.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function mark_failed_state( int $product_id ): void {
		$state           = $this->state->get( $product_id ) ?? array();
		$state['status'] = 'failed';
		$this->state->set( $product_id, $state );
	}
}
