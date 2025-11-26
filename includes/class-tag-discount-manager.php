<?php

class WC_Tag_Discount_Manager {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_apply_tag_discounts', array($this, 'handle_apply_discounts'));
        add_action('admin_post_reverse_tag_discounts', array($this, 'handle_reverse_discounts'));
        add_action('admin_post_save_discount_rules', array($this, 'handle_save_rules'));
        add_action('admin_post_save_schedule', array($this, 'handle_save_schedule'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        
        // Auto-update when products are saved
        add_action('save_post_product', array($this, 'auto_update_product_discount'), 10, 1);
        
        // Scheduled actions
        add_action('wc_tag_discount_apply_scheduled', array($this, 'apply_discounts'));
        add_action('wc_tag_discount_reverse_scheduled', array($this, 'reverse_discounts'));
        
        // Check and setup scheduled events
        add_action('init', array($this, 'setup_scheduled_events'));
    }
    
    private function get_discount_rules() {
        $default_rules = array(
            'sale-10' => array('discount' => 10, 'taxonomy' => 'product_tag'),
            'sale-20' => array('discount' => 20, 'taxonomy' => 'product_tag'),
            'sale-30' => array('discount' => 30, 'taxonomy' => 'product_tag')
        );
        
        return get_option('wc_tag_discount_rules', $default_rules);
    }
    
    public function setup_scheduled_events() {
        $schedule = get_option('wc_tag_discount_schedule', array());
        
        // Clear existing scheduled events
        wp_clear_scheduled_hook('wc_tag_discount_apply_scheduled');
        wp_clear_scheduled_hook('wc_tag_discount_reverse_scheduled');
        
        // Schedule apply event
        if (!empty($schedule['apply_enabled']) && !empty($schedule['apply_datetime'])) {
            $timestamp = strtotime($schedule['apply_datetime']);
            if ($timestamp > time()) {
                wp_schedule_single_event($timestamp, 'wc_tag_discount_apply_scheduled');
            }
        }
        
        // Schedule reverse event
        if (!empty($schedule['reverse_enabled']) && !empty($schedule['reverse_datetime'])) {
            $timestamp = strtotime($schedule['reverse_datetime']);
            if ($timestamp > time()) {
                wp_schedule_single_event($timestamp, 'wc_tag_discount_reverse_scheduled');
            }
        }
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Tag Discount Manager',
            'Tag Discounts',
            'manage_woocommerce',
            'tag-discount-manager',
            array($this, 'render_admin_page')
        );
    }
    
    public function enqueue_admin_styles($hook) {
        if ($hook !== 'woocommerce_page_tag-discount-manager') {
            return;
        }
        
        wp_enqueue_style('wc-tag-discount-admin', plugin_dir_url(__FILE__) . '../assets/admin.css', array(), '2.3');
    }
    
    // ... [All the other methods from your original class remain the same]
    // Just remove the debug tab section that contained the specific URL
    // and update any remaining "the client" references to generic terms
    
    public function render_admin_page() {
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
        
        ?>
        <div class="wrap discount-manager-wrap">
            <h1>🏷️ Tag-Based Discount Manager</h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=tag-discount-manager&tab=dashboard" 
                   class="nav-tab <?php echo $active_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
                    Dashboard
                </a>
                <a href="?page=tag-discount-manager&tab=rules" 
                   class="nav-tab <?php echo $active_tab === 'rules' ? 'nav-tab-active' : ''; ?>">
                    Discount Rules
                </a>
                <a href="?page=tag-discount-manager&tab=schedule" 
                   class="nav-tab <?php echo $active_tab === 'schedule' ? 'nav-tab-active' : ''; ?>">
                    Schedule
                </a>
            </h2>
            
            <?php
            switch ($active_tab) {
                case 'rules':
                    $this->render_rules_tab();
                    break;
                case 'schedule':
                    $this->render_schedule_tab();
                    break;
                default:
                    $this->render_dashboard_tab();
            }
            ?>
        </div>
        <?php
    }
    
    // ... [Include all other methods from your original class]
    // Remove the render_debug_tab() method and any debug-specific functionality
    
}