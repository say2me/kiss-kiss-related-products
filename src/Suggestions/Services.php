<?php
/**
 * The suggestion core's wiring: what the plugin builds, and what it starts only once the site is connected.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

use Vscm\WooRelatedProducts\Container;
use Vscm\WooRelatedProducts\Plugin;
use Vscm\WooRelatedProducts\Woo\CrossSellingRelatedProducts;
use Vscm\WooRelatedProducts\Woo\RelatedCuration;
use Vscm\WooRelatedProducts\Woo\RelatedPicks;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the suggestion services. Until the site is connected (C-063: consented, holding a key, and not a copy)
 * nothing here hooks into WordPress or calls the suggestion service: the objects exist for the merchant tools to
 * read, but no worker or change tracking runs. Stored picks are served wherever there is a service, a copy and a
 * disconnected shop too (R3, R5). Only the tables and their upgrades are kept up otherwise, which needs no outbound request.
 */
final class Services {

	/**
	 * Whether the site may call the suggestion service now (C-063 R4, R5).
	 *
	 * @return bool
	 */
	public static function connected(): bool {
		return ( new Connection() )->connected();
	}

	/**
	 * Replace the default picks with the stored service picks wherever there is a service: reading them sends
	 * nothing, and a copy or a disconnected shop keeps what it has (C-063 R3, R5).
	 *
	 * @param Container $container Dependencies container.
	 * @return void
	 */
	public static function defaults( Container $container ): void {
		if ( ( new ServiceClient() )->is_configured() ) {
			$container->replace( RelatedPicks::class, new ServiceRelatedPicks( new State(), new Links(), new ServiceClient() ) );
		}
	}

	/**
	 * Register the suggestion services: started when connected, only built otherwise.
	 *
	 * @param Plugin    $plugin    The plugin.
	 * @param Container $container Dependencies container.
	 * @return void
	 */
	public static function register( Plugin $plugin, Container $container ): void {
		$plugin->add_service( Schema::class, new Schema() );
		$container->set( Connection::class, new Connection() );
		$container->set( SiteStatus::class, new SiteStatus( new ServiceClient(), $container->get( Connection::class ) ) );

		$connected = self::connected();
		// A copy or a disconnected shop calls nothing but still shows its stored picks and their reasons (R3, R5).
		$serves = ( new ServiceClient() )->is_configured();
		$put    = static function ( string $id, callable $factory, ?bool $start = null ) use ( $plugin, $container, $connected ): void {
			if ( $start ?? $connected ) {
				$plugin->add_factory( $id, $factory );
			} else {
				$container->factory( $id, $factory );
			}
		};

		$put(
			LastRun::class,
			static function () {
				return new LastRun();
			}
		);
		$put(
			ChangeTracker::class,
			static function () {
				return new ChangeTracker();
			}
		);
		$put(
			Curator::class,
			static function ( Container $c ) {
				return new Curator(
					$c->get( CrossSellingRelatedProducts::class ),
					new Dna(),
					new Links(),
					new State(),
					new Calls(),
					new ServiceClient(),
					$c->get( RelatedCuration::class )
				);
			}
		);
		$put(
			Worker::class,
			static function ( Container $c ) {
				return new Worker( new Queue(), $c->get( Curator::class ), new State(), $c->get( LastRun::class ), $c->get( Connection::class ) );
			}
		);
		$put(
			Job::class,
			static function () {
				return new Job( new Queue() );
			}
		);
		$put(
			Frontend::class,
			static function ( Container $c ) {
				return new Frontend( $c->get( CrossSellingRelatedProducts::class ), new Links() );
			},
			$serves
		);
	}
}
