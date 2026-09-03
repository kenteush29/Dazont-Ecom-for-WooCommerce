<?php
/**
 * Money is only money in ONE currency.
 *
 * Run before every release:  php tools/test-money.php dazont-ecom
 *
 * The sales tables keep every order line in the currency it was PAID in, and
 * carry no currency column — the currency is on the order. The sourcing
 * report summed that column as it stood: 100 EUR + 100 PLN + 100 USD came out
 * as "300", a figure the shop reads, sorts by and believes. Every figure in
 * money is brought back to the shop's own currency here, and a currency whose
 * rate cannot be read is LEFT OUT and said, never counted at one to one.
 *
 * Quantities never come through: four sold is four sold in any currency.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function absint( $n ) { return abs( (int) $n ); }
function get_woocommerce_currency() { return $GLOBALS['base'] ?? 'USD'; }
function wc_price( $n, $a = [] ) { return '$' . number_format( (float) $n, 2 ); }
function apply_filters( $t, $v = null, ...$r ) {
	return ( 'dze_currency_rate' === $t && isset( $GLOBALS['filtered'][ (string) ( $r[0] ?? '' ) ] ) )
		? $GLOBALS['filtered'][ (string) $r[0] ]
		: $v;
}
$GLOBALS['opts'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function get_transient( $k ) { return $GLOBALS['trans'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['trans'][ $k ] = $v; return true; }

/**
 * The order lines, as WooCommerce keeps them: an amount, and an order whose
 * currency is somewhere else entirely.
 */
class DZE_Money_Test_Wpdb {
	public $prefix = 'wp_';
	public $postmeta = 'wp_postmeta';
	public $queries = [];
	public function prepare( $q, ...$a ) { return [ $q, $a ]; }
	public function get_var( $q ) {
		$like = is_array( $q ) ? (string) ( $q[1][0] ?? '' ) : '';
		if ( 'wp_wc_orders' === $like ) { return $GLOBALS['hpos'] ? 'wp_wc_orders' : ''; }
		if ( 'wp_wc_order_product_lookup' === $like ) { return $GLOBALS['lookup'] ? 'wp_wc_order_product_lookup' : ''; }
		return '';
	}
	public function get_results( $q, $mode = null ) {
		$sql = is_array( $q ) ? (string) $q[0] : (string) $q;
		$this->queries[] = $sql;
		$rows = [];
		foreach ( $GLOBALS['lines'] as $one ) {
			if ( false !== strpos( $sql, 'l.product_id IN' ) && false === strpos( $sql, (string) $one['pid'] ) ) { continue; }
			$key = $one['pid'] . '|' . $one['cur'];
			$rows[ $key ] = [
				'pid' => $one['pid'],
				'cur' => strtoupper( (string) $one['cur'] ),
				'qty' => ( $rows[ $key ]['qty'] ?? 0 ) + $one['qty'],
				'rev' => ( $rows[ $key ]['rev'] ?? 0 ) + $one['rev'],
			];
		}
		return array_values( $rows );
	}
}
$GLOBALS['wpdb']   = new DZE_Money_Test_Wpdb();
$GLOBALS['hpos']   = true;
$GLOBALS['lookup'] = true;
$GLOBALS['base']   = 'USD';
$GLOBALS['lines']  = [];
$GLOBALS['trans']  = [];
$GLOBALS['filtered'] = [];

require __DIR__ . '/../' . $dir . '/includes/class-money.php';
require __DIR__ . '/../' . $dir . '/includes/class-sales.php';

