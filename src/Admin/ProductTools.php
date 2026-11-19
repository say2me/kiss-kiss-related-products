<?php
/**
 * A product's own related products tools, for every shop (the owner, 2026-11-16).
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Admin;

use Vscm\WooRelatedProducts\InitableInterface;
use Vscm\WooRelatedProducts\Suggestions\Connection;
use Vscm\WooRelatedProducts\Suggestions\Links;
use Vscm\WooRelatedProducts\Suggestions\Queue;
use Vscm\WooRelatedProducts\Woo\Overrides;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The product's edit screen gets a box with its suggestions and the products pinned to it or excluded from it, saved
 * with the product; while the shop is connected, the box and the product list ask for new suggestions. Nothing here
 * depends on a licence: the service's allowance decides how fast a queued product is done.
 */
class ProductTools implements InitableInterface {

	/**
	 * The product list's action that asks for new suggestions.
	 */
	public const ACTION = 'vscm_related_rebuild';

	/**
	 * The box's nonce action and field.
	 */
	private const NONCE = 'vscm_related_product_tools';

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
	 * The pinned and excluded products.
	 *
	 * @var Overrides
	 */
	private Overrides $overrides;

	/**
	 * The connection.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Build.
	 *
	 * @param Queue      $queue      The queue.
	 * @param Links      $links      The stored suggestions.
	 * @param Overrides  $overrides  The pinned and excluded products.
	 * @param Connection $connection The connection.
	 */
	public function __construct( Queue $queue, Links $links, Overrides $overrides, Connection $connection ) {
		$this->queue      = $queue;
		$this->links      = $links;
		$this->overrides  = $overrides;
		$this->connection = $connection;
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_rebuild' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'add_meta_boxes_product', array( $this, 'add_box' ) );
		add_action( 'save_post_product', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Whether this shop may ask for suggestions: connected, and not a copy.
	 *
	 * @return bool
	 */
	private function asks(): bool {
		return null !== $this->connection->site() && ! $this->connection->is_copy();
	}

	/**
	 * A product row's "Find new suggestions", for a shop manager of a connected shop.
	 *
	 * @param array<string, string> $actions The row's actions.
	 * @param WP_Post|mixed         $post    The row's post.
	 * @return array<string, string>
	 */
	public function row_action( array $actions, $post ): array {
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type || ! current_user_can( 'manage_woocommerce' ) || ! $this->asks() ) {
			return $actions;
		}
		// add_query_arg() rather than wp_nonce_url(), which escapes its ampersands: the link is followed as it is.
		$url = add_query_arg(
			array(
				'action'   => self::ACTION,
				'product'  => $post->ID,
				'_wpnonce' => wp_create_nonce( self::ACTION . '_' . $post->ID ),
			),
			admin_url( 'admin-post.php' )
		);

		$actions[ self::ACTION ] = '<a href="' . esc_url( $url, null, 'other' ) . '">' . esc_html__( 'Find new suggestions', 'kiss-kiss-related-products' ) . '</a>';

		return $actions;
	}

	/**
	 * The row action: queue the product and go back with a notice.
	 *
	 * @return void
	 */
	public function handle_rebuild(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'kiss-kiss-related-products' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the id names the nonce action check_admin_referer() verifies below.
		$id = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $id );
		$this->queue->enqueue( array( $id ), 'manual' );

		$back = wp_get_referer();
		wp_safe_redirect( add_query_arg( 'vscm_related_queued', $id, $back ? $back : admin_url( 'edit.php?post_type=product' ) ) );
		exit;
	}

