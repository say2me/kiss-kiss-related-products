<?php
/**
 * The "Suggestions" section of the settings page: consent and connect, the site's status, buy, and why the
 * suggestions are paused (C-063 R1–R3, R6–R8).
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Admin;

use Vscm\WooRelatedProducts\InitableInterface;
use Vscm\WooRelatedProducts\Suggestions\Connection;
use Vscm\WooRelatedProducts\Suggestions\ServiceClient;
use Vscm\WooRelatedProducts\Suggestions\ServiceError;
use Vscm\WooRelatedProducts\Suggestions\SiteStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Rendered by the settings page; its three actions post to admin-post.php with their own nonces.
 */
class ConnectionPanel implements InitableInterface {

	public const CONNECT    = 'vscm_related_connect';
	public const DISCONNECT = 'vscm_related_disconnect';
	public const REFRESH    = 'vscm_related_refresh';

	/**
	 * The sales site, where the buy, cabinet and legal pages live (C-064). A developer points the plugin at another
	 * one (a local shop) with the vscm_related_sales_site filter; a merchant never sets it.
	 */
	public const SALES_SITE = 'https://vasenin.com';

	/**
	 * The sales site's pages, under it.
	 */
	public const TERMS_PATH   = 'terms/';
	public const PRIVACY_PATH = 'privacy/';
	public const BUY_PATH     = 'shop/aiai/buy/';
	public const CABINET_PATH = 'shop/aiai/cabinet/';

	/**
	 * Transient prefix for the outcome of the last connect, per user.
	 */
	private const NOTICE = 'vscm_related_connect_notice_';

	/**
	 * The connection.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * The site's status.
	 *
	 * @var SiteStatus
	 */
	private SiteStatus $status;

	/**
	 * Build.
	 *
	 * @param Connection $connection The connection.
	 * @param SiteStatus $status     The site's status.
	 */
	public function __construct( Connection $connection, SiteStatus $status ) {
		$this->connection = $connection;
		$this->status     = $status;
	}

	/**
	 * A page of the sales site: the address the vscm_related_sales_site filter gives, when it is an http(s) URL,
	 * else vasenin.com (C-064 R5).
	 *
	 * @param string $path The page under the site, such as "terms/".
	 * @return string
	 */
	public static function sales_url( string $path ): string {
		/**
		 * Filters the sales site's address (C-064): a development stack points it at a local shop.
		 *
		 * @param string $site The address, with no trailing slash.
		 */
		$site = apply_filters( 'vscm_related_sales_site', self::SALES_SITE );
		$site = is_string( $site ) && 1 === preg_match( '#^https?://[^/?\#\s]+(/[^?\#\s]*)?$#i', $site ) ? rtrim( $site, '/' ) : self::SALES_SITE;

		return $site . '/' . ltrim( $path, '/' );
	}

