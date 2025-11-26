# WooCommerce Tag-Based Discount Manager

A WordPress plugin for WooCommerce that allows you to apply and manage discounts based on product tags with preview, scheduling, and reversal options.

## Features

- Apply discounts based on product tags or categories
- Preview discount impact before applying
- Schedule automatic discount application and reversal
- Support for simple, variable products and variations
- Real-time updates when products are modified
- Bulk operations with detailed reporting

## Installation

1. Upload the plugin files to `/wp-content/plugins/woocommerce-tag-discount-manager`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Ensure WooCommerce is installed and activated
4. Navigate to WooCommerce → Tag Discounts to configure

## Usage

### Setting Up Discount Rules

1. Go to WooCommerce → Tag Discounts → Discount Rules
2. Add rules in the format: `tag-slug` → `discount-percentage`
3. Example: `sale-20` → `20` (20% discount for products with tag "sale-20")

### Applying Discounts

1. View the Dashboard tab to see affected products
2. Click "Apply Discounts Now" to activate discounts
3. Use the Schedule tab to set up automatic timing

## Requirements

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+

## License

GPL v2 or later