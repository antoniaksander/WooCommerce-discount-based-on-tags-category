<?php
/**
 * Plain-PHP tests for how applying and reversing a discount treats
 * WooCommerce's own sale dates. Uses a small stand-in for WC_Product:
 *
 *     php tests/test-sale-dates.php
 *
 * Exits non-zero if any check fails.
 *
 * @package WC_Tag_Discount_Manager
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- test stubs live next to the checks.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- test stand-in class.

define( 'ABSPATH', __DIR__ . '/' );

/**
 * Stub: no stored options, so rounding is off.
 *
 * @param string $name     Option name.
 * @param mixed  $fallback Default value.
 */
function get_option( $name, $fallback = false ) {
	return $fallback;
}

/**
 * Stub: a two-decimal store.
 */
function wc_get_price_decimals() {
	return 2;
}

/**
 * Stand-in for the parts of WC_Product the discount code uses.
 */
class Test_Product {
	/**
	 * Product data.
	 *
	 * @var array
	 */
	public $data = array(
		'regular_price' => '',
		'sale_price'    => '',
		'from'          => null,
		'to'            => null,
	);

	/**
	 * Meta data.
	 *
	 * @var array
	 */
	public $meta = array();

	/**
	 * Number of save() calls.
	 *
	 * @var int
	 */
	public $saves = 0;

	/**
	 * Sets up the product.
	 *
	 * @param string   $regular Regular price.
	 * @param string   $sale    Sale price.
	 * @param int|null $from    Sale start timestamp.
	 * @param int|null $to      Sale end timestamp.
	 */
	public function __construct( $regular, $sale = '', $from = null, $to = null ) {
		$this->data['regular_price'] = $regular;
		$this->data['sale_price']    = $sale;
		$this->data['from']          = null === $from ? null : new DateTime( '@' . $from );
		$this->data['to']            = null === $to ? null : new DateTime( '@' . $to );
	}

	/** Regular price. */
	public function get_regular_price() {
		return $this->data['regular_price'];
	}

	/** Sale price. */
	public function get_sale_price() {
		return $this->data['sale_price'];
	}

	/**
	 * Sets the sale price.
	 *
	 * @param string $price Price.
	 */
	public function set_sale_price( $price ) {
		$this->data['sale_price'] = (string) $price;
	}

	/** Sale start. */
	public function get_date_on_sale_from() {
		return $this->data['from'];
	}

	/** Sale end. */
	public function get_date_on_sale_to() {
		return $this->data['to'];
	}

	/**
	 * Sets the sale start.
	 *
	 * @param int|null $timestamp Timestamp.
	 */
	public function set_date_on_sale_from( $timestamp ) {
		$this->data['from'] = null === $timestamp ? null : new DateTime( '@' . $timestamp );
	}

	/**
	 * Sets the sale end.
	 *
	 * @param int|null $timestamp Timestamp.
	 */
	public function set_date_on_sale_to( $timestamp ) {
		$this->data['to'] = null === $timestamp ? null : new DateTime( '@' . $timestamp );
	}

	/**
	 * Reads meta.
	 *
	 * @param string $key Meta key.
	 */
	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	/**
	 * Whether meta exists.
	 *
	 * @param string $key Meta key.
	 */
	public function meta_exists( $key ) {
		return array_key_exists( $key, $this->meta );
	}

	/**
	 * Writes meta.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Value.
	 */
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	/**
	 * Deletes meta.
	 *
	 * @param string $key Meta key.
	 */
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	/** Counts saves. */
	public function save() {
		++$this->saves;
	}
}

require dirname( __DIR__ ) . '/includes/class-wc-tag-discount-price.php';
require dirname( __DIR__ ) . '/includes/class-wc-tag-discount-manager.php';

$failures = 0;
$checks   = 0;

/**
 * Compares two values.
 *
 * @param string $label    What is checked.
 * @param mixed  $actual   Actual value.
 * @param mixed  $expected Expected value.
 */
function check( $label, $actual, $expected ) {
	global $failures, $checks;
	++$checks;
	if ( $actual !== $expected ) {
		++$failures;
		echo "FAIL {$label}: got " . var_export( $actual, true ) . ', expected ' . var_export( $expected, true ) . "\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
	}
}

