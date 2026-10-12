<?php
/**
 * Uninstall routine for the Kiss Kiss Related Products plugin.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Delete the settings option.
delete_option( 'vscm_woo_related_products_settings' );

// Delete all transients related to this plugin.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time bulk cleanup on uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_vscm_related_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_vscm_related_' ) . '%'
	)
);
