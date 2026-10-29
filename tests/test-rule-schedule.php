<?php
/**
 * Plain-PHP tests for which rules count as active (started and not expired).
 * Stubs the few WordPress functions the rule helpers use:
 *
 *     php tests/test-rule-schedule.php
 *
 * Exits non-zero if any check fails.
 *
 * @package WC_Tag_Discount_Manager
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- test stubs live next to the checks.

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['tdm_test_options'] = array();

/**
 * Stub: reads from the in-memory options.
 *
 * @param string $name    Option name.
 * @param mixed  $fallback Default value.
 */
function get_option( $name, $fallback = false ) {
	return array_key_exists( $name, $GLOBALS['tdm_test_options'] ) ? $GLOBALS['tdm_test_options'][ $name ] : $fallback;
}

/**
 * Stub: writes to the in-memory options.
 *
 * @param string $name  Option name.
 * @param mixed  $value Value.
 */
function update_option( $name, $value ) {
	$GLOBALS['tdm_test_options'][ $name ] = $value;
	return true;
}

/**
 * Stub: deletes from the in-memory options.
 *
 * @param string $name Option name.
 */
function delete_option( $name ) {
	unset( $GLOBALS['tdm_test_options'][ $name ] );
	return true;
}

/**
 * Stub: the site timezone.
 */
function wp_timezone() {
	return new DateTimeZone( 'Europe/Tallinn' );
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
 * Formats a timestamp as a datetime-local value in the site timezone.
 *
 * @param int $timestamp Unix timestamp.
 */
function local_datetime( $timestamp ) {
	return ( new DateTime( '@' . $timestamp ) )->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' );
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
$opt     = ( new ReflectionClass( 'WC_Tag_Discount_Manager' ) )->getConstant( 'OPT_RULES' );

$day  = 86400;
$now  = time();
$rule = function ( $slug, $apply, $reverse ) {
	return array(
		'taxonomy'         => 'product_tag',
		'slug'             => $slug,
		'discount'         => 20,
		'apply_datetime'   => $apply,
		'reverse_datetime' => $reverse,
	);
};

$GLOBALS['tdm_test_options'][ $opt ] = array(
	'product_tag_no-dates'  => $rule( 'no-dates', '', '' ),
	'product_tag_started'   => $rule( 'started', local_datetime( $now - $day ), local_datetime( $now + $day ) ),
	'product_tag_scheduled' => $rule( 'scheduled', local_datetime( $now + 28 * $day ), local_datetime( $now + 31 * $day ) ),
	'product_tag_expired'   => $rule( 'expired', local_datetime( $now - 10 * $day ), local_datetime( $now - $day ) ),
	'product_tag_open-end'  => $rule( 'open-end', local_datetime( $now + 2 * $day ), '' ),
);

$rules = call_private( $manager, 'get_discount_rules' );

check( 'no dates: not pending', call_private( $manager, 'is_rule_pending', array( $rules['product_tag_no-dates'] ) ), false );
check( 'started: not pending', call_private( $manager, 'is_rule_pending', array( $rules['product_tag_started'] ) ), false );
check( 'scheduled: pending', call_private( $manager, 'is_rule_pending', array( $rules['product_tag_scheduled'] ) ), true );
check( 'expired: not pending', call_private( $manager, 'is_rule_pending', array( $rules['product_tag_expired'] ) ), false );
check( 'future start, no end: pending', call_private( $manager, 'is_rule_pending', array( $rules['product_tag_open-end'] ) ), true );

$active = array_keys( call_private( $manager, 'get_active_discount_rules' ) );
sort( $active );
check( 'active = no dates + started', $active, array( 'product_tag_no-dates', 'product_tag_started' ) );

$pending = array_keys( call_private( $manager, 'get_pending_discount_rules' ) );
sort( $pending );
check( 'pending = scheduled + open-end', $pending, array( 'product_tag_open-end', 'product_tag_scheduled' ) );

echo "{$checks} checks, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
