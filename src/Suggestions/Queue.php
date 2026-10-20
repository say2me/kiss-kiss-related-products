<?php
/**
 * Repository for the {prefix}vscm_related_queue table.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Queue of products awaiting suggestions.
 */
class Queue {

	/**
	 * Max attempts before a row is considered failed and skipped by next().
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Products per multi-row upsert in enqueue(); filterable via vscm_related_service_enqueue_chunk_size.
	 */
	private const ENQUEUE_CHUNK_SIZE = 500;

	/**
	 * Backlog ids moved into the queue per worker run (C-055 R5).
	 */
	private const BACKLOG_STEP = 500;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'vscm_related_queue';
	}

	/**
	 * Backlog table name: products of deleted terms, waiting to be queued with cause 'terms'.
	 *
	 * @return string
	 */
	private function backlog_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'vscm_related_backlog';
	}

	/**
	 * Queue product IDs with a cause. Keeps one row per product: an existing row gets the
	 * newest cause and queued_at, and its attempts/available_at/last_error are reset unless
	 * it is currently backing off from a failure.
	 *
	 * @param int[]  $ids   Product IDs.
	 * @param string $cause Cause. One of new, updated, neighbour-changed, neighbour-removed, terms, manual, rebuild-all.
	 * @return int Number of products queued.
	 */
	public function enqueue( array $ids, string $cause ): int {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$table = $this->table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		/**
		 * Filters how many products one enqueue() upsert statement carries.
		 *
		 * @param int $size Rows per statement.
		 */
		$chunk_size = max( 1, (int) apply_filters( 'vscm_related_service_enqueue_chunk_size', self::ENQUEUE_CHUNK_SIZE ) );

		// A row that is backing off (attempts > 0, available_at still in the future) keeps
		// attempts/available_at/last_error; any other row is reset. Assignments run left to right
		// and later ones see earlier results, so last_error and available_at are evaluated first,
		// while attempts and available_at still hold the row's own values.
		foreach ( array_chunk( $ids, $chunk_size ) as $chunk ) {
			$rows = implode( ', ', array_fill( 0, count( $chunk ), '(%d, %s, %s, %s, 0, NULL)' ) );
			$args = array( $table );
			foreach ( $chunk as $id ) {
				array_push( $args, $id, $cause, $now, $now );
			}
			array_push( $args, $now, $now, $now );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- repository owns its own table; the interpolated rows are fixed placeholders, four per product, so the count is known only at run time.
			$wpdb->query( $wpdb->prepare( "INSERT INTO %i (product_id, cause, queued_at, available_at, attempts, last_error) VALUES {$rows} ON DUPLICATE KEY UPDATE cause = VALUES(cause), queued_at = VALUES(queued_at), last_error = IF(attempts > 0 AND available_at > %s, last_error, NULL), available_at = IF(attempts > 0 AND available_at > %s, available_at, VALUES(available_at)), attempts = IF(attempts > 0 AND available_at > %s, attempts, 0)", $args ) );
		}

		do_action( 'vscm_related_service_queued', $ids, $cause );

		return count( $ids );
	}

	/**
	 * Remove a product's queue row.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function remove( int $product_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$wpdb->delete( $this->table(), array( 'product_id' => $product_id ), array( '%d' ) );
	}

	/**
	 * Copy a term's published products into the backlog, in one statement and before the term's
	 * relationships are deleted. Nothing proportional to the term's size passes through PHP (C-055 R5).
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return int Ids added to the backlog.
	 */
	public function backlog_term( int $term_id, string $taxonomy ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		return (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (product_id, added_at)
				SELECT tr.object_id, %s FROM %i tr
				JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				JOIN %i p ON p.ID = tr.object_id
				WHERE tt.term_id = %d AND tt.taxonomy = %s AND p.post_type = 'product' AND p.post_status = 'publish'",
				$this->backlog_table(),
				gmdate( 'Y-m-d H:i:s' ),
				$wpdb->term_relationships,
				$wpdb->term_taxonomy,
				$wpdb->posts,
				$term_id,
				$taxonomy
			)
		);
	}

	/**
	 * Move at most 500 backlog ids into the queue with cause 'terms'. A product that already has a
	 * row keeps it and its cause; one no longer published is dropped (C-055 R5).
	 *
	 * @return int Ids taken off the backlog.
	 */
	public function move_backlog(): int {
		global $wpdb;

		$backlog = $this->backlog_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT product_id FROM %i ORDER BY product_id LIMIT %d', $backlog, self::BACKLOG_STEP ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$now  = gmdate( 'Y-m-d H:i:s' );
		$list = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- repository owns its own table; the interpolated list is placeholders, one per id.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (product_id, cause, queued_at, available_at, attempts, last_error) SELECT ID, 'terms', %s, %s, 0, NULL FROM %i WHERE ID IN ({$list}) AND post_type = 'product' AND post_status = 'publish'", array_merge( array( $this->table(), $now, $now, $wpdb->posts ), $ids ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- repository owns its own table; the interpolated list is placeholders, one per id.
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE product_id IN ({$list})", array_merge( array( $backlog ), $ids ) ) );

		return count( $ids );
	}

	/**
	 * Ids waiting in the backlog.
	 *
	 * @return int
	 */
	public function backlog_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->backlog_table() ) );
	}

	/**
	 * Product IDs ready to process: available_at <= now, attempts < max; rows of every other cause
	 * before 'terms' rows (C-055 R6), then the best sellers by WooCommerce's total_sales, newest first (C-063 R12),
	 * then the oldest queued_at.
	 *
	 * @param int $limit Max rows.
	 * @return int[]
	 */
	public function next( int $limit ): array {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT q.product_id FROM %i q LEFT JOIN %i l ON l.product_id = q.product_id LEFT JOIN %i p ON p.ID = q.product_id WHERE q.available_at <= %s AND q.attempts < %d ORDER BY (q.cause = 'terms') ASC, COALESCE( l.total_sales, 0 ) DESC, p.post_date DESC, q.queued_at ASC LIMIT %d",
				$this->table(),
				$wpdb->prefix . 'wc_product_meta_lookup',
				$wpdb->posts,
				$now,
				self::MAX_ATTEMPTS,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Record a failed attempt: attempts+1, back off available_at, store the error.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $error      Error message.
	 * @return int The new attempts count.
	 */
	public function fail( int $product_id, string $error ): int {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$attempts = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attempts FROM %i WHERE product_id = %d', $table, $product_id ) );
		++$attempts;

		$available_at = gmdate( 'Y-m-d H:i:s', time() + ( 60 * $attempts ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$wpdb->update(
			$table,
			array(
				'attempts'     => $attempts,
				'available_at' => $available_at,
				'last_error'   => $error,
			),
			array( 'product_id' => $product_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);

		return $attempts;
	}

	/**
	 * All queue rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY queued_at ASC', $this->table() ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total row count.
	 *
	 * @return int
	 */
	public function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table() ) );
	}

	/**
	 * Count of rows that exhausted their retry budget.
	 *
	 * @return int
	 */
	public function failed_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE attempts >= %d', $this->table(), self::MAX_ATTEMPTS ) );
	}

	/**
	 * Row counts grouped by cause.
	 *
	 * @return array<string, int>
	 */
	public function by_cause(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT cause, COUNT(*) AS total FROM %i GROUP BY cause', $this->table() ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['cause'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Remaining (attempts < max) and failed (attempts >= max) row counts for one cause.
	 *
	 * @param string $cause Cause.
	 * @return array{remaining: int, failed: int}
	 */
	public function cause_counts( string $cause ): array {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE cause = %s AND attempts < %d', $table, $cause, self::MAX_ATTEMPTS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$failed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE cause = %s AND attempts >= %d', $table, $cause, self::MAX_ATTEMPTS ) );

		return array(
			'remaining' => $remaining,
			'failed'    => $failed,
		);
	}

	/**
	 * Delete every row with a given cause.
	 *
	 * @param string $cause Cause.
	 * @return int Number of rows deleted.
	 */
	public function delete_cause( string $cause ): int {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE cause = %s', $table, $cause ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$wpdb->delete( $table, array( 'cause' => $cause ), array( '%s' ) );

		return $count;
	}

	/**
	 * Delete every row, whatever its cause, and every id waiting in the backlog: otherwise the next
	 * worker run would queue the backlog again (C-070 R4).
	 *
	 * @return int Number of queue rows and backlog ids deleted.
	 */
	public function clear(): int {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );

		// One statement; its affected-row count is the number of ids removed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$backlog = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->backlog_table() ) );

		return $count + $backlog;
	}

	/**
	 * Reset every failed row (attempts >= max) so it is picked up again: attempts 0, available now.
	 *
	 * @return int Number of rows reset.
	 */
	public function retry_failed(): int {
		global $wpdb;

		$table = $this->table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE attempts >= %d', $table, self::MAX_ATTEMPTS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET attempts = 0, available_at = %s, last_error = %s WHERE attempts >= %d',
				$table,
				$now,
				'',
				self::MAX_ATTEMPTS
			)
		);

		return $count;
	}

	/**
	 * The oldest queue rows, up to a limit.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function rows( int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repository owns its own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY queued_at ASC LIMIT %d', $this->table(), $limit ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}
}
