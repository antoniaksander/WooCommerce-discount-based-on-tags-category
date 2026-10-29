<?php
/**
 * Plain-PHP tests for WC_Tag_Discount_Price. No WordPress needed:
 *
 *     php tests/test-price-rounding.php
 *
 * Exits non-zero if any check fails.
 *
 * @package WC_Tag_Discount_Manager
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-wc-tag-discount-price.php';

$failures = 0;
$checks   = 0;

/**
 * Compares a computed price with the expected one.
 *
 * @param string $label    What is checked.
 * @param float  $actual   Computed price.
 * @param float  $expected Expected price.
 */
function check( $label, $actual, $expected ) {
	global $failures, $checks;
	++$checks;
	if ( abs( (float) $actual - (float) $expected ) > 0.0001 ) {
		++$failures;
		echo "FAIL {$label}: got {$actual}, expected {$expected}\n";
	}
}

/**
 * Shorthand for a two-decimal store.
 *
 * @param float  $regular  Regular price.
 * @param float  $discount Discount %.
 * @param string $mode     Rounding mode.
 * @param string $preset   Preset key.
 * @return float
 */
function price( $regular, $discount, $mode = 'off', $preset = 'end_95' ) {
	return WC_Tag_Discount_Price::discounted( $regular, $discount, 2, $mode, $preset );
}

// Exact discount, rounding off: half-up in cents, no float artefacts.
check( '13.95 -20%', price( 13.95, 20 ), 11.16 );
check( '149.95 -10% (float bug was 134.95)', price( 149.95, 10 ), 134.96 );
check( '224.95 -10% (float bug was 202.45)', price( 224.95, 10 ), 202.46 );
check( '189.95 -10%', price( 189.95, 10 ), 170.96 );
check( '84.95 -15%', price( 84.95, 15 ), 72.21 );
check( '19.99 -12.5%', price( 19.99, 12.5 ), 17.49 );
check( '0.01 -50%', price( 0.01, 50 ), 0.01 );
check( '100 -100%', price( 100, 100 ), 0 );
check( 'zero decimals', WC_Tag_Discount_Price::discounted( 1999, 15, 0, 'down', 'end_95' ), 1699 );

// Round down, every preset. 11.16 is 13.95 -20%.
$down = array(
	'step_5'    => 11.15,
	'step_10'   => 11.10,
	'step_25'   => 11.00,
	'step_50'   => 11.00,
	'end_45_95' => 10.95,
	'end_95'    => 10.95,
	'end_99'    => 10.99,
	'step_100'  => 11.00,
);
foreach ( $down as $preset => $expected ) {
	check( "13.95 -20% down {$preset}", price( 13.95, 20, 'down', $preset ), $expected );
}

// Nearest. 23.16 is 28.95 -20%.
$nearest = array(
	'step_5'    => 23.15,
	'step_10'   => 23.20,
	'step_25'   => 23.25,
	'step_50'   => 23.00,
	'end_45_95' => 22.95,
	'end_95'    => 22.95,
	'end_99'    => 22.99,
	'step_100'  => 23.00,
);
foreach ( $nearest as $preset => $expected ) {
	check( "28.95 -20% nearest {$preset}", price( 28.95, 20, 'nearest', $preset ), $expected );
}

// Up. 35.96 is 44.95 -20%.
$up = array(
	'step_5'    => 36.00,
	'step_10'   => 36.00,
	'step_25'   => 36.00,
	'step_50'   => 36.00,
	'end_45_95' => 36.45,
	'end_95'    => 36.95,
	'end_99'    => 35.99,
	'step_100'  => 36.00,
);
foreach ( $up as $preset => $expected ) {
	check( "44.95 -20% up {$preset}", price( 44.95, 20, 'up', $preset ), $expected );
}

// Exact matches stay put in every mode.
check( '35.95 stays (down end_95)', WC_Tag_Discount_Price::round_cents( 3595, 4495, 'down', 'end_95' ), 3595 );
check( '35.95 stays (up end_95)', WC_Tag_Discount_Price::round_cents( 3595, 4495, 'up', 'end_95' ), 3595 );
check( '35.95 stays (nearest end_95)', WC_Tag_Discount_Price::round_cents( 3595, 4495, 'nearest', 'end_95' ), 3595 );
check( '11.50 stays (down step_50)', WC_Tag_Discount_Price::round_cents( 1150, 1395, 'down', 'step_50' ), 1150 );

// Nearest: halfway rounds up.
check( '10.45 nearest end_95 -> 10.95', WC_Tag_Discount_Price::round_cents( 1045, 2000, 'nearest', 'end_95' ), 1095 );
check( '10.44 nearest end_95 -> 9.95', WC_Tag_Discount_Price::round_cents( 1044, 2000, 'nearest', 'end_95' ), 995 );

// Up would reach or pass the regular price: falls back to down.
check( '10.95 regular, 10.40 up end_95 -> 9.95', price( 10.95, 5, 'up', 'end_95' ), 9.95 );
check( '12.00 regular, 11.40 up step_100 -> 11.00', price( 12.00, 5, 'up', 'step_100' ), 11.00 );
check( '11.95 regular, 11.71 nearest end_95 -> 10.95', price( 11.95, 2, 'nearest', 'end_95' ), 10.95 );

// Down would give zero or less: exact price kept.
check( '0.80 regular -20% down end_95 keeps 0.64', price( 0.80, 20, 'down', 'end_95' ), 0.64 );
check( '0.50 regular -10% down step_100 keeps 0.45', price( 0.50, 10, 'down', 'step_100' ), 0.45 );
check( '1.20 regular -20% down end_95 -> 0.95', price( 1.20, 20, 'down', 'end_95' ), 0.95 );

// Never at or above the regular price, never above the exact price when rounding down.
$prices = array( 0.99, 4.95, 9.95, 10.95, 13.95, 16.95, 28.95, 44.95, 99.00, 149.95, 314.95 );
foreach ( $prices as $regular ) {
	foreach ( array( 5, 10, 15, 20, 25, 30, 50 ) as $discount ) {
		$exact = price( $regular, $discount );
		foreach ( array_keys( WC_Tag_Discount_Price::presets() ) as $preset ) {
			foreach ( array( 'down', 'nearest', 'up' ) as $mode ) {
				$rounded = price( $regular, $discount, $mode, $preset );
				++$checks;
				if ( $rounded <= 0 || $rounded >= $regular ) {
					++$failures;
					echo "FAIL {$regular} -{$discount}% {$mode} {$preset}: {$rounded} not between 0 and the regular price\n";
				}
				if ( 'down' === $mode && $rounded > $exact ) {
					++$failures;
					echo "FAIL {$regular} -{$discount}% down {$preset}: {$rounded} above exact {$exact}\n";
				}
			}
		}
	}
}

// Off and unknown presets change nothing.
check( 'mode off', price( 13.95, 20, 'off', 'end_95' ), 11.16 );
check( 'unknown preset', price( 13.95, 20, 'down', 'nope' ), 11.16 );

echo "{$checks} checks, {$failures} failed\n";
exit( $failures ? 1 : 0 );
