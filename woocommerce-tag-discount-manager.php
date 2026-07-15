<?php
/**
 * Plugin Name: WooCommerce Tag-Based Discount Manager
 * Description: Apply and manage discounts based on product tags with preview, scheduling, and reversal options
 * Version: 1.0.0
 * Author: antoniaksander
 * Text Domain: wc-tag-discount
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 5.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

define('WC_TAG_DISCOUNT_VERSION', '1.0.0');
define('WC_TAG_DISCOUNT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_TAG_DISCOUNT_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Check if WooCommerce is active, including network-activated on multisite.
 */
function wc_tag_discount_is_woocommerce_active() {
    $active_plugins = (array) get_option('active_plugins', array());

    if (is_multisite()) {
        $active_plugins = array_merge($active_plugins, array_keys((array) get_site_option('active_sitewide_plugins', array())));
    }

    return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', $active_plugins), true);
}

if (!wc_tag_discount_is_woocommerce_active()) {
    return;
}

// Include the main class
if (!class_exists('WC_Tag_Discount_Manager')) {
    require_once WC_TAG_DISCOUNT_PLUGIN_DIR . 'includes/class-tag-discount-manager.php';
}

/**
 * Initialize the plugin
 */
function wc_tag_discount_manager_init() {
    load_plugin_textdomain('wc-tag-discount', false, dirname(plugin_basename(__FILE__)) . '/languages');
    new WC_Tag_Discount_Manager();
}
add_action('plugins_loaded', 'wc_tag_discount_manager_init');

/**
 * Display admin notices
 */
add_action('admin_notices', function() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'tag-discount-manager' || !isset($_GET['message'])) {
        return;
    }

    $count = isset($_GET['count']) ? intval($_GET['count']) : 0;

    $messages = array(
        'applied'        => sprintf(_n('Discount applied to %d product.', 'Discounts applied to %d products.', $count, 'wc-tag-discount'), $count),
        'reversed'       => sprintf(_n('Discount removed from %d product.', 'Discounts removed from %d products.', $count, 'wc-tag-discount'), $count),
        'rules_saved'    => __('Discount rules saved.', 'wc-tag-discount'),
        'schedule_saved' => __('Schedule saved.', 'wc-tag-discount'),
    );

    $message_key = sanitize_key(wp_unslash($_GET['message']));

    if (isset($messages[$message_key])) {
        echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__('Success!', 'wc-tag-discount') . '</strong> ' . esc_html($messages[$message_key]) . '</p></div>';
    }
});