$fails = 0;
$ran   = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok    %s\n", $what ); return; }
	$fails++;
	printf( "  FAIL  %s\n          got  %s\n          want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}
/** The rate cache is per request in real life; here each shop is a new one. */
function fresh(): void {
	$r = new ReflectionMethod( 'DZE_Money', 'rate' );
	// Static caches inside a method cannot be reset from outside, so the rate
	// tables are given distinct currencies per section instead.
	$GLOBALS['trans'] = [];
}

echo "The shop's own currency\n";
ok( 'is what WooCommerce says',         DZE_Money::base(), 'USD' );
ok( 'and its own rate is one',          DZE_Money::rate( 'USD' ), 1.0 );
ok( 'unknown until the shop says',      DZE_Money::rate( 'JPY' ), null );

echo "The rates come from the shop, never from us\n";
$GLOBALS['opts']['_wcml_settings'] = [ 'currency_options' => [
	// What is STORED is what one unit of that currency is worth in the shop's
	// own — 1 EUR = 1.10 USD, 1 PLN = 0.25 USD. 4.285 read it the other way
	// round, on the strength of what these plugins' own screens say, and the
	// shop's revenue column answered within the hour: $289,470 on a product
	// sold twice. This fixture is the direction the shop actually has.
	'EUR' => [ 'rate' => 1.1 ],
	'PLN' => [ 'rate' => 0.25 ],
] ];
ok( 'WooCommerce Multilingual is read', DZE_Money::rate( 'EUR' ), 1.1 );
ok( 'and it is taken as it stands',     DZE_Money::rate( 'PLN' ), 0.25 );
$GLOBALS['opts']['woocs_currency_data'] = [ 'GBP' => 1.3 ];
ok( 'so is a plain map',                DZE_Money::rate( 'GBP' ), 1.3 );
$GLOBALS['filtered']['SEK'] = 0.09;
ok( 'and a shop can answer by filter',  DZE_Money::rate( 'SEK' ), 0.09 );
ok( 'converting is that rate',          round( (float) DZE_Money::to_base( 200.0, 'EUR' ), 2 ), 220.00 );
ok( 'and a zloty amount is smaller',    round( (float) DZE_Money::to_base( 400.0, 'PLN' ), 2 ), 100.00 );
ok( 'and unknown stays unknown',        DZE_Money::to_base( 200.0, 'CHF' ), null );

echo "What a product brought in, across currencies\n";
// The same product, sold in three markets. 100 USD + 100 EUR + 100 PLN is
// not 300: it is 100 + 110 + 25.
$GLOBALS['lines'] = [
	[ 'pid' => 7, 'cur' => 'USD', 'qty' => 1, 'rev' => 100 ],
	[ 'pid' => 7, 'cur' => 'EUR', 'qty' => 1, 'rev' => 100 ],
	[ 'pid' => 7, 'cur' => 'PLN', 'qty' => 2, 'rev' => 100 ],
	[ 'pid' => 8, 'cur' => '',    'qty' => 3, 'rev' => 50 ],
];
$got = DZE_Sales::revenue();
// 100 USD + 100 EUR + 100 PLN = 100 + 110 + 25 = 235. Never 300.
ok( 'each currency at its own rate',    round( (float) $got['rev'][7], 2 ), 235.00 );
ok( 'the quantity is never converted',  $got['qty'][7] ?? 0, 4 );
ok( 'no currency on the order = ours',  round( (float) $got['rev'][8], 2 ), 50.00 );
ok( 'and nothing was left out',         $got['missing'], [] );

echo "A currency the shop cannot price\n";
$GLOBALS['lines'][] = [ 'pid' => 7, 'cur' => 'CHF', 'qty' => 5, 'rev' => 900 ];
$got = DZE_Sales::revenue();
ok( 'it is LEFT OUT, not counted as one', round( (float) $got['rev'][7], 2 ), 235.00 );
ok( 'the sale itself is still counted',   $got['qty'][7] ?? 0, 9 );
ok( 'and the screen is told which',       $got['missing'], [ 'CHF' ] );

echo "Both shapes of the orders table\n";
$got = DZE_Sales::revenue( [ 7 ] );
ok( 'HPOS reads the currency column',   false !== strpos( end( $GLOBALS['wpdb']->queries ), 'o.currency' ), true );
ok( 'and asks only for what was asked', false !== strpos( end( $GLOBALS['wpdb']->queries ), 'l.product_id IN ( 7 )' ), true );
$GLOBALS['hpos'] = false;
DZE_Sales::revenue();
ok( 'an older shop reads the meta row',  false !== strpos( end( $GLOBALS['wpdb']->queries ), '_order_currency' ), true );
$GLOBALS['hpos'] = true;

echo "Money earned in another era is not this year's money\n";
// The screenshot: "$19,388.43" beside a product sold 60 times at $15.90, and
// "-$2,258.23" on another. This shop kept its books in roubles before it kept
// them in dollars; the sales table remembers the amount and not what a rouble
// was worth that year, so those lines converted at today's rate are numbers
// from nowhere — and a refund on the dollar side of a rouble-era product
// turns the total NEGATIVE. Revenue reads a horizon.
$asked = null;
$GLOBALS['lines'] = [ [ 'pid' => 7, 'cur' => 'USD', 'qty' => 60, 'rev' => 954 ] ];
DZE_Sales::revenue();
$sql = end( $GLOBALS['wpdb']->queries );
ok( 'the query is bounded in time',     false !== strpos( $sql, 'date_created >=' ), true );
ok( 'to the last two years',            DZE_Sales::MONTHS, 24 );
// The horizon is a WHERE clause, so a shop asking about one product still
// gets both conditions and not one instead of the other.
$GLOBALS['wpdb']->queries = [];
DZE_Sales::revenue( [ 7 ] );
$sql = end( $GLOBALS['wpdb']->queries );
ok( 'and it still narrows to the product',
	false !== strpos( $sql, 'l.product_id IN ( 7 )' ) && false !== strpos( $sql, 'date_created >=' ), true );

echo "And when there is nothing to read\n";
$GLOBALS['lookup'] = false;
ok( 'no lookup table is no figures',    DZE_Sales::revenue(), [ 'rev' => [], 'qty' => [], 'missing' => [], 'by' => [] ] );
$GLOBALS['lookup'] = true;

echo "The sum shows its workings\n";
// "Toujours des montants irreels." A revenue column that cannot be taken apart
// is a revenue column that gets argued about, and this shop has now spent
// three evenings on figures nobody could check. What was read in each
// currency, at what rate, and what it became, kept as the sum is made.
$GLOBALS['lines'] = [
	[ 'pid' => 7, 'cur' => 'USD', 'qty' => 1, 'rev' => 100 ],
	[ 'pid' => 7, 'cur' => 'EUR', 'qty' => 1, 'rev' => 100 ],
	[ 'pid' => 8, 'cur' => 'EUR', 'qty' => 1, 'rev' => 50 ],
];
$by = DZE_Sales::revenue()['by'] ?? [];
ok( 'every currency is named',          array_keys( $by ), [ 'USD', 'EUR' ] );
ok( 'with what was read in it',         $by['EUR']['raw'] ?? 0, 150.0 );
ok( 'the rate it was read at',          $by['EUR']['rate'] ?? 0, 1.1 );
ok( 'and what that came to',            round( (float) ( $by['EUR']['base'] ?? 0 ), 2 ), 165.00 );
ok( 'the shop\'s own is not converted',  $by['USD']['base'] ?? 0, 100.0 );
ok( 'and the lines are counted',        $by['EUR']['lines'] ?? 0, 2 );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
