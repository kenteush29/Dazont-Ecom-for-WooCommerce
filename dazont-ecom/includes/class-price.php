<?php
defined( 'ABSPATH' ) || exit;

/**
 * Charm rounding, shared by every module that computes a price.
 *
 * A discount of 15 % on 34.90 gives 29.665 → 29.67, which nobody would ever
 * put on a shelf. This rounds the result to a fixed ending so a promotion
 * looks like a price and not like the output of a calculator.
 *
 * The DIRECTION is not the shop's choice, it is a property of the price:
 *
 *  - a SALE price rounds DOWN. Rounding it up would make the real reduction
 *    smaller than the percentage announced next to it — 34.90 at −15 % shown
 *    as 29.90 is a 14.3 % discount advertised as 15 %.
 *  - a SELLING price computed from a cost rounds UP, so a margin is never
 *    quietly shaved off by the presentation.
 *
 * Which is why the ending matters more than it looks: on a sale price, a
 * unit-level ending (.90, .99) gives away up to one whole unit, while a
 * ten-cent ending gives away at most ten cents. The settings screen shows
 * the real figures for the chosen ending rather than explaining the maths.
 *
 * Off by default: an existing shop's prices are not rewritten because a
 * plugin was updated.
 */
final class DZE_Price {

	public const OPTION = 'dze_price_rounding';

	/**
	 * Endings on offer. Everything is in CENTS (integers) — a price ladder
	 * built on floats lands on 29.900000000000002 sooner or later.
	 *
	 * step: the ladder's rung, offset: where on the rung the price sits.
	 *
	 * @return array<string,array{label:string,step:int,offset:int}>
	 */
	public static function endings(): array {
		return [
			'off'   => [ 'label' => __( 'Off — exact arithmetic', 'dazont-ecom' ), 'step' => 0,   'offset' => 0 ],
			'x9'    => [ 'label' => __( 'Ends in 9 cents (29.69)', 'dazont-ecom' ), 'step' => 10,  'offset' => 9 ],
			'x5'    => [ 'label' => __( 'Ends in 5 cents (29.65)', 'dazont-ecom' ), 'step' => 10,  'offset' => 5 ],
			'90'    => [ 'label' => __( 'Ends in .90 (28.90)', 'dazont-ecom' ),     'step' => 100, 'offset' => 90 ],
			'95'    => [ 'label' => __( 'Ends in .95 (28.95)', 'dazont-ecom' ),     'step' => 100, 'offset' => 95 ],
			'99'    => [ 'label' => __( 'Ends in .99 (28.99)', 'dazont-ecom' ),     'step' => 100, 'offset' => 99 ],
			'whole' => [ 'label' => __( 'Whole units, no cents (29)', 'dazont-ecom' ), 'step' => 100, 'offset' => 0 ],
		];
	}

	/**
	 * Registers the setting so the General tab can save it.
	 *
	 * Admin only: the front never writes settings, it only reads this one.
	 */
	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_init', static function (): void {
			register_setting( 'dze_price_options', self::OPTION, [
				'type'              => 'string',
				'sanitize_callback' => static function ( $v ): string {
					$v = is_string( $v ) ? $v : 'off';
					return isset( self::endings()[ $v ] ) ? $v : 'off';
				},
				// Autoloaded ON PURPOSE, against the usual rule: the sale-price
				// filter reads it on the shop front, so a non-autoloaded row
				// would cost an extra query on every page. It is a 5-character
				// string.
				'autoload'          => true,
				'default'           => 'off',
			] );
		} );
	}

	/** The chosen ending id. */
	public static function mode(): string {
		static $mode = null;
		if ( null === $mode ) {
			$m    = (string) get_option( self::OPTION, 'off' );
			$mode = isset( self::endings()[ $m ] ) ? $m : 'off';
		}
		return $mode;
	}

	public static function enabled(): bool {
		return 'off' !== self::mode();
	}

	/** Shop decimals, defaulting to 2 when WooCommerce is not loaded. */
	private static function decimals(): int {
		return (int) ( function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 );
	}

	/**
	 * The plain arithmetic rounding — what every caller used before, and what
	 * they still get when charm rounding is off.
	 */
	public static function plain( float $price ): float {
		return round( $price, self::decimals() );
	}

	/**
	 * @param float  $price     The computed price.
	 * @param string $direction 'down' for a sale price, 'up' for a selling price.
	 */
	public static function charm( float $price, string $direction = 'down' ): float {
		$plain = self::plain( $price );
		$mode  = self::mode();
		if ( 'off' === $mode || $plain <= 0 ) {
			return $plain;
		}
		// A shop priced in whole units (JPY and friends) has no cents to land
		// on: only the whole-unit ending means anything there.
		if ( self::decimals() < 2 && 'whole' !== $mode ) {
			return $plain;
		}
		$rung   = self::endings()[ $mode ];
		$step   = (int) $rung['step'];
		$offset = (int) $rung['offset'];

		$cents = (int) round( $plain * 100 );
		$out   = ( (int) floor( $cents / $step ) ) * $step + $offset;
		if ( 'up' === $direction ) {
			if ( $out < $cents ) {
				$out += $step;
			}
		} elseif ( $out > $cents ) {
			$out -= $step;
		}
		// Below the first rung there is nothing to land on without going
		// negative: a 0.50 item is left alone rather than turned into 0.90.
		return $out > 0 ? round( $out / 100, 2 ) : $plain;
	}

	/** Example line for the settings screen, computed from the live setting. */
	public static function preview(): string {
		if ( ! self::enabled() ) {
			return '';
		}
		return sprintf(
			/* translators: 1: sale price example, 2: selling price example */
			__( 'A sale price of 29.67 becomes %1$s (rounded down). A selling price of 24.96 computed from a cost becomes %2$s (rounded up).', 'dazont-ecom' ),
			number_format_i18n( self::charm( 29.67, 'down' ), 2 ),
			number_format_i18n( self::charm( 24.96, 'up' ), 2 )
		);
	}
}
