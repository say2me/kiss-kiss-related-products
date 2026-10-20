<?php
/**
 * Database schema for the related-products suggestions.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Suggestions;

use Vscm\WooRelatedProducts\InitableInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the suggestions's tables.
 */
class Schema implements InitableInterface {

	/**
	 * Current schema version. Bump when install() changes.
	 */
	public const DB_VERSION = '1.1.0';

	/**
	 * Option name storing the installed schema version.
	 */
	private const VERSION_OPTION = 'vscm_related_service_db_version';

	/**
	 * Init.
	 */
	public function init() {
		// The base plugin starts its services inside plugins_loaded, so a callback added to that hook now
		// would never run, and an updated plugin would keep its old tables (found by C-055). Update at
		// once in that case.
		if ( did_action( 'plugins_loaded' ) ) {
			$this->maybe_upgrade();
			return;
		}
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );
	}

	/**
	 * Run install() when the stored version differs.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create or update the tables with dbDelta and record the schema version.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$links_table = $wpdb->prefix . 'vscm_related_links';
		$queue_table = $wpdb->prefix . 'vscm_related_queue';

		$sql_links = "CREATE TABLE {$links_table} (
			product_id BIGINT UNSIGNED NOT NULL,
			related_id BIGINT UNSIGNED NOT NULL,
			position SMALLINT UNSIGNED NOT NULL,
			reason VARCHAR(191) NOT NULL,
			model VARCHAR(100) NOT NULL,
			generated_at DATETIME NOT NULL,
			PRIMARY KEY  (product_id, related_id),
			KEY related_id (related_id)
		) {$charset_collate};";

		$sql_queue = "CREATE TABLE {$queue_table} (
			product_id BIGINT UNSIGNED NOT NULL,
			cause VARCHAR(32) NOT NULL,
			queued_at DATETIME NOT NULL,
			available_at DATETIME NOT NULL,
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			PRIMARY KEY  (product_id)
		) {$charset_collate};";

		// Products of deleted brands and categories, waiting to be queued 500 per worker run (C-055 R5).
		$backlog_table = $wpdb->prefix . 'vscm_related_backlog';
		$sql_backlog   = "CREATE TABLE {$backlog_table} (
			product_id BIGINT UNSIGNED NOT NULL,
			added_at DATETIME NOT NULL,
			PRIMARY KEY  (product_id)
		) {$charset_collate};";

		dbDelta( $sql_links );
		dbDelta( $sql_queue );
		dbDelta( $sql_backlog );

		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}
}
