=== WooCommerce Tag-Based Discount Manager ===
Contributors: antoniaksander
Tags: woocommerce, discounts, sale, product tags, product categories
Requires at least: 5.0
Tested up to: 7.0.2
Requires PHP: 7.4
WC requires at least: 5.0
WC tested up to: 10.7
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Apply and manage discounts based on product tags or categories, with preview, scheduling, and one-click reversal.

== Description ==

WooCommerce Tag-Based Discount Manager lets you run sitewide sales by product tag or category instead of editing prices product by product.

* Map any product tag, category, brand, or attribute (whatever your store actually has taxonomies for) to a discount percentage, with a live search box that can create a brand new term on the spot
* Preview which products will be affected, and at what price, before applying anything — click straight through to a product's edit screen from the preview
* See at a glance how many products match each rule, and how many are currently discounted under it
* Apply or reverse a rule immediately, in bulk across every rule, or on its own schedule — each rule's apply/reverse time is independent, or leave it unscheduled and toggle it manually whenever you want
* Automatically re-evaluates a product's discount when you add, remove, or change its tags
* Works with simple products and each variation of a variable product
* Pause auto-apply before a large bulk import so it doesn't double the write cost per product; resumes on its own on a timer even if you forget

= Design notes =

The plugin does not keep its own price history table or log. It only ever writes to WooCommerce's own `_sale_price` field, plus two small meta keys used to track which rule applied and what the price was beforehand so it can be restored on reversal. Those two meta keys are removed again as soon as a discount is reversed, and everything is cleaned up on uninstall.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/woocommerce-tag-discount-manager`, or install the zip via Plugins → Add New → Upload Plugin.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Ensure WooCommerce is installed and activated.
4. Navigate to WooCommerce → Tag Discounts to configure.

== Frequently Asked Questions ==

= Does this touch my products' regular price? =

No. It only ever sets the sale price. Your regular price is never modified, so reversing a discount (or uninstalling the plugin) always restores exactly what was there before.

= What happens if a product already had a manual sale price before I apply a tag discount? =

That price is remembered and restored when you reverse the discount.

= What happens to my data if I uninstall the plugin? =

Uninstalling (not just deactivating) restores every discounted product's prior sale price, then removes the plugin's options and product meta.

= Will a bulk import slow down because of this plugin? =

It can, if many of the imported products carry a discounted tag or category — each match triggers a full product save on top of the import's own save. Before a large import, use "Pause Auto-Apply" on the Dashboard (WooCommerce → Tag Discounts). New products won't be auto-discounted while paused; run "Apply Discounts Now" once when the import finishes. The pause resumes on its own after the timer you chose, even if you forget to turn it back on, and a reminder banner appears throughout wp-admin the whole time it's active.

== Changelog ==

= 1.5.0 =
* Add support for discounting by any product taxonomy your store actually has, not just tags and categories — brands (if a brands plugin is active), attributes used as taxonomies, or any other custom taxonomy registered on products. The taxonomy list in the Rules tab is now built from what's really on the site, so this needs no configuration.

= 1.4.0 =
* Add one-click updates: WordPress's Plugins page now shows a normal "update available" notice and "Update Now" link for new releases, the same as any wp.org-hosted plugin, instead of requiring a manual download-and-re-upload. Checks the GitHub releases feed and always installs from the built release asset (never GitHub's auto-generated source zip, which uses the wrong folder name and would install as a duplicate plugin instead of updating in place).

= 1.3.0 =
* Rearchitect scheduling to be per-rule instead of one sitewide apply/reverse window. Each rule can be applied immediately, on its own optional schedule, or both; the separate Schedule tab is gone, folded into each rule. Still just the one options row for rule data, and still WP-Cron's existing single "cron" option for every scheduled event on the site — no new database rows or tables, no recurring cron jobs.
* Add a live search box for the rule's tag/category (WooCommerce's own verified term-search), replacing the plain text slug field. Typing a name that doesn't exist yet creates it.
* Show, per rule, how many products currently match it and how many are currently discounted under it.
* Preview list on the Dashboard now links each product straight to its edit screen.
* Fix: the Apply/Reverse Discounts buttons could wrap onto separate lines depending on viewport width.
* Fix: an older/pre-existing `wc_tag_discount_rules` option shape (missing the `slug` field, tag name only recoverable from the array key) would throw warnings and silently fail to match products. Now normalized on read, with a one-time self-healing rewrite; any legacy global schedule is migrated onto the matching rules automatically.
* Fix (found via testing against a real product catalog with variable products): saving a WooCommerce product variation makes WooCommerce internally re-save its parent to keep the parent's cached price range in sync, which fires the same save hook our own auto-detect listens on. During a bulk apply or reverse this could cause our own hook to silently re-apply a discount moments after a reverse just cleared it. Bulk operations now unhook auto-detect for their duration.

= 1.2.0 =
* Add a pausable auto-apply, for bulk imports: pausing stops the per-product auto-discount hook from doing extra work on every save, self-expires on a timer (1-24 hours) so it can't be left off by accident, and shows a sitewide admin reminder with a one-click resume while active.

= 1.1.1 =
* Fix: a product matching multiple discount rules could end up at a different discount percentage depending on whether it was bulk-applied or auto-updated on save. Both now consistently use the last matching rule.
* Fix: scheduled apply/reverse times were resolved against PHP's default server timezone instead of the site's configured timezone, which could fire a sale hours early or late. Now resolved via `wp_timezone()`.
* Fix: the Dashboard preview computed its "before/after" price differently from the real apply logic, so the numbers shown could disagree with what was actually applied if another plugin filtered displayed prices. Both now share one price-resolution method.

= 1.1.0 =
* Add uninstall.php to restore pre-discount prices and clean up all stored data
* Add GPLv2 license headers and license file
* Add CI (lint + coding standards) and automated release packaging

= 1.0.0 =
* Initial working release: discount rules, scheduling, dashboard preview, apply/reverse, auto-update on tag change
