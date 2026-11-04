=== Kiss Kiss Related Products ===
Contributors: slavavasenin
Tags: related products, cross-selling, brands, categories, woocommerce related products
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps the related products on your product pages relevant, simply. An optional suggestion service is used only once you connect.

== Description ==

Kiss Kiss Related Products replaces the related products on your product pages with picks that make sense to a shopper: other products of the same brand, and products from sibling categories.

It works with WooCommerce and requires it: the plugin does nothing on a site where WooCommerce is not installed and active.

* Same-brand products first, then sibling categories.
* Products hidden from the catalogue are never offered, and out-of-stock ones follow your store's own stock visibility setting.
* Results are cached, so product pages stay fast on small hosts.
* One settings screen, under Related Products in the admin menu: switch the feature on or off and add your own CSS class to the related products block.
* No account and no tracking. Out of the box the plugin makes no outbound requests.
* Optional: connect the shop to the suggestion service for suggested related products, with a short reason, for your best sellers. The free plan includes 100 suggestions a month. Nothing is sent before you click Connect; see External services below.

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

Not unless you connect the shop to the suggestion service on the settings screen. Until then it makes no outbound requests. What is sent once connected is listed under External services.

= What happens on a staging copy of my shop? =

A copy at another address, or of another environment type, does not use the shop's suggestion allowance: it sends nothing and keeps showing the suggestions it already has.

= What happens when I delete the plugin? =

Its settings, cached picks, stored suggestions and the shop's connection are removed from the database.

== Screenshots ==

1. A product page: the related products the plugin picked, the same brand first, then sibling categories.
2. The plugin's settings in WooCommerce.

== External services ==

This plugin can connect to the suggestion service at api.vasenin.com, which suggests related products for your products. It is off until a shop manager clicks Connect on the plugin's settings screen and agrees to the terms shown there.

* When you click Connect: the shop's address, the version of the consent text, and the plugin's name and version. The service then fetches a one-time code from the shop's own address to confirm it.
* For each product it suggests for, in the background: its name, brand, categories, tags, attributes, price or price band, a description of up to 300 characters and its stock status, and the same for the candidate products. Nothing about your customers or orders.
* On the settings screen, at most once an hour: the shop's plan and how many suggestions it used this month. At most once a day the plugin also sends the number of published products, from which the service sizes the shop's monthly allowance.

Every request carries the shop's own key and address. Disconnect on the settings screen stops all requests. The service keeps no product data after answering.

Terms of Use: https://vasenin.com/terms/
Privacy Policy: https://vasenin.com/privacy/

== Credits ==

The settings screen uses the fonts Onest, Unbounded and JetBrains Mono, shipped with the plugin in assets/fonts/ under the SIL Open Font License, Version 1.1 (assets/fonts/OFL.txt). The plugin loads no font from another site.

== Changelog ==

= 1.0.1 =
* Connect on the settings screen now reaches the suggestion service, so a connected shop gets suggested related products with a short reason, 100 a month on the free plan.

= 1.0.0 =
* A new look for the settings screen, light and dark, with its fonts shipped in the plugin.
* The suggestions card shows the connection, this month's allowance and any error, and, while suggestions are being made, how many products are done and how many are waiting.
* Refresh makes the waiting products' suggestions there and then.
* Suggestions resume as soon as the allowance has room again.
* Once the shop is connected, the plugin sends the number of its published products at most once a day; see External services.

= 0.1.0 =
* First release.
