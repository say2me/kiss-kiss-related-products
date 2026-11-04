<?php
/**
 * The "Suggestions" section of the settings page: consent and connect, the site's status, buy, and why the
 * suggestions are paused (C-063 R1–R3, R6–R8), as the Kiss Kiss cookbook's connection, quota and service-error
 * screens (C-ce3b88a R10–R12).
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Admin;

use Vscm\WooRelatedProducts\InitableInterface;
use Vscm\WooRelatedProducts\Suggestions\Connection;
use Vscm\WooRelatedProducts\Suggestions\Progress;
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
	 * The suggestions' progress (C-0493ee8).
	 *
	 * @var Progress
	 */
	private Progress $progress;

	/**
	 * Build.
	 *
	 * @param Connection $connection The connection.
	 * @param SiteStatus $status     The site's status.
	 * @param Progress   $progress   The suggestions' progress.
	 */
	public function __construct( Connection $connection, SiteStatus $status, Progress $progress ) {
		$this->connection = $connection;
		$this->status     = $status;
		$this->progress   = $progress;
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
		// One batch of the waiting products, here and now: the drain the background job runs (Worker::HOOK, named here
		// in full as Plugin Check asks), bounded by the worker's own limits, so products move even where WP-Cron's
		// loopback never fires (C-17e0d80).
		do_action( 'vscm_related_service_drain' );
		$this->back();
	}

	/**
	 * The section: a page of its own under the settings, its title and intro, then one card.
	 *
	 * @return void
	 */
	public function render(): void {
		echo '<div class="kk-page">';
		echo '<h2 class="kk-title" id="vscm-suggestions">' . esc_html__( 'Suggestions', 'kiss-kiss-related-products' ) . '</h2>';
		echo '<p class="kk-intro">' . esc_html__( 'Find products that pair well, even when their categories differ.', 'kiss-kiss-related-products' ) . '</p>';
		$this->render_notice();
		$client = new ServiceClient();
		if ( null === $this->connection->site() && ! $this->connection->linked() ) {
			$this->card_open( 'NOT CONNECTED', '' );
			$this->render_consent( $client->is_configured() );
			echo '</div></div>';
			return;
		}
		$this->render_status();
		// A copy may become a shop of its own: connecting it creates a new site (R5).
		if ( $this->connection->is_copy() ) {
			echo '<div class="kk-card"><h3 class="kk-section-title">' . esc_html__( 'Connect this copy as a shop of its own', 'kiss-kiss-related-products' ) . '</h3>';
			$this->render_consent( $client->is_configured() );
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Open the "Site connection" card with its badge.
	 *
	 * @param string $badge The badge's word.
	 * @param string $tone  The badge's tone: success, warning, danger or '' (neutral).
	 * @return void
	 */
	private function card_open( string $badge, string $tone ): void {
		echo '<div class="kk-card"><div class="kk-card__head"><h3 class="kk-section-title">' . esc_html__( 'Site connection', 'kiss-kiss-related-products' ) . '</h3>';
		echo '<span class="kk-badge' . ( '' !== $tone ? ' kk-badge--' . esc_attr( $tone ) : '' ) . '">' . esc_html( $badge ) . '</span></div>';
	}

	/**
	 * A Notice; $legacy is the WordPress notice class the C-063 check and older code look for.
	 *
	 * @param string $tone   info, success, warning or danger.
	 * @param string $title  The title.
	 * @param string $text   The text, if any.
	 * @param string $legacy notice-success or notice-error, if any.
	 * @return void
	 */
	private function notice( string $tone, string $title, string $text = '', string $legacy = '' ): void {
		printf(
			'<div class="kk-notice kk-notice--%1$s%2$s" role="%3$s"><div class="kk-notice__body"><p class="kk-notice__title">%4$s</p>%5$s</div></div>',
			esc_attr( $tone ),
			'' !== $legacy ? ' ' . esc_attr( $legacy ) : '',
			'danger' === $tone ? 'alert' : 'status',
			esc_html( $title ),
			'' !== $text ? '<p class="kk-notice__text">' . esc_html( $text ) . '</p>' : ''
		);
	}

	/**
	 * The consent text and Connect (R1).
	 *
	 * @param bool $configured Whether the service has an address.
	 * @return void
	 */
	private function render_consent( bool $configured ): void {
		echo '<p>' . esc_html__( 'Connect this shop to the suggestion service to get suggested related products, with a short reason, for your best sellers. The free plan includes 100 suggestions a month.', 'kiss-kiss-related-products' ) . '</p>';
		echo '<div class="vscm-consent kk-consent"><p class="kk-text-strong">' . esc_html(
			sprintf(
				/* translators: %s: version number of the consent text */
				__( 'What is sent (version %s)', 'kiss-kiss-related-products' ),
				Connection::CONSENT_VERSION
			)
		) . '</p><p class="kk-muted">' . esc_html__( 'For each product: its name, brand, categories, tags, attributes, price or price band, a description of up to 300 characters and its stock status. Nothing about your customers or orders. The service runs in the EU and keeps no product data.', 'kiss-kiss-related-products' ) . '</p><p class="kk-muted">';
		printf(
			/* translators: 1: Terms of Use link, 2: Privacy Policy link */
			esc_html__( 'By connecting you agree to the %1$s and the %2$s.', 'kiss-kiss-related-products' ),
			'<a href="' . esc_url( self::sales_url( self::TERMS_PATH ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Terms of Use', 'kiss-kiss-related-products' ) . '</a>',
			'<a href="' . esc_url( self::sales_url( self::PRIVACY_PATH ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy Policy', 'kiss-kiss-related-products' ) . '</a>'
		);
		echo '</p></div><div class="kk-actions">';
		$this->open_form( 'vscm-connect-form', self::CONNECT );
		$this->submit( __( 'Connect', 'kiss-kiss-related-products' ), '', ! $configured );
		echo '</form>';
		if ( ! $configured ) {
			echo '<span class="kk-note">' . esc_html__( 'The suggestion service is not available yet.', 'kiss-kiss-related-products' ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * The card: the badge, the host, the count over its bar, the notices, the facts and the actions (R6–R8).
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
		$left   = null !== $related ? $related['limit'] - $related['used'] : null;
		// Spent is the service's word: it gives resume_at only when nothing is left; a purchased plan that used its month
		// can still pick from its catalogue refresh allowance (C-86c4409 R6).
		$spent = null !== $related && null !== $related['resume_at'];
		$down  = null === $related && $this->status->unreachable();
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
			if ( 'free' !== $related['tier'] && $related['rebuild_limit'] > 0 ) {
				$rows[ __( 'Catalogue refresh', 'kiss-kiss-related-products' ) ] = sprintf(
					/* translators: 1: refresh suggestions used, 2: the refresh allowance, 3: the date the allowance ends */
					__( '%1$d of %2$d, until %3$s', 'kiss-kiss-related-products' ),
					$related['rebuild_used'],
					$related['rebuild_limit'],
					null !== $related['rebuild_ends_at'] ? $this->date( $related['rebuild_ends_at'] ) : ''
				);
			}
		}

		if ( null !== $reason || $spent ) {
			$this->card_open( __( 'PAUSED', 'kiss-kiss-related-products' ), 'warning' );
		} elseif ( $down ) {
			$this->card_open( __( 'NO ANSWER', 'kiss-kiss-related-products' ), 'danger' );
		} else {
			$this->card_open( __( 'CONNECTED', 'kiss-kiss-related-products' ), 'success' );
		}
		echo '<p class="kk-muted">' . esc_html(
			sprintf(
				/* translators: %s: the shop's host name */
				__( '%s is connected.', 'kiss-kiss-related-products' ),
				(string) wp_parse_url( home_url(), PHP_URL_HOST )
			)
		) . '</p>';

		if ( $down ) {
			$this->notice( 'danger', __( 'Suggestions couldn\'t update', 'kiss-kiss-related-products' ), __( 'We kept your current picks. Check your connection, then try again.', 'kiss-kiss-related-products' ), 'notice-error' );
		}
		if ( null !== $related && $related['limit'] > 0 ) {
			$pct = (int) round( 100 * min( $related['used'], $related['limit'] ) / $related['limit'] );
			// A purchased plan past its month picks from its catalogue refresh: say so, not only a full bar (C-0493ee8 R7).
			$refresh = $left <= 0 && ! $spent && $related['rebuild_limit'] > 0;
			echo '<p class="kk-text-strong">' . esc_html(
				$refresh
					? sprintf(
						/* translators: 1: refresh suggestions used, 2: the refresh allowance */
						__( 'This month\'s suggestions are used. New ones come from your catalogue refresh: %1$d of %2$d.', 'kiss-kiss-related-products' ),
						$related['rebuild_used'],
						$related['rebuild_limit']
					)
					: sprintf(
						/* translators: 1: suggestions used this month, 2: the month's allowance */
						__( '%1$d of %2$d suggestions used', 'kiss-kiss-related-products' ),
						$related['used'],
						$related['limit']
					)
			) . '</p>';
			printf(
				'<div class="kk-progress" role="progressbar" aria-label="%1$s" aria-valuemin="0" aria-valuemax="%2$d" aria-valuenow="%3$d"><span class="kk-progress__value" style="width:%4$d%%"></span></div>',
				esc_attr__( 'Suggestions used this month', 'kiss-kiss-related-products' ),
				(int) $related['limit'],
				(int) min( $related['used'], $related['limit'] ),
				(int) $pct
			);
			// The low notice from 20% left down to one (C-ce3b88a R11); none left is the paused state.
			if ( $left > 0 && $left * 5 <= $related['limit'] ) {
				$this->notice(
					'warning',
					__( 'Suggestions are running low', 'kiss-kiss-related-products' ),
					sprintf(
						/* translators: %d: suggestions left this month */
						_n( 'You have %d suggestion left. Your current picks stay in place.', 'You have %d suggestions left. Your current picks stay in place.', $left, 'kiss-kiss-related-products' ),
						(int) $left
					)
				);
			} elseif ( $spent && null === $reason ) {
				$this->notice( 'warning', __( 'This month\'s suggestions are used up. Your current picks stay in place.', 'kiss-kiss-related-products' ) );
			}
		}
		if ( null !== $reason ) {
			$this->notice( 'warning', $reason );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- progress_html() escapes every value it prints.
		echo $this->progress_html();

		echo '<table class="kk-facts" id="vscm-status" role="presentation">';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</table><div class="kk-actions">';
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
			// One primary action per state: during an outage it is Try again.
			echo '<a class="kk-button' . ( $down ? ' kk-button--secondary' : '' ) . '" id="vscm-buy" href="' . esc_url( $buy ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get more suggestions', 'kiss-kiss-related-products' ) . '</a>';
			if ( null !== $related && 'free' !== $related['tier'] ) {
				echo '<a class="kk-button kk-button--secondary" href="' . esc_url( self::sales_url( self::CABINET_PATH ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Your licence', 'kiss-kiss-related-products' ) . '</a>';
			}
		}
		$this->open_form( 'vscm-refresh-form', self::REFRESH );
		$this->submit( $down ? __( 'Try again', 'kiss-kiss-related-products' ) : __( 'Refresh', 'kiss-kiss-related-products' ), $down ? '' : 'secondary' );
		echo '</form>';
		if ( null !== $site ) {
			$this->open_form( 'vscm-disconnect-form', self::DISCONNECT );
			$this->submit( __( 'Disconnect site', 'kiss-kiss-related-products' ), 'secondary' );
			echo '</form>';
		}
		echo '</div></div>';
	}

	/**
	 * The progress block (C-0493ee8 R2): the same HTML on the page and from the progress route, so an update reads as
	 * the first paint. `working` carries the moving indicator; `paused` and `done` do not.
	 *
	 * @param array<string, mixed>|null $p A progress already read (the route's), so one answer reads it once (R4).
	 * @return string
	 */
	public function progress_html( ?array $p = null ): string {
		$p     = $p ?? $this->progress->read();
		$lines = array();
		if ( 'working' === $p['state'] ) {
			$lines[] = sprintf(
				/* translators: 1: products that have suggestions, 2: products waiting */
				__( 'Making suggestions: %1$s products have suggestions, %2$s waiting.', 'kiss-kiss-related-products' ),
				number_format_i18n( $p['done'] ),
				number_format_i18n( $p['waiting'] )
			);
		} elseif ( 'paused' === $p['state'] ) {
			$lines[] = sprintf(
				/* translators: %s: products waiting */
				_n( '%s product is waiting.', '%s products are waiting.', $p['waiting'], 'kiss-kiss-related-products' ),
				number_format_i18n( $p['waiting'] )
			);
		} elseif ( null !== $p['last_at'] ) {
			$lines[] = sprintf(
				/* translators: %s: date and time */
				__( 'Suggestions are up to date. The last one was made on %s.', 'kiss-kiss-related-products' ),
				$this->date( $p['last_at'] )
			);
		} else {
			$lines[] = __( 'No suggestions made yet.', 'kiss-kiss-related-products' );
		}
		if ( $p['failed'] > 0 ) {
			$lines[] = sprintf(
				/* translators: %s: products that could not get suggestions */
				_n( '%s product could not get suggestions.', '%s products could not get suggestions.', $p['failed'], 'kiss-kiss-related-products' ),
				number_format_i18n( $p['failed'] )
			);
		}

		$html = '<div class="kk-working" id="vscm-progress" data-state="' . esc_attr( $p['state'] ) . '" aria-live="polite">';
		if ( 'working' === $p['state'] ) {
			$html .= '<span class="kk-working__dot" aria-hidden="true"></span>';
		}
		$html .= '<div class="kk-working__text">';
		foreach ( $lines as $line ) {
			$html .= '<p>' . esc_html( $line ) . '</p>';
		}

		return $html . '</div></div>';
	}

	/**
	 * A submit button in the system's style.
	 *
	 * @param string $label    Its label, a verb.
	 * @param string $variant  '' (primary), secondary, ghost or danger.
	 * @param bool   $disabled Whether it is disabled.
	 * @return void
	 */
	private function submit( string $label, string $variant = '', bool $disabled = false ): void {
		printf(
			'<input type="submit" class="kk-button%1$s" value="%2$s"%3$s />',
			'' !== $variant ? ' kk-button--' . esc_attr( $variant ) : '',
			esc_attr( $label ),
			$disabled ? ' disabled="disabled"' : ''
		);
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
		$ok = ! empty( $notice['ok'] );
		$this->notice( $ok ? 'success' : 'danger', (string) $notice['text'], '', $ok ? 'notice-success' : 'notice-error' );
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
		echo '<form id="' . esc_attr( $id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
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
