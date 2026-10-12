<?php
/**
 * The settings screen: the top-level "Related Products" menu and the form for the plugin's own fields.
 *
 * @package Vscm\WooRelatedProducts
 */

declare(strict_types = 1);

namespace Vscm\WooRelatedProducts\Admin;

use Vscm\WooRelatedProducts\InitableInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level menu and renders a plain server-side form for the fields the plugin
 * owns. Extensions hang their own screens under the same menu.
 */
class SettingsPage implements InitableInterface {

	/**
	 * Menu and page slug.
	 */
	public const SLUG = 'kiss-kiss-related-products';

	/**
	 * Save action name (admin-post and nonce).
	 */
	private const ACTION = 'vscm_related_products_save_settings';

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Build.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Init.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
	}

	/**
	 * Add the top-level menu entry.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Related Products', 'kiss-kiss-related-products' ),
			__( 'Related Products', 'kiss-kiss-related-products' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-screenoptions'
		);
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$values = $this->settings->get();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only notice flag.
		$saved = isset( $_GET['settings-updated'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Related Products', 'kiss-kiss-related-products' ); ?></h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'kiss-kiss-related-products' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vscm-enableFeature"><?php esc_html_e( 'Enable', 'kiss-kiss-related-products' ); ?></label></th>
						<td>
							<input type="checkbox" id="vscm-enableFeature" name="enableFeature" value="1" <?php checked( ! empty( $values['enableFeature'] ) ); ?> />
							<span class="description"><?php esc_html_e( 'Replace the related products on product pages with the brand and sibling-category picks.', 'kiss-kiss-related-products' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vscm-customCssClass"><?php esc_html_e( 'Custom CSS class', 'kiss-kiss-related-products' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="vscm-customCssClass" name="customCssClass" value="<?php echo esc_attr( (string) $values['customCssClass'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Space-separated classes added to the related products block.', 'kiss-kiss-related-products' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the form post: save, then go back to the screen.
	 *
	 * @return void
	 */
	public function save(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'kiss-kiss-related-products' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$css = isset( $_POST['customCssClass'] ) ? sanitize_text_field( wp_unslash( $_POST['customCssClass'] ) ) : '';

		$this->settings->update(
			array(
				'customCssClass' => $css,
				'enableFeature'  => isset( $_POST['enableFeature'] ),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect( add_query_arg( 'settings-updated', '1', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}
}
