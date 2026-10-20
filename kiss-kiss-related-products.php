<?php
/**
 * Plugin Name: Kiss Kiss Related Products
 * Description: Keeps the related products on your product pages relevant, simply and without any external service.
 * Version: 0.1.0
 * Author: Slava Vasenin
 * Author URI: https://vasenin.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kiss-kiss-related-products
 * Domain Path: /languages/
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts;

defined( 'ABSPATH' ) || exit;

define( 'VSCM_WOO_VERSION', '0.1.0' );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} elseif ( ! class_exists( Container::class ) ) {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'Vscm\\WooRelatedProducts\\';
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}

			$relative_class = substr( $class_name, strlen( $prefix ) );
			$file           = __DIR__ . '/src/' . str_replace( '\\', '/', $relative_class ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

/**
 * Create plugin instance and build required services.
 *
 * @return Plugin
 */
function plugin(): Plugin {
	static $plugin;
	if ( ! $plugin ) {
		$container = new Container();
		$container->set( 'plugin_dir', __DIR__ );
		$container->set( 'plugin_url', rtrim( plugin_dir_url( __FILE__ ), '/' ) );
		$plugin = new Plugin( $container );
		// Defaults first: the plugin works with nothing picked, pinned or excluded.
		$container->set( Woo\RelatedPicks::class, new Woo\NullRelatedPicks() );
		$container->set( Woo\RelatedCuration::class, new Woo\NullRelatedCuration() );
		// Once connected, the stored service picks replace the defaults; an extension may still replace them.
		Suggestions\Services::defaults( $container );
		/**
		 * Lets an extension replace the defaults above, before anything is built from them.
		 *
		 * @param Container $container Dependencies container.
		 */
		do_action( 'vscm_related_products_defaults', $container );
		$plugin->add_service(
			Woo\CrossSellingRelatedProducts::class,
			new Woo\CrossSellingRelatedProducts( $container->get( Woo\RelatedPicks::class ), $container->get( Woo\RelatedCuration::class ) )
		);
		$plugin->add_service( Admin\Settings::class, new Admin\Settings() );
		$plugin->add_service( Rest\SettingsRoute::class, new Rest\SettingsRoute( $container->get( Admin\Settings::class ) ) );
		$plugin->add_service( Identity\Challenge::class, new Identity\Challenge() );
		$plugin->add_service( Rest\VerifyRoute::class, new Rest\VerifyRoute( $container->get( Identity\Challenge::class ) ) );
		Suggestions\Services::register( $plugin, $container );
		$plugin->add_service( Admin\ConnectionPanel::class, new Admin\ConnectionPanel( $container->get( Suggestions\Connection::class ), $container->get( Suggestions\SiteStatus::class ) ) );
		$plugin->add_service( Admin\SettingsPage::class, new Admin\SettingsPage( $container->get( Admin\Settings::class ), $container->get( Admin\ConnectionPanel::class ) ) );
		/**
		 * Lets an extension register its own services, once the plugin's are in place and before start.
		 *
		 * @param Plugin $plugin The plugin.
		 */
		do_action( 'vscm_related_products_services', $plugin );
	}

	return $plugin;
}

/**
 * Say so when WooCommerce is missing: "Requires Plugins" covers activation only, and without
 * WooCommerce the plugin would otherwise be silently useless.
 */
add_action(
	'admin_notices',
	static function (): void {
		if ( class_exists( 'WooCommerce' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Kiss Kiss Related Products requires WooCommerce. Activate WooCommerce, or this plugin will do nothing.', 'kiss-kiss-related-products' )
		);
	}
);

/**
 * Load plugin on hook.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		plugin()->start();
	}
);

/**
 * Create the suggestion tables on activation; later versions bring them up to date on `plugins_loaded`.
 */
register_activation_hook( __FILE__, array( Suggestions\Schema::class, 'install' ) );