	/**
	 * Register the actions.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_post_' . self::CONNECT, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . self::DISCONNECT, array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_' . self::REFRESH, array( $this, 'handle_refresh' ) );
	}

	/**
	 * Connect (R2): the only path that sends anything before the merchant agreed.
	 *
	 * @return void
	 */
	public function handle_connect(): void {
		$this->guard( self::CONNECT );
		try {
			$this->connection->connect( VSCM_WOO_VERSION );
			$notice = array(
				'ok'   => true,
				'text' => __( 'Connected. Suggestions for your best sellers start with the next queue run.', 'kiss-kiss-related-products' ),
			);
		} catch ( ServiceError $error ) {
			$notice = array(
				'ok'   => false,
				'text' => $this->explain( $error ),
			);
		}
		set_transient( self::NOTICE . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		$this->back();
	}

	/**
	 * Disconnect (R3).
	 *
	 * @return void
	 */
	public function handle_disconnect(): void {
		$this->guard( self::DISCONNECT );
		$this->connection->disconnect();
		$this->back();
	}

	/**
	 * Read the status again (R6).
	 *
	 * @return void
	 */
	public function handle_refresh(): void {
		$this->guard( self::REFRESH );
		$this->status->get( true );
		$this->back();
	}

	/**
	 * The section.
	 *
	 * @return void
	 */
	public function render(): void {
		echo '<h2 id="vscm-suggestions">' . esc_html__( 'Suggestions', 'kiss-kiss-related-products' ) . '</h2>';
		$this->render_notice();
		$client = new ServiceClient();
		if ( null === $this->connection->site() && ! $this->connection->linked() ) {
			$this->render_consent( $client->is_configured() );
			return;
		}
		$this->render_status();
		// A copy may become a shop of its own: connecting it creates a new site (R5).
		if ( $this->connection->is_copy() ) {
			echo '<h3>' . esc_html__( 'Connect this copy as a shop of its own', 'kiss-kiss-related-products' ) . '</h3>';
			$this->render_consent( $client->is_configured() );
		}
	}

	/**
	 * The consent text and Connect (R1).
	 *
	 * @param bool $configured Whether the service has an address.
	 * @return void
	 */
	private function render_consent( bool $configured ): void {
		echo '<p>' . esc_html__( 'Connect this shop to the suggestion service to get suggested related products, with a short reason, for your best sellers. The free plan includes 100 suggestions a month.', 'kiss-kiss-related-products' ) . '</p>';
		echo '<div class="vscm-consent"><p><strong>' . esc_html(
			sprintf(
				/* translators: %s: version number of the consent text */
				__( 'What is sent (version %s)', 'kiss-kiss-related-products' ),
				Connection::CONSENT_VERSION
			)
		) . '</strong></p><p>' . esc_html__( 'For each product: its name, brand, categories, tags, attributes, price or price band, a description of up to 300 characters and its stock status. Nothing about your customers or orders. The service runs in the EU and keeps no product data.', 'kiss-kiss-related-products' ) . '</p><p>';
		printf(
			/* translators: 1: Terms of Use link, 2: Privacy Policy link */
			esc_html__( 'By connecting you agree to the %1$s and the %2$s.', 'kiss-kiss-related-products' ),
			'<a href="' . esc_url( self::sales_url( self::TERMS_PATH ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Terms of Use', 'kiss-kiss-related-products' ) . '</a>',
			'<a href="' . esc_url( self::sales_url( self::PRIVACY_PATH ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy Policy', 'kiss-kiss-related-products' ) . '</a>'
		);
		echo '</p></div>';
		$this->open_form( 'vscm-connect-form', self::CONNECT );
		if ( $configured ) {
			submit_button( __( 'Connect', 'kiss-kiss-related-products' ), 'primary', 'submit', false );
		} else {
			submit_button( __( 'Connect', 'kiss-kiss-related-products' ), 'primary', 'submit', false, array( 'disabled' => 'disabled' ) );
			echo ' <span class="description">' . esc_html__( 'The suggestion service is not available yet.', 'kiss-kiss-related-products' ) . '</span>';
		}
		echo '</form>';
	}

	/**
	 * The status, the pause and the actions (R6–R8).
	 *
	 * @return void
	 */
	private function render_status(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only flag set by the sales site's return link.
		$returned = isset( $_GET['vscm-bought'] );
		$related  = $this->status->related( $returned );
		$site     = $this->connection->site();
		$rows     = array();

		$reason = $this->pause_reason();
		$rows[ __( 'Status', 'kiss-kiss-related-products' ) ] = $reason ?? __( 'On', 'kiss-kiss-related-products' );
		if ( null !== $related ) {
			$rows[ __( 'Plan', 'kiss-kiss-related-products' ) ] = 'free' === $related['tier'] ? __( 'Free plan', 'kiss-kiss-related-products' ) : __( 'Purchased plan', 'kiss-kiss-related-products' );
			if ( in_array( $related['status'], array( 'expired', 'revoked' ), true ) ) {
				$rows[ __( 'Licence', 'kiss-kiss-related-products' ) ] = 'expired' === $related['status'] ? __( 'Expired', 'kiss-kiss-related-products' ) : __( 'Refunded', 'kiss-kiss-related-products' );
			}
			if ( null !== $related['ends_at'] ) {
				$rows[ __( 'Ends on', 'kiss-kiss-related-products' ) ] = $this->date( $related['ends_at'] );
			}
			$rows[ __( 'This month', 'kiss-kiss-related-products' ) ] = sprintf(
				/* translators: 1: suggestions used this month, 2: the month's allowance */
				__( '%1$d of %2$d', 'kiss-kiss-related-products' ),
				$related['used'],
				$related['limit']
			);
			if ( null !== $related['resume_at'] ) {
				$rows[ __( 'Resumes on', 'kiss-kiss-related-products' ) ] = $this->date( $related['resume_at'] );
			}
		}

		echo '<table class="form-table" id="vscm-status" role="presentation">';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</table><p>';
		// A copy buys nothing: the site id is the shop's, not the copy's (R5).
		if ( null !== $site && ! $this->connection->is_copy() ) {
			$return = add_query_arg( 'vscm-bought', '1', admin_url( 'admin.php?page=' . SettingsPage::SLUG ) );
			$buy    = add_query_arg(
				array(
					'capability' => 'related',
					'site_id'    => rawurlencode( (string) $site['site_id'] ),
					'return'     => rawurlencode( $return ),
				),
				self::sales_url( self::BUY_PATH )
			);
			echo '<a class="button button-primary" id="vscm-buy" href="' . esc_url( $buy ) . '" target="_blank" rel="noopener">' . esc_html__( 'Buy more suggestions', 'kiss-kiss-related-products' ) . '</a> ';
			if ( null !== $related && 'free' !== $related['tier'] ) {
				echo '<a class="button" href="' . esc_url( self::sales_url( self::CABINET_PATH ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Your licence', 'kiss-kiss-related-products' ) . '</a> ';
			}
		}
		echo '</p>';
		$this->open_form( 'vscm-refresh-form', self::REFRESH );
		submit_button( __( 'Refresh', 'kiss-kiss-related-products' ), 'secondary', 'submit', false );
		echo '</form>';
		if ( null !== $site ) {
			$this->open_form( 'vscm-disconnect-form', self::DISCONNECT );
			submit_button( __( 'Disconnect', 'kiss-kiss-related-products' ), 'delete', 'submit', false );
			echo '</form>';
		}
	}

	/**
	 * Why the suggestions are paused, and until when if known (R7); null when they are not.
	 *
	 * @return string|null
	 */
	private function pause_reason(): ?string {
		if ( $this->connection->is_copy() ) {
			return __( 'Paused: this copy of the shop does not use the shop\'s suggestion allowance. Stored suggestions are still shown.', 'kiss-kiss-related-products' );
		}
		if ( ! $this->connection->paused() ) {
			return null;
		}
		$pause = (array) $this->connection->pause();
		$until = isset( $pause['until'] ) ? $this->date( gmdate( 'c', (int) $pause['until'] ) ) : '';
		switch ( $pause['reason'] ?? '' ) {
			case 'quota':
				/* translators: %s: date */
				return sprintf( __( 'Paused: this month\'s allowance is spent. Suggestions resume on %s.', 'kiss-kiss-related-products' ), $until );
			case 'busy':
				/* translators: %s: date and time */
				return sprintf( __( 'Paused: the suggestion service is busy. Suggestions resume at %s.', 'kiss-kiss-related-products' ), $until );
			case 'down':
				/* translators: %s: date and time */
				return sprintf( __( 'Paused: the suggestion service cannot be reached. The next try is at %s.', 'kiss-kiss-related-products' ), $until );
			case 'refused':
				return __( 'Paused: the service no longer accepts this shop\'s key. Disconnect and connect again.', 'kiss-kiss-related-products' );
			case 'revoked':
				return __( 'Paused: this shop has been suspended by the suggestion service.', 'kiss-kiss-related-products' );
		}

		return null;
	}

	/**
	 * The notice of the last connect, once.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice %1$s inline"><p>%2$s</p></div>', ! empty( $notice['ok'] ) ? 'notice-success' : 'notice-error', esc_html( (string) $notice['text'] ) );
	}

	/**
	 * What a failed connect means for the merchant.
	 *
	 * @param ServiceError $error The failure.
	 * @return string
	 */
	private function explain( ServiceError $error ): string {
		switch ( $error->error_code() ) {
			case 'verification_failed':
				return sprintf(
					/* translators: %s: the reason the service gave */
					__( 'The suggestion service could not confirm this shop at its address (%s). Check that the shop is public, then try again.', 'kiss-kiss-related-products' ),
					'' !== $error->reason() ? $error->reason() : $error->getMessage()
				);
			case 'rate_limited':
				/* translators: %d: seconds */
				return sprintf( __( 'Too many attempts. Try again in %d seconds.', 'kiss-kiss-related-products' ), (int) ( $error->retry_after() ?? 60 ) );
			case 'site_revoked':
				return __( 'This shop has been suspended by the suggestion service.', 'kiss-kiss-related-products' );
			case 'config':
				return __( 'The suggestion service is not available yet.', 'kiss-kiss-related-products' );
		}

		if ( 'transport' === $error->error_code() ) {
			return __( 'The suggestion service could not be reached. Try again later.', 'kiss-kiss-related-products' );
		}

		/* translators: %s: the service's error */
		return sprintf( __( 'The suggestion service refused to connect: %s', 'kiss-kiss-related-products' ), $error->getMessage() );
	}

	/**
	 * A date from the service, in the site's format.
	 *
	 * @param string $iso ISO 8601.
	 * @return string
	 */
	private function date( string $iso ): string {
		$time = strtotime( $iso );

		return false === $time ? $iso : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * Open an admin-post form for an action, with its nonce.
	 *
	 * @param string $id     The form id.
	 * @param string $action The action.
	 * @return void
	 */
	private function open_form( string $id, string $action ): void {
		echo '<form id="' . esc_attr( $id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
	}

	/**
	 * The nonce and the capability of an action.
	 *
	 * @param string $action The action.
	 * @return void
	 */
	private function guard( string $action ): void {
		check_admin_referer( $action );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'kiss-kiss-related-products' ), 403 );
		}
	}

	/**
	 * Back to the settings page.
	 *
	 * @return void
	 */
	private function back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) );
		exit;
	}
}
