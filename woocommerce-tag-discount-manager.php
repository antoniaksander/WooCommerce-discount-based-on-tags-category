<?php
/**
 * Plugin Name: WooCommerce Tag-Based Discount Manager
 * Plugin URI: https://github.com/antoniaksander/WooCommerce-discount-based-on-tags-category
 * Description: Apply and manage discounts based on product tags with preview, scheduling, and reversal options
 * Version: 1.2.0
 * Author: antoniaksander
 * Author URI: https://github.com/antoniaksander
 * Text Domain: wc-tag-discount
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 5.0
 * WC tested up to: 9.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_TAG_DISCOUNT_VERSION', '1.2.0' );
define( 'WC_TAG_DISCOUNT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_TAG_DISCOUNT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

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
			'rules_saved'        => __( 'Discount rules saved.', 'wc-tag-discount' ),
			'schedule_saved'     => __( 'Schedule saved.', 'wc-tag-discount' ),
			'auto_apply_paused'  => __( 'Auto-apply paused. It will resume on its own when the timer runs out.', 'wc-tag-discount' ),
			'auto_apply_resumed' => __( 'Auto-apply resumed.', 'wc-tag-discount' ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only selects which notice to render.
		$message_key = sanitize_key( wp_unslash( $_GET['message'] ) );

		if ( isset( $messages[ $message_key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Success!', 'wc-tag-discount' ) . '</strong> ' . esc_html( $messages[ $message_key ] ) . '</p></div>';
		}
	}
);
