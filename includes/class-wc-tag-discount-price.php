<?php
/**
 * Sale price maths: the discount itself and the optional rounding of the result.
 *
 * Kept free of WordPress/WooCommerce calls so it can be unit tested on its own
 * (see tests/test-price-rounding.php). Everything is done in integer minor
 * units (cents) -- float maths gave answers like 149.95 x 0.9 = 134.95 instead
 * of 134.96, because 134.955 is stored as 134.95499...
 *
 * @package WC_Tag_Discount_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Tag_Discount_Price {

	const MODE_OFF     = 'off';
	const MODE_DOWN    = 'down';
	const MODE_NEAREST = 'nearest';
	const MODE_UP      = 'up';

	/**
	 * Rounding modes, in the order the settings screen lists them.
	 *
	 * @return string[]
	 */
	public static function modes() {
		return array( self::MODE_OFF, self::MODE_DOWN, self::MODE_NEAREST, self::MODE_UP );
	}

	/**
	 * Price endings as step + offset in cents: a rounded price is always
	 * k x step - offset. E.g. step 100, offset 5 gives 9.95, 10.95, 11.95.
	 *
	 * @return array<string, array{0:int, 1:int}>
	 */
	public static function presets() {
		return array(
			'step_5'    => array( 5, 0 ),
			'step_10'   => array( 10, 0 ),
			'step_25'   => array( 25, 0 ),
			'step_50'   => array( 50, 0 ),
			'end_45_95' => array( 50, 5 ),
			'end_95'    => array( 100, 5 ),
			'end_99'    => array( 100, 1 ),
			'step_100'  => array( 100, 0 ),
		);
	}

	/**
	 * Regular price less the discount, then rounded as configured.
	 *
	 * @param float  $regular_price Regular price.
	 * @param float  $discount      Discount in percent, e.g. 20 or 12.5.
	 * @param int    $decimals      The store's price decimals (wc_get_price_decimals()).
	 * @param string $mode          One of the MODE_* constants.
	 * @param string $preset        A key of presets().
	 * @return float
	 */
	public static function discounted( $regular_price, $discount, $decimals, $mode = self::MODE_OFF, $preset = 'end_95' ) {
		$decimals = max( 0, (int) $decimals );
		$unit     = (int) pow( 10, $decimals );
		$regular  = (int) round( (float) $regular_price * $unit );

		// Discount in hundredths of a percent, so 12.5% works as well as 20%.
		$basis = (int) round( max( 0, min( 100, (float) $discount ) ) * 100 );

		// Half-up rounding in integers.
		$sale = intdiv( $regular * ( 10000 - $basis ) + 5000, 10000 );

		// Presets are defined in cents, so they only apply to two-decimal currencies.
		if ( 2 === $decimals ) {
			$sale = self::round_cents( $sale, $regular, $mode, $preset );
		}

		return round( $sale / $unit, $decimals );
	}

	/**
	 * Rounds a sale price (in cents) to the preset's endings.
	 *
	 * Never returns a price at or above the regular price: rounding up or to
	 * nearest falls back to rounding down when it would. If rounding down would
	 * give zero or less, the exact price is kept.
	 *
	 * @param int    $sale    Sale price in cents.
	 * @param int    $regular Regular price in cents.
	 * @param string $mode    One of the MODE_* constants.
	 * @param string $preset  A key of presets().
	 * @return int
	 */
	public static function round_cents( $sale, $regular, $mode, $preset ) {
		$presets = self::presets();

		if ( self::MODE_OFF === $mode || ! isset( $presets[ $preset ] ) || $sale <= 0 || $sale >= $regular ) {
			return $sale;
		}

		list( $step, $offset ) = $presets[ $preset ];

		$down = intdiv( $sale + $offset, $step ) * $step - $offset;

		if ( self::MODE_UP === $mode ) {
			$rounded = intdiv( $sale + $offset + $step - 1, $step ) * $step - $offset;
		} elseif ( self::MODE_NEAREST === $mode ) {
			$rounded = intdiv( $sale + $offset + intdiv( $step, 2 ), $step ) * $step - $offset;
		} else {
			$rounded = $down;
		}

		if ( $rounded >= $regular ) {
			$rounded = $down;
		}

		return $rounded > 0 ? $rounded : $sale;
	}
}
