<?php
defined( 'ABSPATH' ) || exit;

/**
 * One shop, one currency to think in.
 *
 * A shop selling in five markets takes orders in several currencies, and the
 * sales tables keep every line in the currency it was PAID in. Adding those
 * columns up gives a number that is not money: 100 EUR + 100 PLN + 100 USD is
 * not 300 of anything. Every figure this plugin shows or sorts by is brought
 * back to the shop's own currency here, or it is not shown at all.
 *
 * Quantities are not money and never come through here: a product sold four
 * times was sold four times in every currency on earth.
 *
 * The rates are the SHOP's, read from whichever multi-currency plugin it runs
 * — WooCommerce Multilingual, WOOCS, Aelia — and never invented. A currency
 * whose rate cannot be read is reported as unknown rather than counted at one
 * to one, because a wrong total is worse than a missing one: it is believed.
 */
final class DZE_Money {

	/** The currency the shop keeps its books in. */
	public static function base(): string {
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'USD';
	}

	/**
	 * How many units of the base currency one unit of $code is worth.
	 *
	 * @return float|null NULL when this shop cannot say — never a guess.
	 */
	public static function rate( string $code ): ?float {
		$code = strtoupper( trim( $code ) );
		if ( '' === $code || $code === self::base() ) {
			return 1.0;
		}
		static $known = [];
		if ( array_key_exists( $code, $known ) ) {
			return $known[ $code ];
		}
		$known[ $code ] = null;

		// A shop with a multi-currency plugin nobody here has heard of can
		// still answer, in its own theme or a mu-plugin, with one filter.
		$said = apply_filters( 'dze_currency_rate', null, $code, self::base() );
		if ( is_numeric( $said ) && (float) $said > 0 ) {
			$known[ $code ] = (float) $said;
			return $known[ $code ];
		}

		foreach ( self::rate_tables() as $rates ) {
			if ( ! is_array( $rates ) ) {
				continue;
			}
			$one = $rates[ $code ] ?? $rates[ strtolower( $code ) ] ?? null;
			// WooCommerce Multilingual keeps a row per currency, the rate under
			// 'rate'; the simpler plugins keep the number itself.
			if ( is_array( $one ) ) {
				$one = $one['rate'] ?? ( $one[1] ?? null );
			}
			if ( is_numeric( $one ) && (float) $one > 0 ) {
				$known[ $code ] = (float) $one;
				return $known[ $code ];
			}
		}
		return null;
	}

	/**
	 * Where the shop's own plugins keep their rates. Read-only, and only ever
	 * options these plugins publish.
	 *
	 * @return array[]
	 */
	private static function rate_tables(): array {
		$out = [];
		// WooCommerce Multilingual (WPML), which is what this shop runs.
		$wcml = get_option( '_wcml_settings', [] );
		if ( is_array( $wcml ) && ! empty( $wcml['currency_options'] ) ) {
			$out[] = (array) $wcml['currency_options'];
		}
		// WOOCS, and Aelia, both of which keep a plain map.
		foreach ( [ 'woocs_currency_data', 'woocommerce_aelia_currencyswitcher_exchange_rates' ] as $key ) {
			$one = get_option( $key, [] );
			if ( is_array( $one ) && $one ) {
				$out[] = $one;
			}
		}
		return $out;
	}

	/**
	 * An amount, in the shop's own currency.
	 *
	 * @return float|null NULL when the rate is unknown — the caller then says
	 *                    so on the screen instead of printing a wrong total.
	 */
	public static function to_base( float $amount, string $code ): ?float {
		$rate = self::rate( $code );
		return null === $rate ? null : $amount * $rate;
	}

	/** The amount as the shop writes money, in its own currency. */
	public static function say( float $amount ): string {
		if ( function_exists( 'wc_price' ) ) {
			return (string) wc_price( $amount, [ 'currency' => self::base() ] );
		}
		return number_format( $amount, 2 ) . ' ' . self::base();
	}
}
