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
/**
 * The two readings the Logs page shows beside the connections. They belong to
 * DZE_Ai_Usage and are PRINTED here, never copied: what is asserted is that
 * the page asks for them, which is the whole point of a host.
 */
class DZE_Ai_Usage {
	public static function render_graph( $limit = 12 ) { echo '<div id="dze-usage-graph">spend</div>'; }
	public static function render_trace() { echo '<div id="dze-usage-trace">calls</div>'; }
}
class DZE_Restock { const MENU_SLUG = 'dazont-ecom'; }
class DZE_Marketing_Ai {
	const MENU_SLUG = 'dazont-ecom-ai';
	public static function tab_links() { return [ 'General' => 'http://shop.test/wp-admin/admin.php?page=dazont-ecom-ai&tab=general' ]; }
}
class DZE_Modules {
	public static function enabled( $id ) { return ! in_array( $id, (array) ( $GLOBALS['off'] ?? [] ), true ); }
}
$GLOBALS['menu'] = [];
function add_submenu_page( $parent, $t, $m, $cap, $slug, $cb = null ) {
	$GLOBALS['menu'][] = [ 'parent' => $parent, 'title' => $m, 'slug' => $slug ];
	return $slug;
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


echo "\nONE MENU ENTRY FOR THE LOGS, AND WORDPRESS'S OWN TABS\n";
// "Je veux un menu logs directement dispo sur le menu wordpress dans le
// plugin." Everything this plugin asked of somebody else lived on a SETTINGS
// page — which is how an image trace went unfound while every picture came
// back wrong.
$GLOBALS['menu'] = [];
DZE_Health::register_menu();
ok( 'there is one entry',               count( $GLOBALS['menu'] ), 1 );
ok( 'under the plugin\'s own menu',      $GLOBALS['menu'][0]['parent'], DZE_Restock::MENU_SLUG );
ok( 'and it is called Logs',            $GLOBALS['menu'][0]['title'], 'Logs' );

// THE TABS, and the one that is a module's.
ok( 'the calls and the spend are always there',
	array_slice( array_keys( DZE_Health::tabs() ), 0, 2 ), [ 'calls', 'spend' ] );
ok( 'the connections are there while that module is on',
	isset( DZE_Health::tabs()['health'] ), true );
$GLOBALS['off'] = [ 'health' ];
ok( 'and gone with it',                 isset( DZE_Health::tabs()['health'] ), false );
// A MODULE SWITCHED OFF MUST NOT TAKE ANOTHER'S FUNCTION WITH IT: the calls
// and the spend are the plugin's own accounting.
ok( 'but the page stays',               count( DZE_Health::tabs() ) >= 2, true );
$GLOBALS['menu'] = [];
DZE_Health::register_menu();
ok( 'and so does its entry',            count( $GLOBALS['menu'] ), 1 );
$GLOBALS['off'] = [];

// A TAB ASKED FOR THAT IS NOT THERE ANSWERS WITH ONE THAT IS, rather than an
// empty screen.
ok( 'an unknown tab falls back to the first',
	DZE_Health::tab_now( [ 'tab' => 'nonsense' ] ), 'calls' );
ok( 'and nothing asked for does too',   DZE_Health::tab_now( [] ), 'calls' );
ok( 'a real one is honoured',           DZE_Health::tab_now( [ 'tab' => 'spend' ] ), 'spend' );

// EACH BODY IS PRINTED BY WHOEVER OWNS THAT WORK — the page is a host, and a
// host that copied a body would be a second one to keep in step.
$GLOBALS['opts'][ DZE_Health::OPT_STATE ] = [ 'at' => time() - 60, 'checks' => [] ];
$_GET = [ 'tab' => 'calls' ];
ob_start(); DZE_Health::render_page(); $dze_calls = (string) ob_get_clean();
ok( 'the calls tab prints the trace',   false !== strpos( $dze_calls, 'id="dze-usage-trace"' ), true );
ok( 'and keeps the anchor old links point at',
	false !== strpos( $dze_calls, 'id="dze-ai-trace"' ), true );
$_GET = [ 'tab' => 'spend' ];
ob_start(); DZE_Health::render_page(); $dze_spend = (string) ob_get_clean();
ok( 'the spend tab prints the graph',   false !== strpos( $dze_spend, 'id="dze-usage-graph"' ), true );
ok( 'and not the calls beside it',      false !== strpos( $dze_spend, 'id="dze-usage-trace"' ), false );
$_GET = [ 'tab' => 'health' ];
ob_start(); DZE_Health::render_page(); $dze_conn = (string) ob_get_clean();
ok( 'the connections tab prints them',  false !== strpos( $dze_conn, 'id="dze-health-run"' ), true );
ok( 'every tab is a way to the others',
	substr_count( $dze_conn, 'page=dazont-ecom-logs&tab=' ), count( DZE_Health::tabs() ) );
ok( 'and the one you are on says so',   substr_count( $dze_conn, 'nav-tab-active' ), 1 );
$_GET = [];

// AN ADDRESS THAT USED TO LAND STILL LANDS. A bookmark, or a link in an email
// this plugin sent months ago, must not end on a page that no longer holds
// what it pointed at.
ok( 'the old settings tab goes to the new screen',
	false !== strpos( DZE_Health::moved( [ 'page' => 'dazont-ecom-ai', 'tab' => 'health' ] ), 'page=dazont-ecom-logs' ), true );
ok( 'and lands on the connections',
	false !== strpos( DZE_Health::moved( [ 'page' => 'dazont-ecom-ai', 'tab' => 'health' ] ), 'tab=health' ), true );
ok( 'another settings tab is left alone',
	DZE_Health::moved( [ 'page' => 'dazont-ecom-ai', 'tab' => 'general' ] ), '' );
ok( 'and so is every other page',       DZE_Health::moved( [ 'page' => 'edit.php' ] ), '' );

// THE LINK EVERY FAILURE CARRIES POINTS AT THE LOG, wherever the log now is.
ok( 'the log link goes to the Logs page',
	false !== strpos( DZE_Health::log_url(), 'page=dazont-ecom-logs' ), true );
// AND A MESSAGE THAT NAMES A SCREEN IS A WAY TO IT: the phrases handed to the
// browser are the ones the sentences are written with.
$dze_screens = DZE_Health::screen_links();
ok( 'the settings tabs are named as the messages write them',
	isset( $dze_screens['Settings → General'] ), true );
ok( 'and so is the Logs page',          isset( $dze_screens['Dazont Ecom → Logs'] ), true );
ok( 'which points at itself',
	false !== strpos( (string) $dze_screens['Dazont Ecom → Logs'], 'page=dazont-ecom-logs' ), true );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
