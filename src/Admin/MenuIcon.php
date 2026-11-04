<?php
/**
 * The admin menu icon: the Kiss Kiss mark in one colour (C-ce3b88a R14).
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The mark's two circles (r 30 at 48,64 and 80,64 on its 128 box, as the design system's kisskiss-mark-bare.svg)
 * in one path. The even-odd rule cuts the lens where they overlap, where the colour mark draws it in ink. One fill,
 * so WordPress's svg-painter can tint it with the admin colour scheme as it does the dashicons.
 */
final class MenuIcon {

	/**
	 * The SVG.
	 */
	private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">'
		. '<path fill="black" fill-rule="evenodd" d="M18 64a30 30 0 1 0 60 0a30 30 0 1 0-60 0zM50 64a30 30 0 1 0 60 0a30 30 0 1 0-60 0z"/>'
		. '</svg>';

	/**
	 * The icon as add_menu_page() takes it.
	 *
	 * @return string
	 */
	public static function data_uri(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the data URI WordPress asks for.
		return 'data:image/svg+xml;base64,' . base64_encode( self::SVG );
	}
}
