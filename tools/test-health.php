<?php
/**
 * The Health screen: what it shows, and WHEN it goes and looks.
 *
 * Run before every release:  php tools/test-health.php dazont-ecom
 *
 * "Health — cet onglet n'était pas à jour seulement après que j'appuye sur
 * Check now. Ça devrait être automatique."
 *
 * The background look is WEEKLY, so the tab greeted the shop with a reading
 * from last Tuesday and waited to be told to ask again. Opening the screen IS
 * the question — so opening it is what asks. Two things have to hold at once,
 * and they pull in opposite directions:
 *
 *  - the asking never happens during the page's own request. It reaches four
 *    providers over HTTP, and a shop whose admin waits on that is a shop that
 *    starts returning 504s. The browser asks, and says it is asking.
 *  - a refresh that found nothing new does not throw the screen away. The
 *    reload is the server redrawing — there is one renderer — but only when
 *    the answer actually moved.
 *
 * This screen had no gate at all, which is why a week-old reading could stand
 * there looking current for months.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'DZE_VERSION', 'test' );
define( 'DZE_URL', 'http://kula.test/wp-content/plugins/dazont-ecom/' );
define( 'DZE_DIR', __DIR__ . '/../' . $dir . '/' );
define( 'DZE_FILE', DZE_DIR . 'dazont-ecom.php' );

function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return 1 === (int) $n ? $a : $b; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_js( $s ) { return addslashes( (string) $s ); }
function checked( $a, $b = true, $e = true ) { return (string) $a === (string) $b ? " checked='checked'" : ''; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function apply_filters( $t, $v = null, ...$a ) { return $v; }
function is_admin() { return true; }
function current_user_can( ...$a ) { return true; }
function admin_url( $p = '' ) { return 'http://shop.test/wp-admin/' . $p; }
function add_query_arg( $args, $url = '', $third = null ) {
	if ( ! is_array( $args ) ) { $args = [ (string) $args => $url ]; $url = (string) $third; }
	return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args );
}
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function wp_next_scheduled( $h ) { return time() + 3600; }
function wp_schedule_event( ...$a ) {}
function wp_clear_scheduled_hook( ...$a ) {}
function plugin_basename( $f ) { return 'dazont-ecom/dazont-ecom.php'; }
function get_site_option( $k, $d = false ) { return $GLOBALS['site_opts'][ $k ] ?? $d; }
function update_site_option( $k, $v ) { $GLOBALS['site_opts'][ $k ] = $v; return true; }
function human_time_diff( $from, $to = 0 ) { return max( 1, (int) round( ( ( $to ?: time() ) - $from ) / 60 ) ) . ' mins'; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?? time() ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }

$GLOBALS['opts'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['trans'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['trans'][ $k ] = $v; return true; }

/** Which install this is — a line the screen prints before anything else. */
class DZE_Site {
	public static function render_line() { echo '<p class="dze-site">This shop</p>'; }
}

require __DIR__ . '/../' . $dir . '/includes/class-health.php';

$fails = 0;
$ran   = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok    %s\n", $what ); return; }
	$fails++;
	printf( "  FAIL  %s\n          got  %s\n          want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}

echo "\nOPENING THE SCREEN IS THE QUESTION\n";
// Never looked at all: there is nothing to show, so it asks.
ok( 'a shop that was never checked asks',
	DZE_Health::stale( [], 1000 ), true );
// The reading the shop was greeted with: a week old, and standing there as if
// it were the state of things right now.
ok( 'a week-old reading asks again',
	DZE_Health::stale( [ 'at' => 1000 ], 1000 + WEEK_IN_SECONDS ), true );
// And one from ten minutes ago does NOT: coming back to the tab twice in a
// morning must not re-ask four providers each time.
ok( 'a reading from ten minutes ago does not',
	DZE_Health::stale( [ 'at' => 1000 ], 1600 ), false );
ok( 'the line is drawn at the hour',
	DZE_Health::stale( [ 'at' => 1000 ], 1000 + HOUR_IN_SECONDS + 1 ), true );
ok( 'and an hour exactly is still fresh',
	DZE_Health::stale( [ 'at' => 1000 ], 1000 + HOUR_IN_SECONDS ), false );

echo "\nA REFRESH THAT FOUND NOTHING NEW DOES NOT THROW THE SCREEN AWAY\n";
// The stamp is what a reader would SEE. A new timestamp on an identical
// reading is not news, and reloading the page for it is the screen jumping
// for nothing.
$dze_a = [ 'at' => 100, 'checks' => [
	'anthropic' => [ 'state' => 'ok',   'said' => 'answered' ],
	'fal'       => [ 'state' => 'down', 'said' => '401' ],
] ];
$dze_b = $dze_a;
$dze_b['at'] = 999999;
ok( 'the same reading an hour later is the same stamp',
	DZE_Health::stamp( $dze_a ), DZE_Health::stamp( $dze_b ) );
// A connection that has changed IS news.
$dze_c = $dze_a;
$dze_c['checks']['fal']['state'] = 'ok';
ok( 'a connection that changed is not',
	DZE_Health::stamp( $dze_a ) === DZE_Health::stamp( $dze_c ), false );
// And so is what the service SAID, even at the same state: "401" and "429"
// are the same colour and two different problems.
$dze_d = $dze_a;
$dze_d['checks']['fal']['said'] = '429';
ok( 'and neither is a different answer at the same state',
	DZE_Health::stamp( $dze_a ) === DZE_Health::stamp( $dze_d ), false );
ok( 'the order the checks come back in changes nothing',
	DZE_Health::stamp( $dze_a ),
	DZE_Health::stamp( [ 'checks' => array_reverse( $dze_a['checks'], true ) ] ) );

echo "\nTHE SCREEN SAYS WHICH IT IS, AND ASKS FROM THE BROWSER\n";
// DRAWN, not described: the marker the script reads is printed by the render,
// and a gate that called stale() alone would prove nothing about the page.
$GLOBALS['opts'][ DZE_Health::OPT_STATE ] = [ 'at' => time() - 2 * WEEK_IN_SECONDS, 'checks' => [] ];
ob_start(); DZE_Health::render(); $dze_old = (string) ob_get_clean();
ok( 'a stale screen marks itself stale',
	false !== strpos( $dze_old, 'data-stale="1"' ), true );
ok( 'and the browser is what goes and asks',
	false !== strpos( $dze_old, "'1' === $('#dze-health-when').attr('data-stale')" ), true );
ok( 'it says it is asking rather than sitting there',
	false !== strpos( $dze_old, 'Asking each service' ), true );
// IT IS THE SAME ACTION THE BUTTON RUNS. A second endpoint for "look again"
// is two answers to one question, and they drift.
ok( 'and it is the button\'s own action',
	substr_count( $dze_old, "call('dze_health_run'" ), 2 );
// A fresh screen does not ask: the reading in front of you is current.
$GLOBALS['opts'][ DZE_Health::OPT_STATE ] = [ 'at' => time() - 60, 'checks' => [] ];
ob_start(); DZE_Health::render(); $dze_new = (string) ob_get_clean();
ok( 'a fresh screen does not ask again',
	false !== strpos( $dze_new, 'data-stale="1"' ), false );
ok( 'and still offers to, by hand',
	false !== strpos( $dze_new, 'id="dze-health-run"' ), true );
// THE ASKING IS NEVER DONE WHILE THE PAGE IS BEING BUILT. Four providers over
// HTTP inside the render is how an admin page starts timing out.
ok( 'drawing the screen contacts nobody',
	empty( $GLOBALS['dze_health_ran'] ), true );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
