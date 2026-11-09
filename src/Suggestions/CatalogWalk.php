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
 * The walk queues every published product without fresh suggestions, a page per Action Scheduler action, so no
 * request carries the whole catalogue; the shop's monthly allowance then paces the builds. It runs once per connected
 * site: after Connect, and for a site connected before 1.0.2, at its first admin request after the update.
 */
class CatalogWalk implements InitableInterface {

	/**
	 * Action Scheduler hook of one page.
	 */
	public const HOOK = 'vscm_related_catalog_walk';

	/**
	 * The site the catalogue was queued for, its site id: read on every admin request, so autoloaded.
	 */
	public const OPTION = 'vscm_related_catalog_walked';

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
	 * Start on the admin screens, and run the pages.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'maybe_start' ) );
		add_action( self::HOOK, array( $this, 'walk' ) );
	}

	/**
	 * Start the walk from the first product, once for the connected site.
	 *
	 * @return void
	 */
	public function maybe_start(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! $this->connection->connected() ) {
			return;
		}
		$site_id = (string) ( $this->connection->site()['site_id'] ?? '' );
		if ( '' === $site_id || get_option( self::OPTION ) === $site_id ) {
			return;
		}
		update_option( self::OPTION, $site_id, true );
		as_enqueue_async_action( self::HOOK, array( 0 ), Worker::GROUP );
	}

	/**
	 * One page: the published products after an ID, the ones without fresh suggestions queued, the queued ones left
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
		$this->queue->add( array_values( array_filter( $ids, fn( int $id ): bool => ! $this->state->is_fresh( $id ) ) ), self::CAUSE );

		if ( count( $ids ) === $size && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( max( $ids ) ), Worker::GROUP );
		}
	}
}