/**
 * Timestamp of a product date, or null.
 *
 * @param DateTime|null $date Date.
 */
function ts( $date ) {
	return $date ? $date->getTimestamp() : null;
}

/**
 * Calls a private method on the manager.
 *
 * @param object $manager Manager instance.
 * @param string $method  Method name.
 * @param array  $args    Arguments.
 */
function call_private( $manager, $method, array $args = array() ) {
	$reflection = new ReflectionMethod( $manager, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $manager, $args );
}

$manager = ( new ReflectionClass( 'WC_Tag_Discount_Manager' ) )->newInstanceWithoutConstructor();
$rule    = array(
	'taxonomy'         => 'product_tag',
	'slug'             => 'sale',
	'discount'         => 20,
	'apply_datetime'   => '',
	'reverse_datetime' => '',
);

$june = 1782431999; // 25 Jun 2026, an expired end date left from an earlier sale.
$dec  = 1796083200; // A start date in the future.

// An old, expired native sale: the rule's price must be live, and reverse puts it all back.
$p = new Test_Product( '18.95', '17.35', null, $june );
call_private( $manager, 'apply_discount_to_product', array( $p, 'product_tag_sale', $rule ) );
check( 'expired: sale price set', $p->get_sale_price(), '15.16' );
check( 'expired: end date cleared', ts( $p->get_date_on_sale_to() ), null );
check( 'expired: start date cleared', ts( $p->get_date_on_sale_from() ), null );
check( 'expired: previous sale price kept', $p->get_meta( '_wc_tag_discount_prev_sale_price' ), '17.35' );
call_private( $manager, 'reverse_discount_on_product', array( $p ) );
check( 'expired: reverse restores sale price', $p->get_sale_price(), '17.35' );
check( 'expired: reverse restores end date', ts( $p->get_date_on_sale_to() ), $june );
check( 'expired: reverse restores no start date', ts( $p->get_date_on_sale_from() ), null );
check( 'expired: reverse removes date meta', $p->meta_exists( '_wc_tag_discount_prev_sale_to' ), false );

// A native sale scheduled for later: cleared while the rule is on, restored after.
$p = new Test_Product( '50.00', '40.00', $dec, null );
call_private( $manager, 'apply_discount_to_product', array( $p, 'product_tag_sale', $rule ) );
check( 'future: start date cleared', ts( $p->get_date_on_sale_from() ), null );
check( 'future: sale price set', $p->get_sale_price(), '40' );
call_private( $manager, 'reverse_discount_on_product', array( $p ) );
check( 'future: reverse restores start date', ts( $p->get_date_on_sale_from() ), $dec );

// No dates at all: nothing to restore, no dates appear.
$p = new Test_Product( '13.95' );
call_private( $manager, 'apply_discount_to_product', array( $p, 'product_tag_sale', $rule ) );
call_private( $manager, 'apply_discount_to_product', array( $p, 'product_tag_sale', $rule ) );
check( 'plain: re-apply keeps empty previous dates', $p->get_meta( '_wc_tag_discount_prev_sale_to' ), '' );
call_private( $manager, 'reverse_discount_on_product', array( $p ) );
check( 'plain: reverse clears sale price', $p->get_sale_price(), '' );
check( 'plain: reverse leaves no end date', ts( $p->get_date_on_sale_to() ), null );

// Discounted before 2.0.1 (rule meta but no date meta): dates are still captured on re-apply.
$p = new Test_Product( '18.95', '15.16', null, $june );
$p->update_meta_data( '_wc_tag_discount_rule', 'product_tag_sale' );
$p->update_meta_data( '_wc_tag_discount_prev_sale_price', '17.35' );
call_private( $manager, 'apply_discount_to_product', array( $p, 'product_tag_sale', $rule ) );
check( 'legacy: previous sale price not overwritten', $p->get_meta( '_wc_tag_discount_prev_sale_price' ), '17.35' );
check( 'legacy: end date captured', (int) $p->get_meta( '_wc_tag_discount_prev_sale_to' ), $june );
check( 'legacy: end date cleared', ts( $p->get_date_on_sale_to() ), null );

echo "{$checks} checks, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
