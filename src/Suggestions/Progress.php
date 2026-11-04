<?php
/**
 * How far the suggestions are: done, waiting, given up, and whether the work runs now (C-0493ee8 R1).
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

use Vscm\WooRelatedProducts\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the plugin's own tables and options only: no product is loaded and the service is not called (R4).
 */
final class Progress {

	/**
	 * The queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * The stored suggestions.
	 *
	 * @var Links
	 */
	private Links $links;

	/**
	 * The last attempt.
	 *
	 * @var LastRun
	 */
	private LastRun $last_run;

	/**
	 * The connection.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * The settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Build.
	 *
	 * @param Queue      $queue      The queue.
	 * @param Links      $links      The stored suggestions.
	 * @param LastRun    $last_run   The last attempt.
	 * @param Connection $connection The connection.
	 * @param Settings   $settings   The settings.
	 */
	public function __construct( Queue $queue, Links $links, LastRun $last_run, Connection $connection, Settings $settings ) {
		$this->queue      = $queue;
		$this->links      = $links;
		$this->last_run   = $last_run;
		$this->connection = $connection;
		$this->settings   = $settings;
	}

	/**
	 * The progress: `paused` while the connection is paused or a copy, or the merchant switched suggestions off;
	 * otherwise `working` while products wait; otherwise `done`.
	 *
	 * @return array{state: string, done: int, waiting: int, failed: int, last_at: string|null}
	 */
	public function read(): array {
		$failed  = $this->queue->failed_count();
		$waiting = max( 0, $this->queue->count() + $this->queue->backlog_count() - $failed );
		$off     = empty( $this->settings->get()['serviceEnabled'] );
		if ( $off || $this->connection->paused() || $this->connection->is_copy() ) {
			$state = 'paused';
		} else {
			$state = $waiting > 0 ? 'working' : 'done';
		}

		return array(
			'state'   => $state,
			'done'    => $this->links->count_products(),
			'waiting' => $waiting,
			'failed'  => $failed,
			'last_at' => $this->last_run->at(),
		);
	}
}
