<?php
/**
 * Plugin Name: WooCommerce Tag-Based Discount Manager
 * Plugin URI: https://github.com/antoniaksander/WooCommerce-discount-based-on-tags-category
 * Description: Apply and manage discounts based on product tags or categories, with preview, per-rule scheduling, and reversal
 * Version: 1.8.1
 * Author: antoniaksander
 * Author URI: https://github.com/antoniaksander
 * Text Domain: wc-tag-discount
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 5.0
 * WC tested up to: 10.7
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_TAG_DISCOUNT_VERSION', '1.8.1' );
define( 'WC_TAG_DISCOUNT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_TAG_DISCOUNT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Lets WordPress offer a normal "Update Now" link in wp-admin for new GitHub
 * releases, instead of manually downloading and re-uploading a zip. Runs
 * independent of the WooCommerce-active check below so updates stay checkable
 * even if WooCommerce is briefly deactivated. Requires the release asset
 * (built via `git archive` with the correct folder name) rather than GitHub's
 * auto-generated source zip, which uses the repo name as the folder and would
 * install as a second, duplicate plugin instead of updating this one in place.
 */
if ( is_admin() ) {
	require_once WC_TAG_DISCOUNT_PLUGIN_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

	YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
		'https://github.com/antoniaksander/WooCommerce-discount-based-on-tags-category/',
		__FILE__,
		'woocommerce-tag-discount-manager'
	)->getVcsApi()->enableReleaseAssets(
		'/^woocommerce-tag-discount-manager-.*\.zip$/',
		YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS
	);
}

/**
 * Check if WooCommerce is active, including network-activated on multisite.
 */
function wc_tag_discount_is_woocommerce_active() {
	$active_plugins = (array) get_option( 'active_plugins', array() );

	if ( is_multisite() ) {
		$active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	return in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', $active_plugins ), true );
}

if ( ! wc_tag_discount_is_woocommerce_active() ) {
	return;
}

// Include the main class
if ( ! class_exists( 'WC_Tag_Discount_Manager' ) ) {
	require_once WC_TAG_DISCOUNT_PLUGIN_DIR . 'includes/class-wc-tag-discount-manager.php';
}

/**
 * Initialize the plugin
 */
function wc_tag_discount_manager_init() {
	load_plugin_textdomain( 'wc-tag-discount', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	new WC_Tag_Discount_Manager();
}
add_action( 'plugins_loaded', 'wc_tag_discount_manager_init' );

/**
 * Display admin notices
 */
add_action(
	'admin_notices',
	function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only selects which notice to render.
		if ( ! isset( $_GET['page'] ) || ! isset( $_GET['message'] ) || 'tag-discount-manager' !== $_GET['page'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only selects which notice to render.
		$count = isset( $_GET['count'] ) ? intval( $_GET['count'] ) : 0;

		$messages = array(
			// translators: %d: number of products a discount was applied to.
			'applied'            => sprintf( _n( 'Discount applied to %d product.', 'Discounts applied to %d products.', $count, 'wc-tag-discount' ), $count ),
			// translators: %d: number of products a discount was removed from.
			'reversed'           => sprintf( _n( 'Discount removed from %d product.', 'Discounts removed from %d products.', $count, 'wc-tag-discount' ), $count ),
			'rules_saved'        => __( 'Rule saved.', 'wc-tag-discount' ),
			'rule_deleted'       => __( 'Rule deleted.', 'wc-tag-discount' ),
			'auto_apply_paused'  => __( 'Auto-apply paused. It will resume on its own when the timer runs out.', 'wc-tag-discount' ),
			'auto_apply_resumed' => __( 'Auto-apply resumed.', 'wc-tag-discount' ),
			'orphan_recreated'   => __( 'Rule recreated. It\'s now listed and editable on the Discount Rules tab.', 'wc-tag-discount' ),
		);

		// Separate map: these render as a warning, not a success, and skip the
		// "Success!" prefix -- not applying anything isn't a success to report.
		$warning_messages = array(
			'rule_expired'           => __( 'This rule is expired (its reverse date has passed), so it was not applied. Update the reverse date and save the rule to reactivate it.', 'wc-tag-discount' ),
			'orphan_recreate_failed' => __( 'Could not recreate this rule automatically -- its taxonomy or discount % could not be determined. Add it manually on the Discount Rules tab instead.', 'wc-tag-discount' ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only selects which notice to render.
		$message_key = sanitize_key( wp_unslash( $_GET['message'] ) );

		if ( isset( $messages[ $message_key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Success!', 'wc-tag-discount' ) . '</strong> ' . esc_html( $messages[ $message_key ] ) . '</p></div>';
		} elseif ( isset( $warning_messages[ $message_key ] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $warning_messages[ $message_key ] ) . '</p></div>';
		}
	}
);
