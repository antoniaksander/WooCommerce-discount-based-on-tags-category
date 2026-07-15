<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Tag_Discount_Manager {

	const META_RULE      = '_wc_tag_discount_rule';
	const META_PREV_SALE = '_wc_tag_discount_prev_sale_price';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_post_apply_tag_discounts', array( $this, 'handle_apply_discounts' ) );
		add_action( 'admin_post_reverse_tag_discounts', array( $this, 'handle_reverse_discounts' ) );
		add_action( 'admin_post_save_discount_rules', array( $this, 'handle_save_rules' ) );
		add_action( 'admin_post_save_schedule', array( $this, 'handle_save_schedule' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );

		// Auto-update when products are saved
		add_action( 'save_post_product', array( $this, 'auto_update_product_discount' ), 10, 1 );

		// Scheduled actions
		add_action( 'wc_tag_discount_apply_scheduled', array( $this, 'apply_discounts' ) );
		add_action( 'wc_tag_discount_reverse_scheduled', array( $this, 'reverse_discounts' ) );

		// Check and setup scheduled events
		add_action( 'init', array( $this, 'setup_scheduled_events' ) );
	}

	private function get_discount_rules() {
		$default_rules = array(
			'product_tag_sale-10' => array(
				'slug'     => 'sale-10',
				'discount' => 10,
				'taxonomy' => 'product_tag',
			),
			'product_tag_sale-20' => array(
				'slug'     => 'sale-20',
				'discount' => 20,
				'taxonomy' => 'product_tag',
			),
			'product_tag_sale-30' => array(
				'slug'     => 'sale-30',
				'discount' => 30,
				'taxonomy' => 'product_tag',
			),
		);

		$rules = get_option( 'wc_tag_discount_rules', $default_rules );

		return is_array( $rules ) ? $rules : $default_rules;
	}

	private function discount_badge_class( $discount ) {
		$discount = (int) $discount;

		return in_array( $discount, array( 10, 20, 30 ), true ) ? 'tag-' . $discount : 'tag-custom';
	}

	/**
	 * The one place that resolves "the price a discount is calculated from". Used by
	 * both the Dashboard preview and the real apply logic so what a merchant previews
	 * is guaranteed to match what actually gets written, regardless of any other
	 * plugin filtering the display ('view' context) price.
	 */
	private function get_discountable_regular_price( $product ) {
		$regular_price = $product->get_regular_price( 'edit' );

		return ( '' !== $regular_price && is_numeric( $regular_price ) ) ? (float) $regular_price : null;
	}

	/**
	 * Resolve a <input type="datetime-local"> value (naive, no timezone) against the
	 * site's configured timezone rather than PHP's default one, since
	 * wp_schedule_single_event() requires a true UTC timestamp.
	 */
	private function schedule_datetime_to_timestamp( $datetime_string ) {
		if ( '' === $datetime_string ) {
			return false;
		}

		try {
			$date = new DateTime( $datetime_string, wp_timezone() );
		} catch ( Exception $e ) {
			return false;
		}

		return $date->getTimestamp();
	}

	public function setup_scheduled_events() {
		$schedule = get_option( 'wc_tag_discount_schedule', array() );

		// Clear existing scheduled events
		wp_clear_scheduled_hook( 'wc_tag_discount_apply_scheduled' );
		wp_clear_scheduled_hook( 'wc_tag_discount_reverse_scheduled' );

		// Schedule apply event
		if ( ! empty( $schedule['apply_enabled'] ) && ! empty( $schedule['apply_datetime'] ) ) {
			$timestamp = $this->schedule_datetime_to_timestamp( $schedule['apply_datetime'] );
			if ( $timestamp && $timestamp > time() ) {
				wp_schedule_single_event( $timestamp, 'wc_tag_discount_apply_scheduled' );
			}
		}

		// Schedule reverse event
		if ( ! empty( $schedule['reverse_enabled'] ) && ! empty( $schedule['reverse_datetime'] ) ) {
			$timestamp = $this->schedule_datetime_to_timestamp( $schedule['reverse_datetime'] );
			if ( $timestamp && $timestamp > time() ) {
				wp_schedule_single_event( $timestamp, 'wc_tag_discount_reverse_scheduled' );
			}
		}
	}

	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Tag Discount Manager', 'wc-tag-discount' ),
			__( 'Tag Discounts', 'wc-tag-discount' ),
			'manage_woocommerce',
			'tag-discount-manager',
			array( $this, 'render_admin_page' )
		);
	}

	public function enqueue_admin_styles( $hook ) {
		if ( 'woocommerce_page_tag-discount-manager' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wc-tag-discount-admin', WC_TAG_DISCOUNT_PLUGIN_URL . 'assets/admin.css', array(), WC_TAG_DISCOUNT_VERSION );
		wp_enqueue_script( 'wc-tag-discount-admin', WC_TAG_DISCOUNT_PLUGIN_URL . 'assets/admin.js', array(), WC_TAG_DISCOUNT_VERSION, true );
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-tag-discount' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only selects which tab to render.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

		?>
		<div class="wrap discount-manager-wrap">
			<h1>🏷️ <?php esc_html_e( 'Tag-Based Discount Manager', 'wc-tag-discount' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="?page=tag-discount-manager&tab=dashboard"
					class="nav-tab <?php echo 'dashboard' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Dashboard', 'wc-tag-discount' ); ?>
				</a>
				<a href="?page=tag-discount-manager&tab=rules"
					class="nav-tab <?php echo 'rules' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Discount Rules', 'wc-tag-discount' ); ?>
				</a>
				<a href="?page=tag-discount-manager&tab=schedule"
					class="nav-tab <?php echo 'schedule' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Schedule', 'wc-tag-discount' ); ?>
				</a>
			</h2>

			<?php
			switch ( $active_tab ) {
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

	private function render_dashboard_tab() {
		$rules          = $this->get_discount_rules();
		$discounted_ids = $this->get_products_with_active_discount();

		$preview = array();
		foreach ( $rules as $rule ) {
			foreach ( $this->get_products_for_rule( $rule ) as $product_id ) {
				$preview[ $product_id ] = $rule;
			}
		}
		?>
		<div class="discount-card">
			<h3><?php esc_html_e( 'Overview', 'wc-tag-discount' ); ?></h3>
			<div class="stats-grid">
				<div class="stat-box">
					<div class="stat-number"><?php echo count( $rules ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Active Rules', 'wc-tag-discount' ); ?></div>
				</div>
				<div class="stat-box">
					<div class="stat-number"><?php echo count( $preview ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Products Matching Rules', 'wc-tag-discount' ); ?></div>
				</div>
				<div class="stat-box">
					<div class="stat-number"><?php echo count( $discounted_ids ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Currently Discounted', 'wc-tag-discount' ); ?></div>
				</div>
			</div>

			<div class="button-group">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="apply_tag_discounts">
					<?php wp_nonce_field( 'wc_tag_discount_apply' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Discounts Now', 'wc-tag-discount' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="reverse_tag_discounts">
					<?php wp_nonce_field( 'wc_tag_discount_reverse' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Reverse Discounts', 'wc-tag-discount' ); ?></button>
				</form>
			</div>
		</div>

		<div class="discount-card">
			<h3><?php esc_html_e( 'Preview', 'wc-tag-discount' ); ?></h3>
			<?php if ( empty( $preview ) ) : ?>
				<p><?php esc_html_e( 'No products currently match a discount rule.', 'wc-tag-discount' ); ?></p>
			<?php else : ?>
				<div class="product-preview">
					<?php
					foreach ( $preview as $product_id => $rule ) :
						$product = wc_get_product( $product_id );
						if ( ! $product ) {
							continue;
						}
						$regular_price = $this->get_discountable_regular_price( $product );
						$new_price     = ( null !== $regular_price )
							? round( $regular_price * ( 1 - $rule['discount'] / 100 ), wc_get_price_decimals() )
							: null;
						?>
						<div class="product-item">
							<span>
								<?php echo esc_html( $product->get_name() ); ?>
								<?php if ( $product->is_type( 'variable' ) ) : ?>
									<span class="variation-info"><?php esc_html_e( '(variable product)', 'wc-tag-discount' ); ?></span>
								<?php endif; ?>
							</span>
							<span class="price-info">
								<?php if ( null !== $new_price ) : ?>
									<span class="old-price"><?php echo wp_kses_post( wc_price( $regular_price ) ); ?></span>
									<span class="new-price"><?php echo wp_kses_post( wc_price( $new_price ) ); ?></span>
								<?php endif; ?>
								<span class="tag-badge <?php echo esc_attr( $this->discount_badge_class( $rule['discount'] ) ); ?>"><?php echo esc_html( $rule['discount'] ); ?>%</span>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_rules_tab() {
		$rules = array_values( $this->get_discount_rules() );

		if ( empty( $rules ) ) {
			$rules = array(
				array(
					'taxonomy' => 'product_tag',
					'slug'     => '',
					'discount' => '',
				),
			);
		}
		?>
		<div class="discount-card">
			<h3><?php esc_html_e( 'Discount Rules', 'wc-tag-discount' ); ?></h3>
			<p><?php esc_html_e( 'Map a product tag or category to a discount percentage. When a product matches more than one rule, the last matching rule wins.', 'wc-tag-discount' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="save_discount_rules">
				<?php wp_nonce_field( 'wc_tag_discount_save_rules' ); ?>

				<table class="rules-table" id="wc-tag-discount-rules-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Taxonomy', 'wc-tag-discount' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'wc-tag-discount' ); ?></th>
							<th><?php esc_html_e( 'Discount %', 'wc-tag-discount' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rules as $rule ) : ?>
							<tr class="rule-row">
								<td>
									<select name="rule_taxonomy[]">
										<option value="product_tag" <?php selected( $rule['taxonomy'], 'product_tag' ); ?>><?php esc_html_e( 'Product Tag', 'wc-tag-discount' ); ?></option>
										<option value="product_cat" <?php selected( $rule['taxonomy'], 'product_cat' ); ?>><?php esc_html_e( 'Product Category', 'wc-tag-discount' ); ?></option>
									</select>
								</td>
								<td><input type="text" name="rule_slug[]" value="<?php echo esc_attr( $rule['slug'] ); ?>" placeholder="e.g. sale-20"></td>
								<td><input type="number" name="rule_discount[]" value="<?php echo esc_attr( $rule['discount'] ); ?>" min="0" max="100" step="0.01"></td>
								<td><button type="button" class="button remove-rule-row"><?php esc_html_e( 'Remove', 'wc-tag-discount' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<button type="button" class="button" id="add-rule-row"><?php esc_html_e( 'Add Rule', 'wc-tag-discount' ); ?></button>
				</p>

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Rules', 'wc-tag-discount' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	private function render_schedule_tab() {
		$schedule         = get_option( 'wc_tag_discount_schedule', array() );
		$apply_enabled    = ! empty( $schedule['apply_enabled'] );
		$apply_datetime   = isset( $schedule['apply_datetime'] ) ? $schedule['apply_datetime'] : '';
		$reverse_enabled  = ! empty( $schedule['reverse_enabled'] );
		$reverse_datetime = isset( $schedule['reverse_datetime'] ) ? $schedule['reverse_datetime'] : '';
		?>
		<div class="discount-card">
			<h3><?php esc_html_e( 'Schedule', 'wc-tag-discount' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="save_schedule">
				<?php wp_nonce_field( 'wc_tag_discount_save_schedule' ); ?>

				<div class="schedule-grid">
					<div class="schedule-box">
						<h4>
							<?php esc_html_e( 'Apply Discounts', 'wc-tag-discount' ); ?>
							<?php
							if ( $apply_enabled && $apply_datetime ) :
								?>
								<span class="scheduled-badge"><?php esc_html_e( 'Scheduled', 'wc-tag-discount' ); ?></span><?php endif; ?>
						</h4>
						<label>
							<input type="checkbox" name="apply_enabled" value="1" <?php checked( $apply_enabled ); ?>>
							<?php esc_html_e( 'Enable scheduled apply', 'wc-tag-discount' ); ?>
						</label>
						<p>
							<input type="datetime-local" name="apply_datetime" value="<?php echo esc_attr( $apply_datetime ); ?>">
						</p>
					</div>
					<div class="schedule-box">
						<h4>
							<?php esc_html_e( 'Reverse Discounts', 'wc-tag-discount' ); ?>
							<?php
							if ( $reverse_enabled && $reverse_datetime ) :
								?>
								<span class="scheduled-badge"><?php esc_html_e( 'Scheduled', 'wc-tag-discount' ); ?></span><?php endif; ?>
						</h4>
						<label>
							<input type="checkbox" name="reverse_enabled" value="1" <?php checked( $reverse_enabled ); ?>>
							<?php esc_html_e( 'Enable scheduled reverse', 'wc-tag-discount' ); ?>
						</label>
						<p>
							<input type="datetime-local" name="reverse_datetime" value="<?php echo esc_attr( $reverse_datetime ); ?>">
						</p>
					</div>
				</div>

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Schedule', 'wc-tag-discount' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	public function handle_apply_discounts() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-tag-discount' ) );
		}
		check_admin_referer( 'wc_tag_discount_apply' );

		$count = $this->apply_discounts();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'tag-discount-manager',
					'message' => 'applied',
					'count'   => $count,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_reverse_discounts() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-tag-discount' ) );
		}
		check_admin_referer( 'wc_tag_discount_reverse' );

		$count = $this->reverse_discounts();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'tag-discount-manager',
					'message' => 'reversed',
					'count'   => $count,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_save_rules() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-tag-discount' ) );
		}
		check_admin_referer( 'wc_tag_discount_save_rules' );

		$slugs      = isset( $_POST['rule_slug'] ) ? (array) wp_unslash( $_POST['rule_slug'] ) : array();
		$discounts  = isset( $_POST['rule_discount'] ) ? (array) wp_unslash( $_POST['rule_discount'] ) : array();
		$taxonomies = isset( $_POST['rule_taxonomy'] ) ? (array) wp_unslash( $_POST['rule_taxonomy'] ) : array();

		$allowed_taxonomies = array( 'product_tag', 'product_cat' );
		$rules              = array();

		foreach ( $slugs as $i => $raw_slug ) {
			$slug = sanitize_title( $raw_slug );
			if ( '' === $slug ) {
				continue;
			}

			$taxonomy = ( isset( $taxonomies[ $i ] ) && in_array( $taxonomies[ $i ], $allowed_taxonomies, true ) )
				? $taxonomies[ $i ]
				: 'product_tag';

			$discount = isset( $discounts[ $i ] ) ? (float) $discounts[ $i ] : 0;
			$discount = max( 0, min( 100, $discount ) );

			if ( $discount <= 0 ) {
				continue;
			}

			$rule_key           = $taxonomy . '_' . $slug;
			$rules[ $rule_key ] = array(
				'slug'     => $slug,
				'taxonomy' => $taxonomy,
				'discount' => $discount,
			);
		}

		// update_option overwrites the single existing row in place, it never grows the table.
		update_option( 'wc_tag_discount_rules', $rules );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'tag-discount-manager',
					'tab'     => 'rules',
					'message' => 'rules_saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_save_schedule() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-tag-discount' ) );
		}
		check_admin_referer( 'wc_tag_discount_save_schedule' );

		$schedule = array(
			'apply_enabled'    => ! empty( $_POST['apply_enabled'] ),
			'apply_datetime'   => isset( $_POST['apply_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['apply_datetime'] ) ) : '',
			'reverse_enabled'  => ! empty( $_POST['reverse_enabled'] ),
			'reverse_datetime' => isset( $_POST['reverse_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['reverse_datetime'] ) ) : '',
		);

		foreach ( array( 'apply_datetime', 'reverse_datetime' ) as $field ) {
			if ( '' !== $schedule[ $field ] && false === $this->schedule_datetime_to_timestamp( $schedule[ $field ] ) ) {
				$schedule[ $field ] = '';
			}
		}

		update_option( 'wc_tag_discount_schedule', $schedule );
		$this->setup_scheduled_events();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'tag-discount-manager',
					'tab'     => 'schedule',
					'message' => 'schedule_saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function apply_discounts() {
		$rules    = $this->get_discount_rules();
		$affected = array();

		foreach ( $rules as $rule_key => $rule ) {
			foreach ( $this->get_products_for_rule( $rule ) as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}

				if ( $product->is_type( 'variable' ) ) {
					foreach ( $product->get_children() as $variation_id ) {
						$variation = wc_get_product( $variation_id );
						if ( $variation && $this->apply_discount_to_product( $variation, $rule_key, $rule ) ) {
							$affected[ $variation_id ] = true;
						}
					}
				} elseif ( $this->apply_discount_to_product( $product, $rule_key, $rule ) ) {
					$affected[ $product_id ] = true;
				}
			}
		}

		return count( $affected );
	}

	public function reverse_discounts() {
		$count = 0;

		foreach ( $this->get_products_with_active_discount() as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product && $this->reverse_discount_on_product( $product ) ) {
				++$count;
			}
		}

		return $count;
	}

	public function auto_update_product_discount( $post_id ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		// Prevent product->save() below from re-triggering this same hook.
		remove_action( 'save_post_product', array( $this, 'auto_update_product_discount' ), 10 );

		$rules        = $this->get_discount_rules();
		$matched_rule = null;
		$matched_key  = null;

		// Iterate every rule (not break on first match) so a product matching several
		// rules ends up on the same one that bulk apply_discounts() would leave it on:
		// the last matching rule in rule order.
		foreach ( $rules as $rule_key => $rule ) {
			if ( has_term( $rule['slug'], $rule['taxonomy'], $post_id ) ) {
				$matched_rule = $rule;
				$matched_key  = $rule_key;
			}
		}

		if ( $matched_rule ) {
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( $variation ) {
						$this->apply_discount_to_product( $variation, $matched_key, $matched_rule );
					}
				}
			} else {
				$this->apply_discount_to_product( $product, $matched_key, $matched_rule );
			}
		} elseif ( '' !== $product->get_meta( self::META_RULE, true ) ) {
			$this->reverse_discount_on_product( $product );
		}

		add_action( 'save_post_product', array( $this, 'auto_update_product_discount' ), 10, 1 );
	}

	/**
	 * Discount a single simple product or variation. Only stores/overwrites the
	 * two meta keys below (no growth on repeat calls) and only ever touches
	 * the sale-price field, never the regular price.
	 */
	private function apply_discount_to_product( $product, $rule_key, $rule ) {
		$regular_price = $this->get_discountable_regular_price( $product );
		if ( null === $regular_price ) {
			return false;
		}

		// Capture the pre-discount sale price once, so a later re-apply doesn't overwrite it with an already-discounted value.
		if ( '' === $product->get_meta( self::META_RULE, true ) ) {
			$product->update_meta_data( self::META_PREV_SALE, $product->get_sale_price( 'edit' ) );
		}

		$discounted = round( $regular_price * ( 1 - ( $rule['discount'] / 100 ) ), wc_get_price_decimals() );

		$product->set_sale_price( $discounted );
		$product->update_meta_data( self::META_RULE, $rule_key );
		$product->save();

		return true;
	}

	private function reverse_discount_on_product( $product ) {
		$rule_key = $product->get_meta( self::META_RULE, true );
		if ( '' === $rule_key ) {
			return false;
		}

		$prev_sale_price = $product->get_meta( self::META_PREV_SALE, true );

		$product->set_sale_price( $prev_sale_price );
		$product->delete_meta_data( self::META_RULE );
		$product->delete_meta_data( self::META_PREV_SALE );
		$product->save();

		return true;
	}

	private function get_products_for_rule( $rule ) {
		return get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => $rule['taxonomy'],
						'field'    => 'slug',
						'terms'    => $rule['slug'],
					),
				),
			)
		);
	}

	private function get_products_with_active_discount() {
		return get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_RULE,
			)
		);
	}
}
