=== WooCommerce Tag-Based Discount Manager ===
Contributors: antoniaksander
Tags: woocommerce, discounts, sale, product tags, product categories
Requires at least: 5.0
Tested up to: 7.0.2
Requires PHP: 7.4
WC requires at least: 5.0
WC tested up to: 10.7
Stable tag: 2.0.2
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
* Optional price rounding for every discounted price (down, nearest or up, to endings like x.95, x.99, x.45/x.95 or x.00/x.50), so a 20% discount on 13.95 can show 10.95 instead of 11.16. Off by default
* See every product on sale that this plugin didn't discount -- a manual price, another plugin, or a leftover -- so it can actually be the source of truth for what's discounted on your store, not just for the discounts it applied itself

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

= Can discounted prices end in .95 instead of odd cents? =

Yes. On the Dashboard, under Price Rounding, pick a mode and a price ending. Round down keeps the discount at least the stated percentage; round up and nearest can make it slightly smaller, so check your local price-display rules before using them. A rounded price never reaches the regular price, and if rounding down would bring a very cheap item to zero, its exact price is kept. Saving the setting doesn't change live prices on its own: the preview shows the new prices, and they're written on the next "Apply Discounts Now" (or when a product is next saved).

== Changelog ==

= 2.0.2 =
* Fix: since 2.0.1, saving a product through the REST API (e.g. a tag or status change from an external tool or import) no longer applied or reversed its discount. A saved product is now queued and checked once the whole request has finished, so the result is right whichever way the product was saved: the edit screen, Quick/Bulk Edit, the REST API or an import.

= 2.0.1 =
* Fix: saving a product on its edit screen undid what the plugin had just applied or reversed. The plugin ran on save_post_product, before WooCommerce stored the form, so the form's old sale price was written back. It now runs after WooCommerce has saved the product (woocommerce_new_product / woocommerce_update_product), so edit-screen saves, Quick/Bulk Edit, the REST API and imports all apply or reverse correctly.
* Fix: saving a variable product that no longer matches any active rule now reverses the discount on its variations (the discount lives on the variations, not the parent).
* Fix: a product's own WooCommerce sale dates could hide a rule's discount. An end date left over from an earlier sale, or a start date in the future, made WooCommerce ignore the sale price. Applying a rule now clears those dates while its discount is on and puts them back on reverse.

= 2.0.0 =
* Add optional price rounding (Dashboard › Price Rounding): off, round down, nearest or up, to x.x0/x.x5, x.x0, x.00/x.25/x.50/x.75, x.00/x.50, x.45/x.95, x.95, x.99 or whole amounts. Off by default, so existing stores see no change. The Dashboard preview and the real apply share one calculation, and a rounded price never reaches the regular price.
* Fix: discounted prices were calculated with floating-point maths, so some came out one cent low (149.95 at 10% off gave 134.95 instead of 134.96; 224.95 gave 202.45 instead of 202.46). Prices are now calculated in whole cents with normal half-up rounding. Run "Apply Discounts Now" once to correct existing discounted prices.
* Fix: rules with a future "Apply at" date were treated as live by "Apply Discounts Now", by every product save and by the Dashboard preview, so a rule scheduled weeks ahead (e.g. Black Friday) went on sale the first time anyone pressed Apply. A scheduled rule now only starts at its apply date, through its own scheduled event. "Apply This Rule Now" is disabled on it, the Rules tab marks it as Scheduled, and "Apply Discounts Now" removes any discount a scheduled rule already left on products.

= 1.9.1 =
* Fix: coding-standards violations that had been failing CI on every push since 1.7.0 (multi-line PHP embedded inside HTML `onsubmit` attributes). No functional change -- the confirmation dialogs on Apply/Clear All buttons produce identical output; verified against real store data on the dev site.

= 1.9.0 =
* Add an "Untracked Discounts" tab: lists every product currently on sale that this plugin didn't discount -- a manually-set sale price, another plugin, WooCommerce's own native scheduled sale, or a leftover from before this plugin was installed. Clear one at a time or all at once; nothing here is touched automatically, since the plugin has no way to know whether an untracked price is a mistake or intentional. Built on WooCommerce's own cached on-sale index, so it stays cheap on a large catalog.

= 1.8.1 =
* Fix: 1.8.0 made Apply also reverse a rule's discount on products that no longer match it, but did so silently — the same "Apply Discounts Now" / "Apply This Rule Now" buttons you already knew, with no indication anything more than adding discounts was about to happen. If there's anything for a rule to reverse, applying it now shows the count up front and asks for confirmation before touching a live price, both in bulk and per-rule.

= 1.8.0 =
* Fix: "Apply" only ever added discounts, never removed one — if a product's tag/category/brand assignment changed since it was last discounted (bulk import, brand cleanup, etc.), it kept its stale sale price indefinitely even though it no longer matched its rule. Applying a rule (individually or in bulk) now also reverses that rule's discount on any product that no longer matches, so Apply is a real sync instead of purely additive. Also fixes the "Rule recreated" / "could not recreate" notices from 1.7.0's orphan review card, which weren't wired up to actually display.

= 1.7.0 =
* Fix: deleting a rule removed it from the Rules tab but left its discount live on every product still carrying it — with the rule gone, there was no way to find or reverse just those products short of reversing every currently-discounted product on the site.
* Add a safety net for exactly that: the Dashboard now lists any discount still live under a rule that's no longer in the Rules tab, with an explicit choice per one — reverse it, or recreate it as a proper rule (taxonomy, tag/category, and discount % reconstructed from the still-live price) so it stays managed. A sitewide admin notice links here from any wp-admin screen so it can't go unnoticed.

= 1.6.0 =
* Fix: a rule's "Reverse at" date was only ever consumed once, to schedule the one-time cron reversal — the rule itself kept matching forever afterward. Once that date passed, any new product (or an existing one, freshly tagged) would still silently pick up the "expired" discount on save, with no indication anything was wrong. Rules past their reverse date no longer match anywhere (auto-apply on save, bulk Apply, per-rule Apply, and the Dashboard preview); they stay fully visible and editable in the Rules tab, now with a clear "Expired" notice, so you can update the date or delete the rule.

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
