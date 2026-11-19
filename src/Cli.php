<?php
/**
 * `wp vscm related …` commands for the suggestions, for every shop since 2026-11-16.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts;

use WP_CLI;
use WP_CLI\Formatter;
use Vscm\WooRelatedProducts\Suggestions\Calls;
use Vscm\WooRelatedProducts\Suggestions\Catalog;
use Vscm\WooRelatedProducts\Suggestions\Curator;
use Vscm\WooRelatedProducts\Suggestions\LastRun;
use Vscm\WooRelatedProducts\Suggestions\Links;
use Vscm\WooRelatedProducts\Suggestions\Queue;
use Vscm\WooRelatedProducts\Suggestions\ServiceClient;
use Vscm\WooRelatedProducts\Suggestions\State;
use Vscm\WooRelatedProducts\Suggestions\Worker;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands: status, rebuild, drain, queue, links.
 */
class Cli {

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
	 * Daily call counter.
	 *
	 * @var Calls
	 */
	private Calls $calls;

	/**
	 * Link rows repository.
	 *
	 * @var Links
	 */
	private Links $links;

	/**
	 * Generation state repository.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * The suggestion service's HTTP client.
	 *
	 * @var ServiceClient
	 */
	private ServiceClient $service;

	/**
	 * Worker.
	 *
	 * @var Worker
	 */
	private Worker $worker;

	/**
	 * Last build attempt repository.
	 *
	 * @var LastRun
	 */
	private LastRun $last_run;

	/**
	 * Build.
	 *
	 * @param Queue         $queue    Queue repository.
	 * @param Curator       $curator  Curator.
	 * @param Calls         $calls    Daily call counter.
	 * @param Links         $links    Link rows repository.
	 * @param State         $state    Generation state repository.
	 * @param ServiceClient $service  The suggestion service's HTTP client.
	 * @param Worker        $worker   Worker.
	 * @param LastRun       $last_run Last build attempt repository.
	 */
	public function __construct( Queue $queue, Curator $curator, Calls $calls, Links $links, State $state, ServiceClient $service, Worker $worker, LastRun $last_run ) {
		$this->queue    = $queue;
		$this->curator  = $curator;
		$this->calls    = $calls;
		$this->links    = $links;
		$this->state    = $state;
		$this->service  = $service;
		$this->worker   = $worker;
		$this->last_run = $last_run;
	}

