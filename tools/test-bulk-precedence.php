<?php
/**
 * The bulk order comes first; the bundle offer only where it does not apply.
 *
 * Run before every release:  php tools/test-bulk-precedence.php dazont-ecom
 *
 * « Il y a un bug qui créé des soldes énormes. La promo bulk order doit prendre
 * le dessus sur la promo 2 achetés le 2e à -10% » (03/10/2026). Both virtual
 * coupons were computed on the same lines and added up: six of one product
 * over the minimum got the 10 % wholesale tier AND the 10 % bundle offer.
 * This drives prepare_cart_coupons() through the real class.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function add_action() {} function add_filter() {} function add_shortcode() {} function do_action() {}
function did_action( $h ) { return 1; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wc_get_price_decimals() { return 2; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function apply_filters( $tag, $value = null, ...$rest ) { return $value; }

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
		'r2' => [ 'id' => 'r2', 'title' => 'Bundle discount', 'type' => 'bulk', 'enabled' => true, 'percent' => 10, 'scope' => 'all', 'threshold' => 2 ],
	],
];
$engine  = DZE_Discounts::instance();
$amounts = new ReflectionProperty( $engine, 'coupon_amounts' );
$amounts->setAccessible( true );

$wrong = 0;
$check = static function ( string $what, array $lines, float $bundle, float $wholesale ) use ( $engine, $amounts, &$wrong ): void {
	$items = [];
	foreach ( $lines as $i => [ $price, $qty ] ) {
		$items[ 'k' . $i ] = [ 'data' => new WC_Product( $i + 1, $price ), 'quantity' => $qty ];
	}
	$engine->prepare_cart_coupons( new WC_Cart( $items ) );
	$got = $amounts->getValue( $engine );
	$b   = (float) ( $got[ DZE_Discounts::COUPON_BUNDLE ] ?? 0 );
	$w   = (float) ( $got[ DZE_Discounts::COUPON_WHOLESALE ] ?? 0 );
	$ok  = abs( $b - $bundle ) < 0.001 && abs( $w - $wholesale ) < 0.001;
	if ( ! $ok ) {
		$wrong++;
	}
	printf( "  %s %s — bundle %.2f (expected %.2f), bulk order %.2f (expected %.2f)\n", $ok ? 'ok   ' : 'WRONG', $what, $b, $bundle, $w, $wholesale );
};

// Six of one product at $113.90 ($683.40, over the $500 minimum): the bulk order only.
$check( 'six of one product over the minimum: the bulk order, not both', [ [ 113.90, 6 ] ], 0, 68.34 );
// Two of one product, a small cart: the bulk order does not apply, the bundle does.
$check( 'two of one product under the minimum: the bundle offer', [ [ 30.00, 2 ] ], 6.00, 0 );
// A cart under the minimum keeps its bundle lines.
$check( 'two pairs under the minimum: both bundle lines', [ [ 30.00, 2 ], [ 50.00, 2 ] ], 16.00, 0 );

echo $wrong ? "  $wrong WRONG\n" : "  all good\n";
exit( $wrong ? 1 : 0 );
