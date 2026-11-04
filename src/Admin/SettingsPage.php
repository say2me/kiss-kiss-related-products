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
 * Registers the top-level menu and renders the server-side form for the fields the plugin owns, as the Kiss Kiss
 * cookbook's "Plugin settings" (C-ce3b88a R6). Extensions hang their own screens under the same menu.
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
	 * What a refused save typed, kept for the one render after its redirect (per user).
	 */
	private const TYPED = 'vscm_related_settings_typed_';

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The "Suggestions" section (C-063), or null when the suggestion core is not built.
	 *
	 * @var ConnectionPanel|null
	 */
	private ?ConnectionPanel $panel;

	/**
	 * The screen's hook suffix, once the menu is added.
	 *
	 * @var string
	 */
	private string $screen = '';

	/**
	 * Build.
	 *
	 * @param Settings             $settings Settings repository.
	 * @param ConnectionPanel|null $panel    The "Suggestions" section, if built.
	 */
	public function __construct( Settings $settings, ?ConnectionPanel $panel = null ) {
		$this->settings = $settings;
		$this->panel    = $panel;
	}

	/**
	 * Init.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the top-level menu entry.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$this->screen = (string) add_menu_page(
			__( 'Related Products', 'kiss-kiss-related-products' ),
			__( 'Related Products', 'kiss-kiss-related-products' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' ),
			MenuIcon::data_uri()
		);
	}

	/**
	 * Load the Kiss Kiss styles on this screen only (C-ce3b88a R4).
	 *
	 * @param string $hook_suffix The current admin screen's hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' !== $this->screen && $hook_suffix === $this->screen ) {
			wp_enqueue_style( Assets::HANDLE );
			wp_enqueue_script( Assets::PROGRESS );
		}
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

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flags of the save's redirect.
		$outcome = isset( $_GET['settings-updated'] ) ? sanitize_key( wp_unslash( $_GET['settings-updated'] ) ) : '';
		$invalid = isset( $_GET['settings-error'] ) && 'relatedcount' === sanitize_key( wp_unslash( $_GET['settings-error'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$values = $this->settings->get();
		$typed  = array();
		if ( $invalid ) {
			$typed = get_transient( self::TYPED . get_current_user_id() );
			$typed = is_array( $typed ) ? $typed : array();
			delete_transient( self::TYPED . get_current_user_id() );
		}
		$value = static function ( string $key ) use ( $values, $typed ) {
			return array_key_exists( $key, $typed ) ? $typed[ $key ] : $values[ $key ];
		};
		?>
		<div class="wrap">
			<hr class="wp-header-end" />
			<div class="kk">
				<div class="kk-page">
					<div class="kk-heading">
						<h1 class="kk-title"><?php esc_html_e( 'Related products', 'kiss-kiss-related-products' ); ?></h1>
						<span class="kk-badge kk-badge--kiss"><?php esc_html_e( 'FREE', 'kiss-kiss-related-products' ); ?></span>
					</div>
					<p class="kk-intro"><?php esc_html_e( 'Show products that belong together. Start with the same brand, then fill from sibling categories.', 'kiss-kiss-related-products' ); ?></p>
					<?php $this->outcome_notice( $outcome, $invalid ); ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="kk-card" novalidate>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
						<?php wp_nonce_field( self::ACTION ); ?>
						<?php
						$this->switch_row(
							'enableFeature',
							__( 'Show Kiss Kiss related products', 'kiss-kiss-related-products' ),
							__( 'Same brand first, then sibling categories. When off, WooCommerce shows its own related products.', 'kiss-kiss-related-products' ),
							! empty( $value( 'enableFeature' ) )
						);
						?>
						<div class="kk-row">
							<div>
								<h3 class="kk-row__title"><?php esc_html_e( 'Number of products', 'kiss-kiss-related-products' ); ?></h3>
								<p class="kk-row__help"><?php esc_html_e( 'Keep the row short enough to scan.', 'kiss-kiss-related-products' ); ?></p>
							</div>
							<div class="kk-field">
								<label class="kk-field__label" for="vscm-relatedCount"><?php esc_html_e( 'Products to show', 'kiss-kiss-related-products' ); ?></label>
								<input class="kk-input" type="number" id="vscm-relatedCount" name="relatedCount" min="1" max="12" step="1" inputmode="numeric" value="<?php echo esc_attr( (string) $value( 'relatedCount' ) ); ?>" aria-describedby="vscm-relatedCount-hint"<?php echo $invalid ? ' aria-invalid="true"' : ''; ?> />
								<p class="kk-field__hint<?php echo $invalid ? ' kk-field__hint--error' : ''; ?>" id="vscm-relatedCount-hint"><?php esc_html_e( 'Choose from 1 to 12 products.', 'kiss-kiss-related-products' ); ?></p>
							</div>
						</div>
						<?php
						$this->switch_row(
							'serviceEnabled',
							__( 'Use suggestions', 'kiss-kiss-related-products' ),
							__( 'Once connected, show the suggestion service\'s picks for the products it has seen. Off shows the brand and category picks only.', 'kiss-kiss-related-products' ),
							! empty( $value( 'serviceEnabled' ) )
						);
						?>
						<div class="kk-row">
							<div>
								<h3 class="kk-row__title"><?php esc_html_e( 'Custom CSS class', 'kiss-kiss-related-products' ); ?></h3>
								<p class="kk-row__help"><?php esc_html_e( 'Space-separated classes added to the related products block.', 'kiss-kiss-related-products' ); ?></p>
							</div>
							<div class="kk-field">
								<label class="kk-field__label" for="vscm-customCssClass"><?php esc_html_e( 'Classes', 'kiss-kiss-related-products' ); ?></label>
								<input class="kk-input" type="text" id="vscm-customCssClass" name="customCssClass" value="<?php echo esc_attr( (string) $value( 'customCssClass' ) ); ?>" />
							</div>
						</div>
						<div class="kk-actions">
							<button type="submit" class="kk-button"><?php esc_html_e( 'Save changes', 'kiss-kiss-related-products' ); ?></button>
						</div>
					</form>
				</div>
				<?php
				// Inside the system's wrapper, beside the settings: outside it the card falls back to WordPress's own look.
				if ( null !== $this->panel ) {
					$this->panel->render();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * One setting with a switch: the title and help on the left, the switch with its label on the right.
	 *
	 * @param string $key     The field.
	 * @param string $title   Its title, also the switch's label.
	 * @param string $help    The help under the title.
	 * @param bool   $checked Whether it is on.
	 * @return void
	 */
	private function switch_row( string $key, string $title, string $help, bool $checked ): void {
		$id = 'vscm-' . $key;
		?>
		<div class="kk-row">
			<div>
				<h3 class="kk-row__title"><?php echo esc_html( $title ); ?></h3>
				<p class="kk-row__help" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $help ); ?></p>
			</div>
			<label class="kk-switch" for="<?php echo esc_attr( $id ); ?>">
				<input class="kk-switch__input" type="checkbox" role="switch" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" value="1" aria-describedby="<?php echo esc_attr( $id ); ?>-help" <?php checked( $checked ); ?> />
				<span class="kk-switch__label"><?php echo esc_html( $title ); ?></span>
			</label>
		</div>
		<?php
	}

	/**
	 * The notice that names the save's outcome (C-ce3b88a R9).
	 *
	 * @param string $outcome The redirect's settings-updated flag.
	 * @param bool   $invalid Whether the save was refused for an invalid field.
	 * @return void
	 */
	private function outcome_notice( string $outcome, bool $invalid ): void {
		if ( $invalid ) {
			$this->notice( 'danger', __( 'Your settings weren\'t saved. Try again.', 'kiss-kiss-related-products' ), __( 'Choose from 1 to 12 products.', 'kiss-kiss-related-products' ) );
		} elseif ( '1' === $outcome ) {
			$this->notice( 'success', __( 'Settings saved.', 'kiss-kiss-related-products' ) );
		} elseif ( 'unchanged' === $outcome ) {
			$this->notice( 'info', __( 'No changes to save.', 'kiss-kiss-related-products' ) );
		} elseif ( 'failed' === $outcome ) {
			$this->notice( 'danger', __( 'Your settings weren\'t saved. Try again.', 'kiss-kiss-related-products' ) );
		}
	}

	/**
	 * A Notice: a status for success and information, an alert for a failure.
	 *
	 * @param string $tone  info, success, warning or danger.
	 * @param string $title The notice's title.
	 * @param string $text  Its text, if any.
	 * @return void
	 */
	private function notice( string $tone, string $title, string $text = '' ): void {
		$role = 'danger' === $tone ? 'alert' : 'status';
		?>
		<div class="kk-notice kk-notice--<?php echo esc_attr( $tone ); ?>" role="<?php echo esc_attr( $role ); ?>">
			<div class="kk-notice__body">
				<p class="kk-notice__title"><?php echo esc_html( $title ); ?></p>
				<?php if ( '' !== $text ) : ?>
					<p class="kk-notice__text"><?php echo esc_html( $text ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle the form post: save, then go back to the screen with what happened.
	 *
	 * @return void
	 */
	public function save(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'kiss-kiss-related-products' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$input = array(
			'customCssClass' => isset( $_POST['customCssClass'] ) ? sanitize_text_field( wp_unslash( $_POST['customCssClass'] ) ) : '',
			'enableFeature'  => isset( $_POST['enableFeature'] ),
			'serviceEnabled' => isset( $_POST['serviceEnabled'] ),
		);
		if ( isset( $_POST['relatedCount'] ) && '' !== $_POST['relatedCount'] ) {
			$input['relatedCount'] = sanitize_text_field( wp_unslash( $_POST['relatedCount'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$back = admin_url( 'admin.php?page=' . self::SLUG );
		if ( array() !== $this->settings->invalid( $input ) ) {
			// Nothing is saved; the screen shows what was typed, with the field marked invalid.
			set_transient( self::TYPED . get_current_user_id(), $input, 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'settings-error', 'relatedCount', $back ) );
			exit;
		}

		$before = $this->settings->get();
		$wanted = array_merge( $before, $this->settings->sanitize( $input ) );
		$after  = $this->settings->update( $input );

		if ( $before === $wanted ) {
			$outcome = 'unchanged';
		} elseif ( $after === $wanted ) {
			$outcome = '1';
		} else {
			$outcome = 'failed';
		}

		wp_safe_redirect( add_query_arg( 'settings-updated', $outcome, $back ) );
		exit;
	}
}
