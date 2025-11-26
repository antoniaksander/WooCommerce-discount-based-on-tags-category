<?php
/**
 * Plugin Name: WooCommerce Tag-Based Discount Manager
 * Description: Apply and manage discounts based on product tags with preview, scheduling, and reversal options
 * Version: 2.3
 * Author: Your Name
 * Text Domain: wc-tag-discount
 * Requires Plugins: woocommerce
 * WC requires at least: 5.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Include the main class
if (!class_exists('WC_Tag_Discount_Manager')) {
    require_once plugin_dir_path(__FILE__) . 'includes/class-tag-discount-manager.php';
}

/**
 * Initialize the plugin
 */
function wc_tag_discount_manager_init() {
    new WC_Tag_Discount_Manager();
}
add_action('plugins_loaded', 'wc_tag_discount_manager_init');

/**
 * Display admin notices
 */
add_action('admin_notices', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'tag-discount-manager' && isset($_GET['message'])) {
        $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
        
        $messages = [
            'applied' => '<p><strong>Success!</strong> Discounts applied to ' . $count . ' products.</p>',
            'reversed' => '<p><strong>Success!</strong> Discounts removed from ' . $count . ' products.</p>',
            'rules_saved' => '<p><strong>Success!</strong> Discount rules saved.</p>',
            'schedule_saved' => '<p><strong>Success!</strong> Schedule saved.</p>'
        ];
        
        if (isset($messages[$_GET['message']])) {
            echo '<div class="notice notice-success is-dismissible">';
            echo $messages[$_GET['message']];
            echo '</div>';
        }
    }
});