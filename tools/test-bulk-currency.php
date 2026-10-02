<?php
/**
 * A wholesale minimum is a sum of money, in the shop's currency.
 *
 * Run before every release:  php tools/test-bulk-currency.php dazont-ecom
 *
 * Kula's bulk-order rule says "500 and 3 items". The cart is added up from
 * prices WCML has already converted, and "500" was compared with it as typed:
 * in yen a three-dollar cart passed, and every order of three things got the
 * wholesale tier — in zloty, kronor, pesos, every currency worth more units
 * than the dollar. This drives winning_bulk_order() through the real class,
 * with a cart in a currency worth 158 to the dollar and in the shop's own.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function add_action() {} function add_filter() {} function add_shortcode() {} function do_action() {}
function is_admin() { return false; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }

/** WCML's conversion of an amount typed in the shop's currency, at the test's rate. */
function apply_filters( $tag, $value = null, ...$rest ) {
	return 'wcml_raw_price_amount' === $tag ? $value * $GLOBALS['rate'] : $value;
}

class WC_Product {
	private int $id;
	private float $price;
	public function __construct( int $id, float $price ) { $this->id = $id; $this->price = $price; }
	public function get_id() { return $this->id; }
	public function get_parent_id() { return 0; }
	public function get_price() { return $this->price; }
}
class WC_Cart {
	private array $items;
	public function __construct( array $items ) { $this->items = $items; }
	public function get_cart() { return $this->items; }
}
class DZE_Wpml {
	public static function is_active() { return false; }
	public static function current_language() { return ''; }
}

require "$dir/includes/class-discounts.php";

$GLOBALS['opts'] = [
	'dze_discount_rules' => [
		'r1' => [ 'id' => 'r1', 'title' => 'Bulk discount', 'type' => 'bulk_order', 'enabled' => true, 'percent' => 10, 'scope' => 'all',
			'min_subtotal' => 500, 'min_qty' => 3, 'tiers' => [ [ 'qty' => 3, 'percent' => 5 ], [ 'qty' => 5, 'percent' => 10 ] ] ],
	],
];
$engine = DZE_Discounts::instance();
$win    = new ReflectionMethod( $engine, 'winning_bulk_order' );
$win->setAccessible( true );

/** Three of one product, priced in the CART's currency (already converted, as WCML serves it). */
function cart_of( float $unit_in_cart_currency, int $qty ): WC_Cart {
	return new WC_Cart( [ [ 'data' => new WC_Product( 1, $unit_in_cart_currency ), 'quantity' => $qty ] ] );
}

$wrong = 0;
$check = static function ( string $what, float $rate, float $unit_usd, int $qty, float $expected ) use ( $win, $engine, &$wrong ): void {
	$GLOBALS['rate'] = $rate;
	[ , $pct ] = $win->invoke( $engine, cart_of( $unit_usd * $rate, $qty ) );
	$ok = abs( $pct - $expected ) < 0.001;
	if ( ! $ok ) {
		$wrong++;
	}
	printf( "  %s %s — tier %s %%, expected %s %%\n", $ok ? 'ok   ' : 'WRONG', $what, $pct, $expected );
};

// In yen (158 to the dollar): the minimum is 79,000 yen, not 500.
$check( 'yen, 3 x $20 (a $60 cart) gets no wholesale tier', 158.0, 20, 3, 0 );
$check( 'yen, 3 x $200 (a $600 cart) gets the 3-item tier', 158.0, 200, 3, 5 );
$check( 'yen, 5 x $150 (a $750 cart) gets the 5-item tier', 158.0, 150, 5, 10 );
// In the shop's own currency nothing changes.
$check( 'dollars, 3 x $20 gets no wholesale tier', 1.0, 20, 3, 0 );
$check( 'dollars, 3 x $200 gets the 3-item tier', 1.0, 200, 3, 5 );
// A currency worth LESS than the dollar must not lock the tier away either.
$check( 'euros at 0.9, 3 x $200 (540 euros) gets the 3-item tier', 0.9, 200, 3, 5 );

echo $wrong ? "  $wrong WRONG\n" : "  all good\n";
