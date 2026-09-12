<?php
/**
 * The guardrails on image spending, and the bill said BEFORE the press.
 *
 * Run before every release:  php tools/test-spend.php dazont-ecom
 *
 * "J'ai dépensé hier 40$ en génération d'images. Ce n'est pas normal que
 * autant ait été dépensé. Il faut une limite par post, d'appel par heure. 10
 * semble bien." — and, the next morning: "sur fal j'ai vu 24 images générées
 * pour le même produit le fsb patch. Ca m'étonnerait que ce soit ma colab qui
 * en ait fait autant."
 *
 * Twenty-four is what the bulk screen asks for on its own: three prompt rows
 * at four attempts each is twelve images per product, run twice. So there are
 * two halves to this, and this gate holds both:
 *
 *   1. A CEILING that stops a run that has gone round in circles — counted on
 *      the ATTEMPT, per clock hour, per product and for the whole shop, and
 *      asked at the ONE funnel every image request passes through.
 *   2. A PRESS THAT SAYS WHAT IT IS ABOUT TO SPEND. Every figure was already
 *      on the bulk screen — the rows, the attempts, the ticked products, the
 *      price per image — and none of them had ever been multiplied together.
 *      The button read "Generate (30)", which is a count of products and reads
 *      like a count of the work.
 *
 * The monthly budget was no guard at all here: `over_budget()` is `$cap > 0`,
 * so a shop that never set one had nothing in its way.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'DZE_VERSION', 'test' );
define( 'DZE_URL', 'http://kula.test/wp-content/plugins/dazont-ecom/' );
define( 'DZE_DIR', __DIR__ . '/../' . $dir . '/' );
define( 'DZE_FILE', DZE_DIR . 'dazont-ecom.php' );

function __( $s, $d = '' ) { return $s; }
function _n( $o, $m, $n, $d = '' ) { return 1 === (int) $n ? $o : $m; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function apply_filters( $t, $v = null, ...$a ) { return $v; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function current_time( $t = '', $g = 0 ) { return 'timestamp' === $t ? time() : gmdate( 'Y-m-d H:i:s' ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }

// REAL TRANSIENTS. Stubbed to `false`/no-op — as the other harnesses stub them
// — every counter reads nought for ever and the ceiling can never be reached:
// the gate would go green on a guard that does nothing, which is the exact
// failure it exists to catch.
$GLOBALS['tr'] = [];
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['tr'][ $k ] = $v; $GLOBALS['ttl'][ $k ] = (int) $ttl; return true; }
function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); return true; }

$GLOBALS['opts'] = [];
$GLOBALS['mai']  = [];
class DZE_Marketing_Ai {
	const MENU_SLUG = 'dazont-ecom-ai';
	public static function get_settings() { return $GLOBALS['mai']; }
	public static function api_key() { return 'k'; }
}

require __DIR__ . '/../' . $dir . '/includes/class-ai-usage.php';

$ran = 0; $fails = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok   %s\n", $what ); return; }
	$fails++;
	printf( "  WRONG %s\n       got  %s\n       want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}
function reset_counters(): void { $GLOBALS['tr'] = []; $GLOBALS['ttl'] = []; }

echo "\nThe ceilings the shop ships with\n";
$GLOBALS['mai'] = [];
ok( 'ten images of one product an hour', DZE_Ai_Usage::fal_post_cap(), 10 );
ok( 'sixty for the whole shop',          DZE_Ai_Usage::fal_hour_cap(), 60 );
$GLOBALS['mai'] = [ 'fal_cap_post' => 3, 'fal_cap_hour' => 5 ];
ok( 'and the shop can say otherwise',    DZE_Ai_Usage::fal_post_cap(), 3 );
// A ceiling set to nothing is OFF, not a ceiling of zero: a shop that typed 0
// to mean "no limit" would otherwise be unable to make a single photograph.
$GLOBALS['mai'] = [ 'fal_cap_post' => 0, 'fal_cap_hour' => 0 ];
reset_counters();
for ( $i = 0; $i < 50; $i++ ) { DZE_Ai_Usage::fal_attempt( 7 ); }
ok( 'nought means no ceiling, not a ceiling of nought', DZE_Ai_Usage::fal_blocked( 7 ), '' );

echo "\nThe per-product ceiling — the fsb patch, twenty-four times\n";
$GLOBALS['mai'] = [ 'fal_cap_post' => 10, 'fal_cap_hour' => 60 ];
reset_counters();
$made = 0;
for ( $i = 0; $i < 24; $i++ ) {
	if ( '' !== DZE_Ai_Usage::fal_blocked( 7 ) ) { break; }
	DZE_Ai_Usage::fal_attempt( 7 );
	$made++;
}
ok( 'a run asking twenty-four times gets ten', $made, 10 );
ok( 'and the eleventh is refused in words',
	false !== strpos( DZE_Ai_Usage::fal_blocked( 7 ), 'already had 10 images' ), true );
// The ceiling is on ONE product: the next one starts from nothing. A ceiling
// that spilled between products would stop an ordinary bulk run dead.
ok( 'another product is untouched by it', DZE_Ai_Usage::fal_blocked( 8 ), '' );
ok( 'and the shop-wide count did move',   DZE_Ai_Usage::fal_used( 8 )['hour'], 10 );

echo "\nThe shop-wide ceiling\n";
$GLOBALS['mai'] = [ 'fal_cap_post' => 10, 'fal_cap_hour' => 25 ];
reset_counters();
$made = 0;
for ( $p = 7; $p < 20; $p++ ) {
	for ( $i = 0; $i < 10; $i++ ) {
		if ( '' !== DZE_Ai_Usage::fal_blocked( $p ) ) { break 2; }
		DZE_Ai_Usage::fal_attempt( $p );
		$made++;
	}
}
ok( 'a loop over products stops at the hour ceiling', $made, 25 );
ok( 'and it says which ceiling stopped it',
	false !== strpos( DZE_Ai_Usage::fal_blocked( 99 ), 'made 25 images in the past hour' ), true );

echo "\nCounted on the ATTEMPT, not on what came back\n";
// A run failing in a loop reaches fal exactly as often as one succeeding. If
// only the successes were counted, the broken run — the expensive one — would
// never be stopped.
$GLOBALS['mai'] = [ 'fal_cap_post' => 10, 'fal_cap_hour' => 60 ];
reset_counters();
DZE_Ai_Usage::fal_attempt( 7 );
ok( 'one attempt, one counted for the product', DZE_Ai_Usage::fal_used( 7 )['post'], 1 );
ok( 'and one for the shop',                     DZE_Ai_Usage::fal_used( 7 )['hour'], 1 );
ok( 'the counter outlives the window it counts',
	( $GLOBALS['ttl'][ 'dze_fal_h_' . gmdate( 'YmdH' ) ] ?? 0 ) >= HOUR_IN_SECONDS, true );

echo "\nThe ceiling is asked at the ONE funnel every request passes\n";
// Six places make an image and all six call fal_generate(). Asking the ceiling
// anywhere else is a list somebody has to keep, and the one forgotten is the
// bug.
$src = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content.php' );
$fn  = strstr( $src, 'function fal_generate(' );
$fn  = false === $fn ? '' : substr( $fn, 0, 2000 );
ok( 'fal_generate asks before it sends',   false !== strpos( $fn, 'fal_blocked' ), true );
ok( 'and counts the attempt before it sends', false !== strpos( $fn, 'fal_attempt' ), true );
ok( 'it is told WHICH product it is for',  false !== strpos( $fn, 'int $pid' ), true );
// THE PRODUCT LANE NAMES ITS PRODUCT. The per-product ceiling counts nothing
// for a call that did not say which product it is for, and the whole of the
// product image work — the toolbox, the bulk screen, every automatic pass —
// goes through class-content-ajax.php. The three other callers (a promotion's
// hero, a discount banner, the image lab) have no product to name and are
// stopped by the shop-wide ceiling alone, which is the right answer for them.
$lane  = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content-ajax.php' );
preg_match_all( '/fal_generate\((.*)\);/', $lane, $m );
$calls = (array) ( $m[1] ?? [] );
$blind = 0;
foreach ( $calls as $args ) {
	// The product is NAMED in the call — wherever in the argument list it
	// sits. Pinned to the last position, this check went red the day the
	// trace gained what the photographs are, which is a true statement about
	// argument order and nothing at all about the ceiling it exists for.
	if ( ! preg_match( '/,\s*\$pid\s*(?:,|$)/', trim( $args ) ) ) { $blind++; }
}
ok( 'the product lane really does make images', count( $calls ) >= 2, true );
ok( 'and every one of its calls names its product', $blind, 0 );
// And nothing reaches the provider around it: one endpoint, one funnel, one
// place the ceiling has to be asked.
ok( 'and the provider is reached from exactly one place',
	substr_count( $src, 'wp_remote_post( self::FAL_ENDPOINT' ), 1 );

echo "\nWhat the press is about to spend, said before the press\n";
// The figures the two screens multiply together are handed over by PHP. A
// screen missing one of them silently draws nothing, which is the state this
// shipped in for its whole life.
$js = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content-bulk.js' );
ok( 'the bulk screen has somewhere to say it', false !== strpos( $src, 'id="dze-cb-spend"' ), true );
ok( 'and something that writes into it',       false !== strpos( $js, "$('#dze-cb-spend')" ), true );
$tool = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content.js' );
ok( 'the toolbox has one too',                 false !== strpos( $tool, 'dze-cx-willspend' ), true );
// The price and the ceiling travel to BOTH screens: named for one and not the
// other, the second says "360 photographs" and never what they cost.
foreach ( [ 'imageCost', 'falPostCap' ] as $key ) {
	ok( 'both screens are handed ' . $key, substr_count( $src, "'" . $key . "'" ) >= 2, true );
}
foreach ( [ 'willCost', 'willMake', 'overCap' ] as $key ) {
	ok( 'both screens are handed the word ' . $key, substr_count( $src, "'" . $key . "'" ) >= 2, true );
	ok( 'and both read it: ' . $key, ( false !== strpos( $js, 'i18n.' . $key ) ) && ( false !== strpos( $tool, 'i18n.' . $key ) ), true );
}
// AND THE CEILING IS NAMED WHERE IT WOULD BE HIT. Twelve photographs a product
// on a ceiling of ten is a run that stops two short on every line; saying so
// before the press is the difference between a guardrail and a refusal
// halfway through.
ok( 'the bulk screen warns when the order is over the ceiling',
	false !== strpos( $js, 'i18n.overCap' ), true );
ok( 'and so does the toolbox',  false !== strpos( $tool, 'i18n.overCap' ), true );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
