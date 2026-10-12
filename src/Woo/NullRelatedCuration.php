<?php
/**
 * Related curation with nothing pinned or excluded.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Pins and excludes nothing.
 */
class NullRelatedCuration implements RelatedCuration {

	/**
	 * Nothing pinned.
	 *
	 * @param int $id Product ID.
	 * @return int[]
	 */
	public function pinned( int $id ): array {
		return array();
	}

	/**
	 * Nothing excluded.
	 *
	 * @param int $id Product ID.
	 * @return int[]
	 */
	public function excluded( int $id ): array {
		return array();
	}
}
