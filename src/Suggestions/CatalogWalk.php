<?php
/**
 * The catalogue queued once a site is connected.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

use Vscm\WooRelatedProducts\InitableInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Connect promises suggestions for the best sellers, and the queue serves the best sellers first (C-063 R12). Until
 * 1.0.2, though, only products that changed after connecting were ever queued, so a shop could connect and see none.
 *
 * The walk queues every published product whose suggestions are not fresh or are over six months old, a page per
 * Action Scheduler action, so no request carries the whole catalogue; the shop's monthly allowance then paces the
 * builds. It starts for a connected site after Connect, and again each month (1.0.3): an admin request or the daily
 * check starts it, so the suggestions stay current without anyone opening the admin.
 */
class CatalogWalk implements InitableInterface {

	/**
	 * Action Scheduler hook of one page.
	 */
	public const HOOK = 'vscm_related_catalog_walk';

	/**
	 * Action Scheduler hook of the daily check that starts the month's walk.
	 */
	public const CHECK = 'vscm_related_catalog_walk_check';

	/**
	 * The last walk: the site it was for (its site id) and when it started. Read on every admin request, so autoloaded.
	 */
	public const OPTION = 'vscm_related_catalog_walked';

	/**
	 * How old suggestions may get before the walk queues their product again.
	 */
	public const AGE = 6 * MONTH_IN_SECONDS;

	/**
	 * Queue cause of the walk's rows.
	 */
	public const CAUSE = 'catalog';

	/**
	 * Products per page.
	 */
	public const PAGE = 500;

	/**
	 * Queue repository.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Generation state repository.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * The site's connection.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Build.
	 *
	 * @param Queue      $queue      Queue repository.
	 * @param State      $state      Generation state repository.
	 * @param Connection $connection The site's connection.
	 */
	public function __construct( Queue $queue, State $state, Connection $connection ) {
		$this->queue      = $queue;
		$this->state      = $state;
		$this->connection = $connection;
	}

	/**
	 * Start on the admin screens and from the daily check, and run the pages.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'maybe_start' ) );
		add_action( self::CHECK, array( $this, 'maybe_start' ) );
		add_action( self::HOOK, array( $this, 'walk' ) );
	}

	/**
	 * Start the walk from the first product for the connected site, unless it started for this site within the
	 * month; and keep the daily check scheduled that starts the next month's walk.
	 *
	 * @return void
	 */
	public function maybe_start(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! $this->connection->connected() ) {
			return;
		}
		$site_id = (string) ( $this->connection->site()['site_id'] ?? '' );
		if ( '' === $site_id ) {
			return;
		}
		// 1.0.2 kept the site id alone, with no time: that site is walked once more.
		$walked = get_option( self::OPTION );
		$walked = is_array( $walked ) ? $walked : array();
		if ( ( $walked['site'] ?? '' ) === $site_id && time() - (int) ( $walked['at'] ?? 0 ) < MONTH_IN_SECONDS ) {
			return;
		}
		update_option(
			self::OPTION,
			array(
				'site' => $site_id,
				'at'   => time(),
			),
			true
		);
		as_enqueue_async_action( self::HOOK, array( 0 ), Worker::GROUP );
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) && ! as_has_scheduled_action( self::CHECK, array(), Worker::GROUP ) ) {
			as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::CHECK, array(), Worker::GROUP );
		}
	}

	/**
	 * One page: the published products after an ID, the ones whose suggestions are due queued, the queued ones left
	 * as they are, and the next page scheduled while this one was full. A site no longer connected stops the walk.
	 *
	 * @param int|string $after The last product ID of the previous page; 0 for the first.
	 * @return void
	 */
	public function walk( $after = 0 ): void {
		if ( ! $this->connection->connected() ) {
			return;
		}

		/**
		 * Filters how many products one page of the catalogue walk reads and queues.
		 *
		 * @param int $size Products per page.
		 */
		$size = max( 1, (int) apply_filters( 'vscm_related_catalog_walk_page', self::PAGE ) );
		$ids  = Catalog::published_ids_after( (int) $after, $size );
		if ( array() === $ids ) {
			return;
		}

		update_meta_cache( 'post', $ids );
		$this->queue->add( array_values( array_filter( $ids, array( $this, 'due' ) ) ), self::CAUSE );

		if ( count( $ids ) === $size && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( max( $ids ) ), Worker::GROUP );
		}
	}

	/**
	 * Whether a product's suggestions are due: none fresh, or made more than six months ago.
	 *
	 * @param int $id Product ID.
	 * @return bool
	 */
	private function due( int $id ): bool {
		if ( ! $this->state->is_fresh( $id ) ) {
			return true;
		}
		$made = strtotime( (string) ( $this->state->get( $id )['generated_at'] ?? '' ) );

		return false === $made || time() - $made > self::AGE;
	}
}