	/**
	 * Feature status: enabled/configured/paused, product freshness, queue and call counts.
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void {
		$settings = get_option( 'vscm_woo_related_products_settings' );
		$settings = is_array( $settings ) ? $settings : array();

		$published_ids = $this->published_product_ids();
		$fresh         = $this->state->count_fresh( $published_ids );

		$data = array(
			'enabled'        => ! empty( $settings['serviceEnabled'] ),
			'configured'     => $this->service->is_configured(),
			'paused'         => (bool) get_option( 'vscm_related_service_paused' ),
			'products'       => count( $published_ids ),
			'fresh'          => $fresh,
			'stale'          => count( $published_ids ) - $fresh,
			'queued'         => $this->queue->count(),
			'backlog'        => $this->queue->backlog_count(),
			'failed'         => $this->queue->failed_count(),
			'calls_today'    => $this->calls->today(),
			'queue_by_cause' => $this->queue->by_cause(),
		);

		$this->print_assoc( $data, $assoc_args );
	}

	/**
	 * Queue products for rebuild; with --product and --sync, build it now.
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function rebuild( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';

		if ( isset( $assoc_args['all'] ) ) {
			$count = $this->queue->enqueue( $this->published_product_ids(), 'rebuild-all' );
			$this->print_assoc( array( 'queued' => $count ), $assoc_args );
			return;
		}

		if ( ! isset( $assoc_args['product'] ) ) {
			// @phpstan-ignore-next-line -- WP-CLI class not covered by the installed WP-only stub package.
			WP_CLI::error( 'Specify --product=<id> or --all.' );
			return;
		}

		$product_id = (int) $assoc_args['product'];
		$this->queue->enqueue( array( $product_id ), 'manual' );

		if ( ! isset( $assoc_args['sync'] ) ) {
			$this->print_assoc(
				array(
					'product_id' => $product_id,
					'queued'     => true,
				),
				$assoc_args
			);
			return;
		}

		$result = $this->curator->build( $product_id );

		if ( 'failed' === $result['status'] ) {
			$this->queue->fail( $product_id, $result['error'] );
		} else {
			$this->queue->remove( $product_id );
		}

		$this->last_run->record( $product_id, $result['status'], $result['error'], $result['input_tokens'], $result['output_tokens'], $result['ms'], $result['cached'] );

		$this->print_assoc(
			array(
				'product_id' => $product_id,
				'status'     => $result['status'],
				'related'    => $result['related'],
				'error'      => $result['error'],
			),
			$assoc_args
		);
	}

	/**
	 * Process the queue now, ignoring the paused flag.
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function drain( array $args, array $assoc_args ): void {
		// Without --max, drain everything that is available now.
		$max = isset( $assoc_args['max'] ) ? max( 1, (int) $assoc_args['max'] ) : PHP_INT_MAX;

		$this->print_assoc( $this->worker->run( $max, DAY_IN_SECONDS ), $assoc_args );
	}

	/**
	 * List queue rows, or empty the queue with --clear.
	 *
	 * ## OPTIONS
	 *
	 * [--clear]
	 * : Delete every queued row (built related products are kept) and print how many were removed.
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function queue( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['clear'] ) ) {
			$this->print_assoc( array( 'removed' => $this->queue->clear() ), $assoc_args );
			return;
		}

		$rows = array_map(
			function ( array $row ): array {
				return array(
					'product_id'   => (int) $row['product_id'],
					'cause'        => $row['cause'],
					'queued_at'    => $row['queued_at'],
					'available_at' => $row['available_at'],
					'attempts'     => (int) $row['attempts'],
					'last_error'   => (string) $row['last_error'],
				);
			},
			$this->queue->all()
		);

		$this->print_list( $rows, array( 'product_id', 'cause', 'queued_at', 'available_at', 'attempts', 'last_error' ), $assoc_args );
	}

	/**
	 * List a product's stored link rows.
	 *
	 * @param array<int, string>    $args       Positional args: [0] product ID.
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function links( array $args, array $assoc_args ): void {
		$product_id = (int) ( $args[0] ?? 0 );

		$rows = array_map(
			function ( array $row ): array {
				return array(
					'related_id' => (int) $row['related_id'],
					'position'   => (int) $row['position'],
					'reason'     => $row['reason'],
					'model'      => $row['model'],
				);
			},
			$this->links->for_product( $product_id )
		);

		$this->print_list( $rows, array( 'related_id', 'position', 'reason', 'model' ), $assoc_args );
	}

	/**
	 * Published product IDs.
	 *
	 * @return int[]
	 */
	private function published_product_ids(): array {
		return Catalog::published_ids();
	}

	/**
	 * Print a single associative result as JSON, or as lines.
	 *
	 * @param array<string, mixed>  $data       Data to print.
	 * @param array<string, string> $assoc_args Associative args, reads 'format'.
	 * @return void
	 */
	private function print_assoc( array $data, array $assoc_args ): void {
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			// @phpstan-ignore-next-line -- WP-CLI class not covered by the installed WP-only stub package.
			WP_CLI::line( (string) wp_json_encode( $data ) );
			return;
		}

		foreach ( $data as $key => $value ) {
			// @phpstan-ignore-next-line -- WP-CLI class not covered by the installed WP-only stub package.
			WP_CLI::line( $key . ': ' . ( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) ) );
		}
	}

	/**
	 * Print a list of rows as JSON, or as a table.
	 *
	 * @param array<int, array<string, mixed>> $rows       Rows to print.
	 * @param string[]                         $fields     Column order.
	 * @param array<string, string>            $assoc_args Associative args, reads 'format'.
	 * @return void
	 */
	private function print_list( array $rows, array $fields, array $assoc_args ): void {
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			// @phpstan-ignore-next-line -- WP-CLI class not covered by the installed WP-only stub package.
			WP_CLI::line( (string) wp_json_encode( $rows ) );
			return;
		}

		$table_args = array( 'format' => 'table' );
		// @phpstan-ignore-next-line -- WP-CLI class not covered by the installed WP-only stub package.
		$formatter = new Formatter( $table_args, $fields );
		// @phpstan-ignore-next-line -- WP-CLI class not covered by the installed WP-only stub package.
		$formatter->display_items( $rows );
	}
}
