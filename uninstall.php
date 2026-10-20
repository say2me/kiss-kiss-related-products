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

// Delete the connect challenge.
delete_option( 'vscm_aiai_challenge' );

// Drop the suggestion tables.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time bulk cleanup on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}vscm_related_links" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time bulk cleanup on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}vscm_related_queue" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time bulk cleanup on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}vscm_related_backlog" );

// Delete the suggestion post meta.
delete_post_meta_by_key( '_vscm_service_dna' );
delete_post_meta_by_key( '_vscm_service_state' );

// Delete the suggestion options.
delete_option( 'vscm_related_service_db_version' );
delete_option( 'vscm_related_service_calls' );
delete_option( 'vscm_related_service_paused' );
delete_option( 'vscm_related_service_job' );
delete_option( 'vscm_related_service_last_run' );
// The connected site and its pause (C-063); the cached status and the connect notice are transients, below.
delete_option( 'vscm_related_service_site' );

// Unschedule the drain action.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'vscm_related_service_drain', array(), 'vscm-related-service' );
}

// Delete all transients related to this plugin.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time bulk cleanup on uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_vscm_related_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_vscm_related_' ) . '%'
	)
);
