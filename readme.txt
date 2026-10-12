=== Kiss Kiss Related Products ===
Contributors: slavavasenin
Tags: related products, cross-selling, brands, categories, woocommerce related products
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps the related products on your product pages relevant, simply and without any external service.

== Description ==

Kiss Kiss Related Products replaces the related products on your product pages with picks that make sense to a shopper: other products of the same brand, and products from sibling categories.

It works with WooCommerce and requires it: the plugin does nothing on a site where WooCommerce is not installed and active.

* Same-brand products first, then sibling categories.
* Products hidden from the catalogue are never offered, and out-of-stock ones follow your store's own stock visibility setting.
* Results are cached, so product pages stay fast on small hosts.
* One settings screen, under Related Products in the admin menu: switch the feature on or off and add your own CSS class to the related products block.
* No external service, no account and no tracking. The plugin makes no outbound requests.

== Installation ==

1. Install and activate WooCommerce first; this plugin requires it.
2. Upload the `kiss-kiss-related-products` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
3. Activate the plugin through the Plugins screen.
4. Open Related Products in the admin menu to review the settings. The plugin is on by default.

== Frequently Asked Questions ==

= Where are the settings? =

Under Related Products in the admin menu.

= How do I turn it off? =

Untick Enable on the settings screen. Your product pages go back to the store's own related products. Deactivating the plugin does the same.

= Does it send any data anywhere? =

No. It makes no outbound requests and sends no data to any external service.

= What happens when I delete the plugin? =

Its settings and cached picks are removed from the database.

== Changelog ==

= 0.1.0 =
* First release.
