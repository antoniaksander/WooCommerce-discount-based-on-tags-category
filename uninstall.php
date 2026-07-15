<?php
/**
 * Fires on plugin deletion (not on deactivation). Restores the pre-discount
 * sale price on any product we touched before removing our data, so
 * uninstalling doesn't leave products stuck at a discounted price.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( function_exists( 'wc_get_product' ) ) {
	$discounted_ids = get_posts(
		array(
			'post_type'      => array( 'product', 'product_variation' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_wc_tag_discount_rule',
		)
	);

	foreach ( $discounted_ids as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			continue;
		}

		$product->set_sale_price( $product->get_meta( '_wc_tag_discount_prev_sale_price', true ) );
		$product->save();
	}
}

delete_option( 'wc_tag_discount_rules' );
delete_option( 'wc_tag_discount_schedule' );
delete_option( 'wc_tag_discount_pause_until' );

// delete_post_meta_by_key() is a single bulk query, not a per-post loop.
delete_post_meta_by_key( '_wc_tag_discount_rule' );
delete_post_meta_by_key( '_wc_tag_discount_prev_sale_price' );

wp_clear_scheduled_hook( 'wc_tag_discount_apply_scheduled' );
wp_clear_scheduled_hook( 'wc_tag_discount_reverse_scheduled' );