	/**
	 * The notice after a product was queued.
	 *
	 * @return void
	 */
	public function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, after a safe redirect.
		if ( ! isset( $_GET['vscm_related_queued'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, after a safe redirect.
		$id = absint( $_GET['vscm_related_queued'] );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: the product's name */
					__( 'New suggestions for %s are on their way.', 'kiss-kiss-related-products' ),
					get_the_title( $id )
				)
			)
		);
	}

	/**
	 * The box on the product's edit screen.
	 *
	 * @return void
	 */
	public function add_box(): void {
		add_meta_box( 'vscm-related-products', __( 'Related products', 'kiss-kiss-related-products' ), array( $this, 'render' ), 'product', 'side' );
	}

	/**
	 * The box: the stored suggestions with their reasons, the pinned and excluded products, and, while connected, the
	 * choice to ask for new suggestions when the product is saved.
	 *
	 * @param WP_Post $post The product.
	 * @return void
	 */
	public function render( WP_Post $post ): void {
		$id = (int) $post->ID;
		wp_nonce_field( self::NONCE, self::NONCE );
		$rows = $this->links->for_product( $id );
		if ( empty( $rows ) ) {
			echo '<p class="description">' . esc_html__( 'No suggestions yet.', 'kiss-kiss-related-products' ) . '</p>';
		} else {
			echo '<p><strong>' . esc_html__( 'Suggestions', 'kiss-kiss-related-products' ) . '</strong></p><ul>';
			foreach ( $rows as $row ) {
				$related = (int) $row['related_id'];
				$reason  = (string) ( $row['reason'] ?? '' );
				echo '<li><a href="' . esc_url( (string) get_edit_post_link( $related, 'raw' ) ) . '"' . ( '' !== $reason ? ' title="' . esc_attr( $reason ) . '"' : '' ) . '>' . esc_html( get_the_title( $related ) ) . '</a></li>';
			}
			echo '</ul>';
		}
		$this->select( 'vscm_related_pinned', __( 'Pinned: always shown first', 'kiss-kiss-related-products' ), $this->overrides->pinned( $id ) );
		$this->select( 'vscm_related_excluded', __( 'Excluded: never shown', 'kiss-kiss-related-products' ), $this->overrides->excluded( $id ) );
		if ( $this->asks() && current_user_can( 'manage_woocommerce' ) ) {
			echo '<p><label><input type="checkbox" name="vscm_related_find" value="1" /> ' . esc_html__( 'Find new suggestions when I update the product', 'kiss-kiss-related-products' ) . '</label></p>';
		}
	}

	/**
	 * A WooCommerce product search, several products at once, filled with the products it holds.
	 *
	 * @param string $name  The field's name.
	 * @param string $label The field's label.
	 * @param int[]  $ids   The products it holds.
	 * @return void
	 */
	private function select( string $name, string $label, array $ids ): void {
		printf( '<p><label for="%1$s">%2$s</label><br />', esc_attr( $name ), esc_html( $label ) );
		printf(
			'<select multiple class="wc-product-search" style="width:100%%;" id="%1$s" name="%1$s[]" data-action="woocommerce_json_search_products_and_variations" data-placeholder="%2$s">',
			esc_attr( $name ),
			esc_attr__( 'Search for a product…', 'kiss-kiss-related-products' )
		);
		foreach ( $ids as $id ) {
			printf( '<option selected value="%1$d">%2$s</option>', (int) $id, esc_html( get_the_title( (int) $id ) ) );
		}
		echo '</select></p>';
	}

	/**
	 * Save the box with the product: the pinned and excluded products, and the request for new suggestions.
	 *
	 * @param int          $post_id The product.
	 * @param WP_Post|null $post    The product's post.
	 * @return void
	 */
	public function save( $post_id, $post = null ): void {
		$post_id = (int) $post_id;
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$pinned   = isset( $_POST['vscm_related_pinned'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['vscm_related_pinned'] ) ) : array();
		$excluded = isset( $_POST['vscm_related_excluded'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['vscm_related_excluded'] ) ) : array();
		$this->overrides->save( $post_id, $pinned, $excluded );
		if ( ! empty( $_POST['vscm_related_find'] ) && $this->asks() && current_user_can( 'manage_woocommerce' ) ) {
			$this->queue->enqueue( array( $post_id ), 'manual' );
		}
	}
}
