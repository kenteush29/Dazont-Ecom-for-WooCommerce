<?php
/**
 * The Bundle and Wholesale codes are known to WooCommerce whenever it asks.
 *
 * Run before every release:  php tools/test-bulk-coupon-known.php dazont-ecom
 *
 * « I'm trying to order 3 items from your site and it won't allow me to check
 * out. Keeps saying the bundle code isn't valid […] it won't allow me to remove
 * it » (a Kula customer, 07/11/2026). FunnelKit's checkout form checks the
 * cart's codes (`woocommerce_check_cart_items`) before the totals are added
 * up, and the code only existed once they had been: « Sorry, it seems the
 * coupon "dze_bundle" is invalid - it has now been removed from your order »,
 * then put back by the totals, refused again at the next step. Reproduced on
 * Kula with 4.518.4.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

$GLOBALS['hooks'] = [];
function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function add_action( $h, $cb = null, $p = 10 ) { $GLOBALS['hooks'][] = $h; }
function add_filter( $h, $cb = null, $p = 10 ) { $GLOBALS['hooks'][] = $h; }
function add_shortcode() {} function do_action() {}
function did_action( $h ) { return $GLOBALS['did'][ $h ] ?? 1; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wc_get_price_decimals() { return 2; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function apply_filters( $tag, $value = null, ...$rest ) { return $value; }
function WC() { return $GLOBALS['wc']; }

class WC_Product {
	private int $id;
	private float $price;
	public function __construct( int $id, float $price ) { $this->id = $id; $this->price = $price; }
	public function get_id() { return $this->id; }
	public function get_parent_id() { return 0; }
	public function get_price() { return $this->price; }
}
class WC_Cart {
	public array $applied_coupons = [];
	public array $items;
	public function __construct( array $items, array $codes = [] ) { $this->items = $items; $this->applied_coupons = $codes; }
	public function get_cart() { return $this->items; }
}
class DZE_Wpml {
	public static function is_active() { return false; }
	public static function current_language() { return ''; }
}

require "$dir/includes/class-discounts.php";

$bundle_rule = [ 'r2' => [ 'id' => 'r2', 'title' => 'Bundle discount', 'type' => 'bulk', 'enabled' => true, 'percent' => 10, 'scope' => 'all', 'threshold' => 2 ] ];
$GLOBALS['opts'] = [ 'dze_discount_rules' => $bundle_rule ];

$engine  = DZE_Discounts::instance();
$amounts = new ReflectionProperty( $engine, 'coupon_amounts' );
$amounts->setAccessible( true );
$active  = new ReflectionProperty( $engine, 'active' );
$active->setAccessible( true );
$fresh_request = static function () use ( $engine, $amounts, $active ): void {
	$amounts->setValue( $engine, [] ); // nothing worked out yet in this request
	$active->setValue( $engine, null );
};
$pen = static fn( int $qty ): array => [ 'k0' => [ 'data' => new WC_Product( 988350574, 30.90 ), 'quantity' => $qty ] ];

$wrong = 0;
$say   = static function ( bool $ok, string $what ) use ( &$wrong ): void {
	if ( ! $ok ) {
		$wrong++;
	}
	echo '  ', $ok ? 'ok    ' : 'WRONG ', $what, "\n";
};

// 1. The customer's case: three pens, the code in the session, no totals pass yet.
$fresh_request();
$GLOBALS['wc'] = (object) [ 'cart' => new WC_Cart( $pen( 3 ), [ 'dze_bundle' ] ) ];
$data = $engine->virtual_coupon_data( false, 'dze_bundle' );
$say( is_array( $data ) && abs( (float) $data['amount'] - 9.27 ) < 0.001, 'asked before any totals pass, Bundle is known and worth 9.27 (3 × 30.90 at 10 %) — got ' . var_export( is_array( $data ) ? $data['amount'] : $data, true ) );

// 2. Same, through the check WooCommerce and FunnelKit run before the totals.
$fresh_request();
$GLOBALS['wc'] = (object) [ 'cart' => new WC_Cart( $pen( 3 ), [ 'dze_bundle' ] ) ];
$engine->check_cart_codes();
$say( in_array( 'dze_bundle', $GLOBALS['wc']->cart->applied_coupons, true ), 'the check before the totals keeps the code the cart earns' );

// 3. A code the cart no longer earns (one pen left): taken out before WooCommerce sees it.
$fresh_request();
$GLOBALS['wc'] = (object) [ 'cart' => new WC_Cart( $pen( 1 ), [ 'dze_bundle' ] ) ];
$engine->check_cart_codes();
$say( ! in_array( 'dze_bundle', $GLOBALS['wc']->cart->applied_coupons, true ), 'a code the cart no longer earns is taken out before WooCommerce checks it' );
$data = $engine->virtual_coupon_data( false, 'dze_bundle' );
$say( is_array( $data ) && 0.0 === (float) $data['amount'], 'and if asked anyway it answers « nothing off », never « no such code »' );

// 4. Every totals pass, not only the first: three pens, then one, in the same request.
$fresh_request();
$cart = new WC_Cart( $pen( 3 ) );
$GLOBALS['wc'] = (object) [ 'cart' => $cart ];
$GLOBALS['did']['woocommerce_before_calculate_totals'] = 1;
$engine->prepare_cart_coupons( $cart );
$cart->items = $pen( 1 );
$GLOBALS['did']['woocommerce_before_calculate_totals'] = 2;
$engine->prepare_cart_coupons( $cart );
$got = $amounts->getValue( $engine );
$say( 0.0 === (float) ( $got['dze_bundle'] ?? -1 ) && ! in_array( 'dze_bundle', $cart->applied_coupons, true ), 'the second totals pass of a request recounts the cart (one pen: no Bundle)' );
unset( $GLOBALS['did']['woocommerce_before_calculate_totals'] );

// 5. Someone else's code is none of our business.
$say( false === $engine->virtual_coupon_data( false, 'welcome10' ), 'a code that is not ours is left to WooCommerce' );

// 6. No bulk offer running any more: the code left in a session is still known, then dropped.
$GLOBALS['opts'] = [ 'dze_discount_rules' => [] ];
$fresh_request();
$GLOBALS['hooks'] = [];
$engine->register_engine();
$say( in_array( 'woocommerce_get_shop_coupon_data', $GLOBALS['hooks'], true ) && in_array( 'woocommerce_check_cart_items', $GLOBALS['hooks'], true ), 'with no bulk offer running, our two codes still have their hooks' );
$GLOBALS['wc'] = (object) [ 'cart' => new WC_Cart( $pen( 3 ), [ 'dze_bundle' ] ) ];
$engine->check_cart_codes();
$say( ! in_array( 'dze_bundle', $GLOBALS['wc']->cart->applied_coupons, true ), 'and a code left in a session after the offer ended goes without a word' );

// 7. Before wp_loaded the cart is not read (WooCommerce would call it doing it wrong).
$GLOBALS['opts'] = [ 'dze_discount_rules' => $bundle_rule ];
$fresh_request();
$GLOBALS['did']['wp_loaded'] = 0;
$data = $engine->virtual_coupon_data( false, 'dze_bundle' );
$say( is_array( $data ) && 0.0 === (float) $data['amount'], 'before wp_loaded: known, nothing off, cart untouched' );

echo $wrong ? "  $wrong WRONG\n" : "  all good\n";
exit( $wrong ? 1 : 0 );
