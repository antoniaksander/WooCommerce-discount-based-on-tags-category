<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Tag_Discount_Manager {

	const META_RULE       = '_wc_tag_discount_rule';
	const META_PREV_SALE  = '_wc_tag_discount_prev_sale_price';
	const OPT_RULES       = 'wc_tag_discount_rules';
	const OPT_PAUSE_UNTIL = 'wc_tag_discount_pause_until';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_post_apply_tag_discounts', array( $this, 'handle_apply_discounts' ) );
		add_action( 'admin_post_reverse_tag_discounts', array( $this, 'handle_reverse_discounts' ) );
		add_action( 'admin_post_save_tag_discount_rule', array( $this, 'handle_save_rule' ) );
		add_action( 'admin_post_delete_tag_discount_rule', array( $this, 'handle_delete_rule' ) );
		add_action( 'admin_post_apply_tag_discount_rule', array( $this, 'handle_apply_rule' ) );
		add_action( 'admin_post_reverse_tag_discount_rule', array( $this, 'handle_reverse_rule' ) );
		add_action( 'admin_post_pause_tag_discount_auto_apply', array( $this, 'handle_pause_auto_apply' ) );
		add_action( 'admin_post_resume_tag_discount_auto_apply', array( $this, 'handle_resume_auto_apply' ) );
		add_action( 'admin_post_reverse_orphaned_tag_discount_rule', array( $this, 'handle_reverse_orphaned_rule' ) );
		add_action( 'admin_post_recreate_orphaned_tag_discount_rule', array( $this, 'handle_recreate_orphaned_rule' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_notices', array( $this, 'render_pause_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_orphaned_rules_notice' ) );

		// Auto-update when products are saved
		add_action( 'save_post_product', array( $this, 'auto_update_product_discount' ), 10, 1 );

		// Per-rule scheduled actions, fired with the rule key as the single argument.
		add_action( 'wc_tag_discount_apply_rule_scheduled', array( $this, 'handle_apply_rule_scheduled' ), 10, 1 );
		add_action( 'wc_tag_discount_reverse_rule_scheduled', array( $this, 'handle_reverse_rule_scheduled' ), 10, 1 );

		// Check and setup scheduled events
		add_action( 'init', array( $this, 'setup_scheduled_events' ) );
	}

	/**
	 * Loads and normalizes stored rules. Defensive against older/malformed shapes
	 * (e.g. entries missing 'slug', where the array key itself was the slug) so a
	 * pre-existing option value from an earlier version of this plugin doesn't throw
	 * warnings or silently stop matching. Self-heals once: normalized data (and any
	 * one-time migration from the old global schedule option) is written back only
	 * when something actually needed fixing, not on every read.
	 */
	private function get_discount_rules() {
		$default_rules = array(
			'product_tag_sale-10' => array(
				'slug'             => 'sale-10',
				'taxonomy'         => 'product_tag',
				'discount'         => 10,
				'apply_datetime'   => '',
				'reverse_datetime' => '',
			),
			'product_tag_sale-20' => array(
				'slug'             => 'sale-20',
				'taxonomy'         => 'product_tag',
				'discount'         => 20,
				'apply_datetime'   => '',
				'reverse_datetime' => '',
			),
			'product_tag_sale-30' => array(
				'slug'             => 'sale-30',
				'taxonomy'         => 'product_tag',
				'discount'         => 30,
				'apply_datetime'   => '',
				'reverse_datetime' => '',
			),
		);

		$raw = get_option( self::OPT_RULES, null );

		if ( null === $raw || ! is_array( $raw ) ) {
			return $default_rules;
		}

		$legacy_schedule = get_option( 'wc_tag_discount_schedule', array() );
		$normalized      = array();
		$needs_rewrite   = false;

		foreach ( $raw as $key => $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['discount'] ) || ! is_numeric( $rule['discount'] ) ) {
				$needs_rewrite = true;
				continue;
			}

			if ( isset( $rule['slug'] ) && '' !== $rule['slug'] ) {
				$slug = (string) $rule['slug'];
			} else {
				// Older schema used the array key itself as the slug.
				$slug          = (string) $key;
				$needs_rewrite = true;
			}

			$taxonomy = isset( $rule['taxonomy'] ) ? $rule['taxonomy'] : 'product_tag';

			$apply_datetime   = isset( $rule['apply_datetime'] ) ? $rule['apply_datetime'] : '';
			$reverse_datetime = isset( $rule['reverse_datetime'] ) ? $rule['reverse_datetime'] : '';

			// One-time migration: carry the old global schedule onto rules that don't have their own.
			if ( '' === $apply_datetime && ! empty( $legacy_schedule['apply_enabled'] ) && ! empty( $legacy_schedule['apply_datetime'] ) ) {
				$apply_datetime = $legacy_schedule['apply_datetime'];
				$needs_rewrite  = true;
			}
			if ( '' === $reverse_datetime && ! empty( $legacy_schedule['reverse_enabled'] ) && ! empty( $legacy_schedule['reverse_datetime'] ) ) {
				$reverse_datetime = $legacy_schedule['reverse_datetime'];
				$needs_rewrite    = true;
			}

			$normalized[ $taxonomy . '_' . $slug ] = array(
				'slug'             => $slug,
				'taxonomy'         => $taxonomy,
				'discount'         => (float) $rule['discount'],
				'apply_datetime'   => $apply_datetime,
				'reverse_datetime' => $reverse_datetime,
			);
		}

		if ( $needs_rewrite ) {
			update_option( self::OPT_RULES, $normalized );
			if ( ! empty( $legacy_schedule ) ) {
				delete_option( 'wc_tag_discount_schedule' );
			}
		}

		return $normalized;
	}

	/**
	 * A rule with a reverse_datetime in the past has already had its window --
	 * without this check it kept matching forever, since reverse_datetime was
	 * only ever consumed once, to schedule the one-time cron reversal. Any
	 * later save of a new or edited product carrying that tag/category would
	 * silently pick the (supposedly expired) discount back up. Expired rules
	 * stay fully visible/editable in the Rules tab; this only gates whether a
	 * rule is used for matching (auto-apply-on-save, bulk apply, per-rule
	 * apply, and the Dashboard preview).
	 */
	private function is_rule_expired( $rule ) {
		if ( '' === $rule['reverse_datetime'] ) {
			return false;
		}

		$timestamp = $this->schedule_datetime_to_timestamp( $rule['reverse_datetime'] );

		return false !== $timestamp && $timestamp <= time();
	}

	/**
	 * get_discount_rules() filtered down to rules that can still actually
	 * apply to a product. Use this (not get_discount_rules() directly) for
	 * anything that matches/applies a rule; use get_discount_rules() only for
	 * rule management (the Rules tab needs to show and let you edit/delete
	 * expired rules too).
	 */
	private function get_active_discount_rules() {
		return array_filter(
			$this->get_discount_rules(),
			function ( $rule ) {
				return ! $this->is_rule_expired( $rule );
			}
		);
	}

	/**
	 * Whether auto-apply-on-save is currently paused (e.g. during a bulk import).
	 * Correctness never depends on WP-Cron firing: this is a plain wall-clock
	 * comparison against a stored "resume at" timestamp, so a pause always lifts
	 * itself on time even if nothing ever visits the site to trigger cron. Returns
	 * the resume timestamp while paused, or false once it's expired (also lazily
	 * deleting the now-stale option so it doesn't just sit there forever).
	 */
	private function is_auto_apply_paused() {
		$paused_until = (int) get_option( self::OPT_PAUSE_UNTIL, 0 );

		if ( $paused_until > time() ) {
			return $paused_until;
		}

		if ( $paused_until ) {
			delete_option( self::OPT_PAUSE_UNTIL );
		}

		return false;
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

	/**
	 * (Re)schedules every rule's optional apply/reverse timer. Still just the one
	 * options row for rule data, and WP-Cron itself keeps every scheduled event
	 * (from every plugin on the site) in a single "cron" option regardless of how
	 * many rules have a timer set -- adding per-rule schedules doesn't create new
	 * rows or tables. Every event here is one-time (wp_schedule_single_event), never
	 * a recurring job, and disappears the moment it fires.
	 */
	public function setup_scheduled_events() {
		wp_clear_scheduled_hook( 'wc_tag_discount_apply_rule_scheduled' );
		wp_clear_scheduled_hook( 'wc_tag_discount_reverse_rule_scheduled' );

		foreach ( $this->get_discount_rules() as $rule_key => $rule ) {
			if ( '' !== $rule['apply_datetime'] ) {
				$timestamp = $this->schedule_datetime_to_timestamp( $rule['apply_datetime'] );
				if ( $timestamp && $timestamp > time() ) {
					wp_schedule_single_event( $timestamp, 'wc_tag_discount_apply_rule_scheduled', array( $rule_key ) );
				}
			}

			if ( '' !== $rule['reverse_datetime'] ) {
				$timestamp = $this->schedule_datetime_to_timestamp( $rule['reverse_datetime'] );
				if ( $timestamp && $timestamp > time() ) {
					wp_schedule_single_event( $timestamp, 'wc_tag_discount_reverse_rule_scheduled', array( $rule_key ) );
				}
			}
		}
	}

	public function handle_apply_rule_scheduled( $rule_key ) {
		$rules = $this->get_discount_rules();
		if ( isset( $rules[ $rule_key ] ) ) {
			$this->apply_rule( $rule_key, $rules[ $rule_key ] );
		}
	}

	public function handle_reverse_rule_scheduled( $rule_key ) {
		$this->reverse_rule( $rule_key );
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

		// Reuse WooCommerce's own verified taxonomy-term search (wc_ajax_json_search_taxonomy_terms)
		// instead of building a parallel AJAX endpoint.
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_script( 'wc-tag-discount-admin', WC_TAG_DISCOUNT_PLUGIN_URL . 'assets/admin.js', array( 'jquery', 'wc-enhanced-select' ), WC_TAG_DISCOUNT_VERSION, true );
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
			</h2>

			<?php
			if ( 'rules' === $active_tab ) {
				$this->render_rules_tab();
			} else {
				$this->render_dashboard_tab();
			}
			?>
		</div>
		<?php
	}

	private function render_dashboard_tab() {
		$rules          = $this->get_active_discount_rules();
		$discounted_ids = $this->get_products_with_active_discount();

		$preview     = array();
		$stale_total = 0;
		foreach ( $rules as $rule_key => $rule ) {
			foreach ( $this->get_products_for_rule( $rule ) as $product_id ) {
				$preview[ $product_id ] = $rule;
			}
			$stale_total += $this->count_stale_for_rule( $rule_key, $rule );
		}
		?>
		<?php $this->render_orphaned_rules_card(); ?>

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
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					<?php if ( $stale_total > 0 ) : ?>
						onsubmit="return confirm('<?php echo esc_js( sprintf(
							/* translators: %d: number of products whose discount would be reversed. */
							_n( 'This will also reverse the discount on %d product that no longer matches its rule. Continue?', 'This will also reverse the discount on %d products that no longer match their rule. Continue?', $stale_total, 'wc-tag-discount' ),
							$stale_total
						) ); ?>');"
					<?php endif; ?>
				>
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

		<?php $this->render_bulk_operations_card(); ?>

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
								<a href="<?php echo esc_url( get_edit_post_link( $product_id ) ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo esc_html( $product->get_name() ); ?>
								</a>
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

	private function render_bulk_operations_card() {
		$paused_until = $this->is_auto_apply_paused();
		?>
		<div class="discount-card">
			<h3><?php esc_html_e( 'Bulk Operations', 'wc-tag-discount' ); ?></h3>
			<?php if ( $paused_until ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: human-readable time remaining, e.g. "3 hours". */
						esc_html__( 'Auto-apply is paused for %s. New or edited products will not get their tag/category discount applied automatically until then.', 'wc-tag-discount' ),
						esc_html( human_time_diff( time(), $paused_until ) )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="resume_tag_discount_auto_apply">
					<?php wp_nonce_field( 'wc_tag_discount_resume' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Resume Auto-Apply Now', 'wc-tag-discount' ); ?></button>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Before a large bulk import, pause auto-apply so each newly added product doesn\'t trigger an extra save. It resumes on its own when the timer runs out, even if you forget — just run "Apply Discounts Now" above once your import finishes.', 'wc-tag-discount' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pause_tag_discount_auto_apply">
					<?php wp_nonce_field( 'wc_tag_discount_pause' ); ?>
					<label for="pause_hours"><?php esc_html_e( 'Pause for:', 'wc-tag-discount' ); ?></label>
					<select name="pause_hours" id="pause_hours">
						<option value="1"><?php esc_html_e( '1 hour', 'wc-tag-discount' ); ?></option>
						<option value="2"><?php esc_html_e( '2 hours', 'wc-tag-discount' ); ?></option>
						<option value="4" selected><?php esc_html_e( '4 hours', 'wc-tag-discount' ); ?></option>
						<option value="8"><?php esc_html_e( '8 hours', 'wc-tag-discount' ); ?></option>
						<option value="24"><?php esc_html_e( '24 hours', 'wc-tag-discount' ); ?></option>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Pause Auto-Apply', 'wc-tag-discount' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_rules_tab() {
		$rules = $this->get_discount_rules();
		?>
		<div class="discount-card">
			<h3><?php esc_html_e( 'Discount Rules', 'wc-tag-discount' ); ?></h3>
			<p><?php esc_html_e( 'Each rule can be applied right now, on its own schedule, or both — there is no single sitewide sale window anymore. When a product matches more than one rule, the last matching rule wins.', 'wc-tag-discount' ); ?></p>

			<?php if ( empty( $rules ) ) : ?>
				<p><?php esc_html_e( 'No rules yet — add one below.', 'wc-tag-discount' ); ?></p>
			<?php else : ?>
				<?php foreach ( $rules as $rule_key => $rule ) : ?>
					<?php $this->render_rule_row( $rule_key, $rule ); ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<div class="discount-card">
			<h3><?php esc_html_e( 'Add a Rule', 'wc-tag-discount' ); ?></h3>
			<?php $this->render_rule_row( '', null ); ?>
		</div>
		<?php
	}

	private function render_rule_row( $rule_key, $rule ) {
		$is_new           = ( null === $rule );
		$taxonomy         = $is_new ? 'product_tag' : $rule['taxonomy'];
		$slug             = $is_new ? '' : $rule['slug'];
		$discount         = $is_new ? '' : $rule['discount'];
		$apply_datetime   = $is_new ? '' : $rule['apply_datetime'];
		$reverse_datetime = $is_new ? '' : $rule['reverse_datetime'];
		$is_expired       = ! $is_new && $this->is_rule_expired( $rule );
		?>
		<div class="rule-row-card<?php echo $is_expired ? ' rule-row-card--expired' : ''; ?>">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rule-form">
				<input type="hidden" name="action" value="save_tag_discount_rule">
				<input type="hidden" name="rule_key" value="<?php echo esc_attr( $rule_key ); ?>">
				<?php wp_nonce_field( 'wc_tag_discount_save_rule' ); ?>

				<div class="rule-row-grid">
					<div class="rule-field">
						<label><?php esc_html_e( 'Taxonomy', 'wc-tag-discount' ); ?></label>
						<select name="taxonomy" class="rule-taxonomy-select">
							<?php foreach ( $this->get_selectable_taxonomies() as $tax_slug => $tax_label ) : ?>
								<option value="<?php echo esc_attr( $tax_slug ); ?>" <?php selected( $taxonomy, $tax_slug ); ?>><?php echo esc_html( $tax_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="rule-field">
						<label><?php esc_html_e( 'Value', 'wc-tag-discount' ); ?></label>
						<select name="slug" class="wc-tag-discount-term-search" data-placeholder="<?php esc_attr_e( 'Search or type to create new…', 'wc-tag-discount' ); ?>" style="width:100%">
							<?php if ( '' !== $slug ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" selected><?php echo esc_html( $this->get_term_label( $taxonomy, $slug ) ); ?></option>
							<?php endif; ?>
						</select>
					</div>
					<div class="rule-field">
						<label><?php esc_html_e( 'Discount %', 'wc-tag-discount' ); ?></label>
						<input type="number" name="discount" value="<?php echo esc_attr( $discount ); ?>" min="0.01" max="100" step="0.01">
					</div>
					<div class="rule-field">
						<label><?php esc_html_e( 'Apply at (optional)', 'wc-tag-discount' ); ?></label>
						<input type="datetime-local" name="apply_datetime" value="<?php echo esc_attr( $apply_datetime ); ?>">
					</div>
					<div class="rule-field">
						<label><?php esc_html_e( 'Reverse at (optional)', 'wc-tag-discount' ); ?></label>
						<input type="datetime-local" name="reverse_datetime" value="<?php echo esc_attr( $reverse_datetime ); ?>">
					</div>
				</div>

				<?php if ( ! $is_new ) : ?>
					<?php if ( $is_expired ) : ?>
						<p class="rule-status rule-status--expired">
							<?php esc_html_e( '⚠ Expired — its reverse date has passed, so it no longer applies to any product (new, edited, or via Apply). Update the date above and save, or delete the rule, to reactivate it.', 'wc-tag-discount' ); ?>
						</p>
					<?php endif; ?>
					<p class="rule-status">
						<?php
						$match_count  = count( $this->get_products_for_rule( $rule ) );
						$active_count = count( $this->get_products_with_rule( $rule_key ) );
						$stale_count  = $is_expired ? 0 : $this->count_stale_for_rule( $rule_key, $rule );
						printf(
							/* translators: 1: number of products matching the tag/category, 2: number currently discounted under this rule. */
							esc_html__( '%1$d products match this rule right now — %2$d are currently discounted under it.', 'wc-tag-discount' ),
							(int) $match_count,
							(int) $active_count
						);
						if ( $stale_count > 0 ) :
							?>
							<br>
							<?php
							printf(
								/* translators: %d: number of products still discounted under this rule that no longer match it. */
								esc_html( _n( 'Applying will reverse %d of those that no longer match.', 'Applying will reverse %d of those that no longer match.', $stale_count, 'wc-tag-discount' ) ),
								(int) $stale_count
							);
							?>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<div class="button-group">
					<button type="submit" class="button button-primary"><?php echo $is_new ? esc_html__( 'Add Rule', 'wc-tag-discount' ) : esc_html__( 'Save Changes', 'wc-tag-discount' ); ?></button>
				</div>
			</form>

			<?php if ( ! $is_new ) : ?>
				<div class="button-group rule-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						<?php if ( $stale_count > 0 ) : ?>
							onsubmit="return confirm('<?php echo esc_js( sprintf(
								/* translators: %d: number of products whose discount would be reversed. */
								_n( 'This will also reverse the discount on %d product that no longer matches this rule. Continue?', 'This will also reverse the discount on %d products that no longer match this rule. Continue?', $stale_count, 'wc-tag-discount' ),
								$stale_count
							) ); ?>');"
						<?php endif; ?>
					>
						<input type="hidden" name="action" value="apply_tag_discount_rule">
						<input type="hidden" name="rule_key" value="<?php echo esc_attr( $rule_key ); ?>">
						<?php wp_nonce_field( 'wc_tag_discount_apply_rule' ); ?>
						<button type="submit" class="button" <?php disabled( $is_expired ); ?> title="<?php echo $is_expired ? esc_attr__( 'This rule is expired -- update its reverse date to reactivate it first.', 'wc-tag-discount' ) : ''; ?>"><?php esc_html_e( 'Apply This Rule Now', 'wc-tag-discount' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="reverse_tag_discount_rule">
						<input type="hidden" name="rule_key" value="<?php echo esc_attr( $rule_key ); ?>">
						<?php wp_nonce_field( 'wc_tag_discount_reverse_rule' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Reverse This Rule Now', 'wc-tag-discount' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this rule?', 'wc-tag-discount' ) ); ?>');">
						<input type="hidden" name="action" value="delete_tag_discount_rule">
						<input type="hidden" name="rule_key" value="<?php echo esc_attr( $rule_key ); ?>">
						<?php wp_nonce_field( 'wc_tag_discount_delete_rule' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Delete', 'wc-tag-discount' ); ?></button>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function get_term_label( $taxonomy, $slug ) {
		$term = get_term_by( 'slug', $slug, $taxonomy );

		return ( $term && ! is_wp_error( $term ) ) ? $term->name : $slug;
	}

	/**
	 * Taxonomies a rule can target: every taxonomy actually registered on products
	 * with an admin UI (tags, categories, brands if a brand plugin is active,
	 * attributes used as taxonomies, etc.), not a hardcoded product_tag/product_cat
	 * pair. Keeps this working with whatever taxonomies a given store has without
	 * needing a code change for each brand plugin's own taxonomy name.
	 */
	private function get_selectable_taxonomies() {
		$taxonomies = array();

		foreach ( get_object_taxonomies( 'product', 'objects' ) as $taxonomy ) {
			if ( $taxonomy->show_ui ) {
				$taxonomies[ $taxonomy->name ] = $taxonomy->label;
			}
		}

		return $taxonomies;
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

	public function handle_save_rule() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-tag-discount' ) );
		}
		check_admin_referer( 'wc_tag_discount_save_rule' );

		$allowed_taxonomies = array_keys( $this->get_selectable_taxonomies() );
		$old_key            = isset( $_POST['rule_key'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_key'] ) ) : '';

		$taxonomy = ( isset( $_POST['taxonomy'] ) && in_array( wp_unslash( $_POST['taxonomy'] ), $allowed_taxonomies, true ) )
			? wp_unslash( $_POST['taxonomy'] )
			: 'product_tag';

		$typed = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		$slug  = sanitize_title( $typed );

		$discount = isset( $_POST['discount'] ) ? (float) wp_unslash( $_POST['discount'] ) : 0;
		$discount = max( 0, min( 100, $discount ) );

		$rules = $this->get_discount_rules();

		if ( '' !== $old_key ) {
			unset( $rules[ $old_key ] );
		}

		if ( '' !== $slug && $discount > 0 ) {
			// Create the term if it doesn't exist yet, so a merchant can type a brand
			// new tag/category name directly into the rule instead of creating it first.
			if ( ! term_exists( $slug, $taxonomy ) ) {
				$inserted = wp_insert_term( $typed, $taxonomy );
				if ( ! is_wp_error( $inserted ) ) {
					$term = get_term( $inserted['term_id'], $taxonomy );
					if ( $term && ! is_wp_error( $term ) ) {
						$slug = $term->slug;
					}
				}
			}

			$apply_datetime   = isset( $_POST['apply_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['apply_datetime'] ) ) : '';
			$reverse_datetime = isset( $_POST['reverse_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['reverse_datetime'] ) ) : '';

			if ( '' !== $apply_datetime && false === $this->schedule_datetime_to_timestamp( $apply_datetime ) ) {
				$apply_datetime = '';
			}
			if ( '' !== $reverse_datetime && false === $this->schedule_datetime_to_timestamp( $reverse_datetime ) ) {
				$reverse_datetime = '';
			}

			$rules[ $taxonomy . '_' . $slug ] = array(
				'slug'             => $slug,
				'taxonomy'         => $taxonomy,
				'discount'         => $discount,
				'apply_datetime'   => $apply_datetime,
				'reverse_datetime' => $reverse_datetime,
			);
		}

		update_option( self::OPT_RULES, $rules );
		$this->setup_scheduled_events();

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

	public function handle_delete_rule() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-tag-discount' ) );
		}
		check_admin_referer( 'wc_tag_discount_delete_rule' );

		$rule_key = isset( $_POST['rule_key'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_key'] ) ) : '';
		$rules    = $this->get_discount_rules();

		// Deliberately does NOT reverse discounts on the rule's products here. Any
		// product still carrying this rule's meta becomes an "orphaned" discount --
		// surfaced on the Dashboard (see render_orphaned_rules_card()) where the admin
		// explicitly chooses to reverse it or recreate it as a rule, instead of losing
		// a still-wanted discount the moment its rule definition is removed.
		unset( $rules[ $rule_key ] );
		update_option( self::OPT_RULES, $rules );
		$this->setup_scheduled_events();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'tag-discount-manager',
					'tab'     => 'rules',
					'message' => 'rule_deleted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_apply_rule() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-tag-discount' ) );
		}
		check_admin_referer( 'wc_tag_discount_apply_rule' );

		$rule_key = isset( $_POST['rule_key'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_key'] ) ) : '';
		$rules    = $this->get_discount_rules();

		if ( isset( $rules[ $rule_key ] ) && $this->is_rule_expired( $rules[ $rule_key ] ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'tag-discount-manager',
						'tab'     => 'rules',
						'message' => 'rule_expired',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$count = isset( $rules[ $rule_key ] ) ? $this->apply_rule( $rule_key, $rules[ $rule_key ] ) : 0;

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'tag-discount-manager',
					'tab'     => 'rules',
					'message' => 'applied',
					'count'   => $count,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_reverse_rule() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-tag-discount' ) );
		}
		check_admin_referer( 'wc_tag_discount_reverse_rule' );

		$rule_key = isset( $_POST['rule_key'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_key'] ) ) : '';
		$count    = $this->reverse_rule( $rule_key );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'tag-discount-manager',
					'tab'     => 'rules',
					'message' => 'reversed',
					'count'   => $count,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_pause_auto_apply() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-tag-discount' ) );
		}
		check_admin_referer( 'wc_tag_discount_pause' );

		$hours = isset( $_POST['pause_hours'] ) ? (float) wp_unslash( $_POST['pause_hours'] ) : 4;
		$hours = max( 0.5, min( 24, $hours ) );

		update_option( self::OPT_PAUSE_UNTIL, time() + (int) round( $hours * HOUR_IN_SECONDS ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'tag-discount-manager',
					'message' => 'auto_apply_paused',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_resume_auto_apply() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-tag-discount' ) );
		}
		check_admin_referer( 'wc_tag_discount_resume' );

		delete_option( self::OPT_PAUSE_UNTIL );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'tag-discount-manager',
					'message' => 'auto_apply_resumed',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_reverse_orphaned_rule() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-tag-discount' ) );
		}
		check_admin_referer( 'wc_tag_discount_reverse_orphan' );

		$rule_key = isset( $_POST['rule_key'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_key'] ) ) : '';
		$count    = '' !== $rule_key ? $this->reverse_rule( $rule_key ) : 0;

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

	/**
	 * Turns an orphaned rule key back into a real, editable rule: taxonomy/slug parsed
	 * from the key, discount % reconstructed from a live sample product's current
	 * sale/regular price (see guess_rule_parts_from_key()/guess_discount_for_orphan()).
	 * Refuses -- rather than guessing -- when either can't be determined confidently,
	 * since a wrong recreated discount is worse than none.
	 */
	public function handle_recreate_orphaned_rule() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-tag-discount' ) );
		}
		check_admin_referer( 'wc_tag_discount_recreate_orphan' );

		$rule_key = isset( $_POST['rule_key'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_key'] ) ) : '';

		list( $taxonomy, $slug ) = $this->guess_rule_parts_from_key( $rule_key );
		$discount                = ( $taxonomy && $slug ) ? $this->guess_discount_for_orphan( $rule_key ) : null;

		if ( ! $taxonomy || ! $slug || null === $discount || $discount <= 0 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'tag-discount-manager',
						'message' => 'orphan_recreate_failed',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$rules = $this->get_discount_rules();
		$rules[ $taxonomy . '_' . $slug ] = array(
			'slug'             => $slug,
			'taxonomy'         => $taxonomy,
			'discount'         => $discount,
			'apply_datetime'   => '',
			'reverse_datetime' => '',
		);

		update_option( self::OPT_RULES, $rules );
		$this->setup_scheduled_events();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'tag-discount-manager',
					'tab'     => 'rules',
					'message' => 'orphan_recreated',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Sitewide reminder while auto-apply is paused, so pausing it before a bulk
	 * import can never go unnoticed. Skipped on our own settings page, which
	 * already shows the same status via the Dashboard's Bulk Operations card.
	 */
	public function render_pause_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$paused_until = $this->is_auto_apply_paused();
		if ( ! $paused_until ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'woocommerce_page_tag-discount-manager' === $screen->id ) {
			return;
		}

		$resume_url = wp_nonce_url( admin_url( 'admin-post.php?action=resume_tag_discount_auto_apply' ), 'wc_tag_discount_resume' );

		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: %s: human-readable time remaining, e.g. "3 hours". */
			esc_html__( 'Tag Discount auto-apply is paused for %s (e.g. for a bulk import) — new or edited products will not get their tag/category discount applied automatically until then. Run "Apply Discounts Now" from WooCommerce → Tag Discounts when you\'re done, or:', 'wc-tag-discount' ),
			esc_html( human_time_diff( time(), $paused_until ) )
		);
		echo ' <a href="' . esc_url( $resume_url ) . '" class="button button-small">' . esc_html__( 'Resume auto-apply now', 'wc-tag-discount' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Sitewide reminder that some products are still discounted under a rule that no
	 * longer exists (see handle_delete_rule()) -- so it can't quietly go unnoticed
	 * outside the Dashboard tab. Skipped on our own settings page, which shows the
	 * same thing via render_orphaned_rules_card() with the actual reverse/recreate
	 * choice attached.
	 */
	public function render_orphaned_rules_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'woocommerce_page_tag-discount-manager' === $screen->id ) {
			return;
		}

		$orphans = $this->get_orphaned_rule_keys();
		if ( empty( $orphans ) ) {
			return;
		}

		$dashboard_url = admin_url( 'admin.php?page=tag-discount-manager' );

		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: %d: number of removed rules that still have live discounts on products. */
			esc_html( _n( 'Tag Discount Manager: %d rule was removed but its discount is still live on some products.', 'Tag Discount Manager: %d rules were removed but their discounts are still live on some products.', count( $orphans ), 'wc-tag-discount' ) ),
			(int) count( $orphans )
		);
		echo ' <a href="' . esc_url( $dashboard_url ) . '">' . esc_html__( 'Review them', 'wc-tag-discount' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * The actual safety net: for each rule key still live on products but no longer in
	 * the rules list, let the admin explicitly choose to reverse those discounts or
	 * recreate the rule (see handle_reverse_orphaned_rule()/handle_recreate_orphaned_rule())
	 * rather than the plugin silently deciding either way.
	 */
	private function render_orphaned_rules_card() {
		$orphans = $this->get_orphaned_rule_keys();
		if ( empty( $orphans ) ) {
			return;
		}
		?>
		<div class="discount-card discount-card--warning">
			<h3><?php esc_html_e( 'Discounts From Removed Rules', 'wc-tag-discount' ); ?></h3>
			<p><?php esc_html_e( 'These rules were deleted, but the products they discounted still have that discount applied. Nothing was changed automatically -- choose what to do with each one below.', 'wc-tag-discount' ); ?></p>

			<?php foreach ( $orphans as $rule_key ) : ?>
				<?php
				list( $taxonomy, $slug ) = $this->guess_rule_parts_from_key( $rule_key );
				$product_count           = $this->count_products_with_rule( $rule_key );
				$taxonomy_labels         = $this->get_selectable_taxonomies();

				if ( $taxonomy && $slug ) {
					$label = sprintf(
						/* translators: 1: taxonomy label (e.g. "Tags"), 2: term name. */
						__( '%1$s: %2$s', 'wc-tag-discount' ),
						isset( $taxonomy_labels[ $taxonomy ] ) ? $taxonomy_labels[ $taxonomy ] : $taxonomy,
						$this->get_term_label( $taxonomy, $slug )
					);
				} else {
					$label = $rule_key;
				}

				$guessed_discount = ( $taxonomy && $slug ) ? $this->guess_discount_for_orphan( $rule_key ) : null;
				$can_recreate      = ( $taxonomy && $slug && null !== $guessed_discount && $guessed_discount > 0 );
				?>
				<div class="rule-row-card rule-row-card--expired">
					<p class="rule-status">
						<strong><?php echo esc_html( $label ); ?></strong>
						&mdash;
						<?php
						printf(
							/* translators: %d: number of products still discounted under this removed rule. */
							esc_html( _n( '%d product still discounted under this removed rule.', '%d products still discounted under this removed rule.', $product_count, 'wc-tag-discount' ) ),
							(int) $product_count
						);
						?>
						<?php if ( $can_recreate ) : ?>
							<?php
							printf(
								/* translators: %s: reconstructed discount percentage. */
								esc_html__( ' Recreating it would restore a %s%% rule.', 'wc-tag-discount' ),
								esc_html( $guessed_discount )
							);
							?>
						<?php endif; ?>
					</p>
					<div class="button-group">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="recreate_orphaned_tag_discount_rule">
							<input type="hidden" name="rule_key" value="<?php echo esc_attr( $rule_key ); ?>">
							<?php wp_nonce_field( 'wc_tag_discount_recreate_orphan' ); ?>
							<button type="submit" class="button button-primary" <?php disabled( ! $can_recreate ); ?> title="<?php echo $can_recreate ? '' : esc_attr__( 'Could not reconstruct a taxonomy/slug/discount for this rule -- add it manually on the Discount Rules tab instead.', 'wc-tag-discount' ); ?>">
								<?php esc_html_e( 'Keep Live — Recreate as a Rule', 'wc-tag-discount' ); ?>
							</button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Reverse this discount on every matching product? This cannot be undone.', 'wc-tag-discount' ) ); ?>');">
							<input type="hidden" name="action" value="reverse_orphaned_tag_discount_rule">
							<input type="hidden" name="rule_key" value="<?php echo esc_attr( $rule_key ); ?>">
							<?php wp_nonce_field( 'wc_tag_discount_reverse_orphan' ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Reverse Discount', 'wc-tag-discount' ); ?></button>
						</form>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Read-only equivalent of discount_matching_products()'s ID set (product ids, or
	 * variation ids for a variable product) -- for previewing how many products Apply
	 * would touch without actually writing anything. Kept separate from
	 * discount_matching_products() rather than adding a dry-run flag there, since that
	 * method's whole job is the write.
	 */
	private function get_matching_ids_expanded( $rule ) {
		$ids = array();

		foreach ( $this->get_products_for_rule( $rule ) as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$ids[ $variation_id ] = true;
				}
			} else {
				$ids[ $product_id ] = true;
			}
		}

		return $ids;
	}

	/**
	 * How many products/variations sync_rule() would reverse if this rule were applied
	 * right now -- i.e. still carrying this rule's own meta but no longer matching its
	 * taxonomy. Used to warn before Apply, since Apply now reverses stale matches (see
	 * sync_rule()) and that's a real, immediate price change on a live shop that
	 * shouldn't happen without the admin seeing the number first.
	 */
	private function count_stale_for_rule( $rule_key, $rule ) {
		$matched = $this->get_matching_ids_expanded( $rule );

		return count( array_diff( $this->get_products_with_rule( $rule_key ), array_keys( $matched ) ) );
	}

	/**
	 * Applies one rule to every product it currently matches. Returns the set of
	 * affected product/variation IDs (not just a count) so apply_discounts() can
	 * de-duplicate correctly when a product matches more than one rule.
	 */
	private function discount_matching_products( $rule_key, $rule ) {
		$affected = array();

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

		return $affected;
	}

	/**
	 * Runs a bulk apply/reverse operation with auto_update_product_discount()
	 * unhooked for its duration. Necessary because saving a variation makes
	 * WooCommerce internally re-save its parent product to keep the parent's
	 * cached price range in sync -- that parent save fires save_post_product
	 * like any other, and without this guard our own auto-detect hook would see
	 * it, find the parent still carries the matching tag, and silently
	 * re-apply the very discount a bulk reverse was in the middle of clearing.
	 */
	private function without_auto_update( callable $callback ) {
		remove_action( 'save_post_product', array( $this, 'auto_update_product_discount' ), 10 );
		$result = $callback();
		add_action( 'save_post_product', array( $this, 'auto_update_product_discount' ), 10, 1 );

		return $result;
	}

	private function reverse_products( array $product_ids ) {
		$count = 0;

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product && $this->reverse_discount_on_product( $product ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Applies a rule to everything it currently matches, and reverses it on any
	 * product/variation still carrying this rule's own meta that no longer matches --
	 * e.g. a product's tag/category/brand assignment changed (bulk import, brand
	 * cleanup, etc.) since the last apply. Without this, apply was purely additive:
	 * a still-listed, still-active rule's discount could survive indefinitely on
	 * products it no longer actually matches. Deliberately scoped to this one rule's
	 * own meta value, never touching a different (e.g. orphaned/deleted) rule's
	 * products -- that stays the admin's explicit call via the orphan review card.
	 */
	private function sync_rule( $rule_key, $rule ) {
		$matched = $this->discount_matching_products( $rule_key, $rule );
		$stale   = array_diff( $this->get_products_with_rule( $rule_key ), array_keys( $matched ) );
		$this->reverse_products( $stale );

		return $matched;
	}

	public function apply_discounts() {
		return $this->without_auto_update(
			function () {
				$affected = array();
				foreach ( $this->get_active_discount_rules() as $rule_key => $rule ) {
					$affected += $this->sync_rule( $rule_key, $rule );
				}
				return count( $affected );
			}
		);
	}

	public function apply_rule( $rule_key, $rule ) {
		return $this->without_auto_update(
			function () use ( $rule_key, $rule ) {
				return count( $this->sync_rule( $rule_key, $rule ) );
			}
		);
	}

	public function reverse_discounts() {
		return $this->without_auto_update(
			function () {
				return $this->reverse_products( $this->get_products_with_active_discount() );
			}
		);
	}

	public function reverse_rule( $rule_key ) {
		return $this->without_auto_update(
			function () use ( $rule_key ) {
				return $this->reverse_products( $this->get_products_with_rule( $rule_key ) );
			}
		);
	}

	public function auto_update_product_discount( $post_id ) {
		if ( $this->is_auto_apply_paused() ) {
			return;
		}

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

		$rules        = $this->get_active_discount_rules();
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

	/**
	 * Distinct rule keys currently recorded on any product's discount meta, straight
	 * from postmeta. A single DISTINCT query regardless of catalog size -- deliberately
	 * not get_posts()/WP_Query here, since this can run on every admin page load (see
	 * render_orphaned_rules_notice()) and the catalog is headed from ~1,000 to ~3,000
	 * products shortly.
	 */
	private function get_live_rule_keys_in_use() {
		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				self::META_RULE
			)
		);
	}

	/**
	 * Rule keys with live discounted products but no matching entry in the current
	 * rules list -- typically left behind by deleting a rule (see handle_delete_rule()),
	 * which intentionally leaves this data in place for review here rather than
	 * discarding it silently.
	 */
	private function get_orphaned_rule_keys() {
		$known_keys = array_keys( $this->get_discount_rules() );
		$live_keys  = $this->get_live_rule_keys_in_use();

		return array_values( array_diff( $live_keys, $known_keys ) );
	}

	/**
	 * Cheap COUNT for a single rule key, not a full get_posts() fetch -- used for the
	 * orphan review card where we only need a number per orphan, not the objects.
	 */
	private function count_products_with_rule( $rule_key ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				self::META_RULE,
				$rule_key
			)
		);
	}

	private function get_one_product_id_with_rule( $rule_key ) {
		$ids = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_RULE,
				'meta_value'     => $rule_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- exact match on our own meta key, limited to 1 result.
			)
		);

		return $ids ? $ids[0] : null;
	}

	/**
	 * Best-effort split of a rule key ("{taxonomy}_{slug}") back into its parts, by
	 * matching against currently-registered selectable taxonomies (longest name first,
	 * so e.g. a hypothetical "product_tag_extra" taxonomy isn't shadowed by
	 * "product_tag"). Returns array(null, null) if no known taxonomy's prefix matches
	 * -- e.g. the term's taxonomy came from a plugin that's since been deactivated.
	 */
	private function guess_rule_parts_from_key( $rule_key ) {
		$taxonomies = array_keys( $this->get_selectable_taxonomies() );
		usort(
			$taxonomies,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		foreach ( $taxonomies as $taxonomy ) {
			$prefix = $taxonomy . '_';
			if ( 0 === strpos( $rule_key, $prefix ) ) {
				return array( $taxonomy, substr( $rule_key, strlen( $prefix ) ) );
			}
		}

		return array( null, null );
	}

	/**
	 * Reconstructs the original discount % for an orphaned rule from a live sample
	 * product's current sale vs. regular price -- the rule definition (which used to
	 * carry the discount value) is gone, so this ratio is the only place it survives.
	 * Returns null if it can't be determined (e.g. no matching product, or a since-
	 * changed regular price), in which case recreation is refused rather than guessed.
	 */
	private function guess_discount_for_orphan( $rule_key ) {
		$sample_id = $this->get_one_product_id_with_rule( $rule_key );
		if ( ! $sample_id ) {
			return null;
		}

		$product = wc_get_product( $sample_id );
		if ( ! $product ) {
			return null;
		}

		$regular_price = $this->get_discountable_regular_price( $product );
		$sale_price    = $product->get_sale_price( 'edit' );

		if ( null === $regular_price || $regular_price <= 0 || '' === $sale_price || ! is_numeric( $sale_price ) ) {
			return null;
		}

		return round( ( 1 - ( (float) $sale_price / $regular_price ) ) * 100, 2 );
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

	private function get_products_with_rule( $rule_key ) {
		return get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_RULE,
				'meta_value'     => $rule_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- exact match on our own indexed-by-usage meta key, needed to scope a reverse to one rule.
			)
		);
	}
}
