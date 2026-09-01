<?php
/**
 * A promotion in five markets is ONE promotion.
 *
 * Run before every release:  php tools/test-promo-i18n.php dazont-ecom
 *
 * The shop asked for its "Patriot Day Sale" in French and got back the 14
 * Juillet: another holiday, in another month, on a promotion that runs in
 * September. The model was given a NAME and no calendar, and filled the gap
 * from the market's own — encouraged by an instruction that asks for "the
 * line a shop in that market would write".
 *
 * Two things go with the ask now, and this proves both leave: the days the
 * promotion runs, and the rule that its occasion never changes. The call
 * itself cannot be run from here, so what is checked is what is SENT.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return $n > 1 ? $b : $a; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr__( $s, $d = '' ) { return $s; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function add_action() {} function add_filter() {} function do_action() {} function apply_filters( $t, $v = null, ...$r ) { return $v; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function current_time( $t = 'timestamp' ) { return 'timestamp' === $t ? time() : gmdate( 'Y-m-d H:i:s' ); }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?? time() ); }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function wp_next_scheduled( ...$a ) { return 0; }
function wp_schedule_single_event( ...$a ) { return true; }
function wp_clear_scheduled_hook( ...$a ) {}
function is_admin() { return true; }
function wp_cache_delete( ...$a ) {}
function delete_transient( $k ) { return true; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function get_transient( $k ) { return false; }
$GLOBALS['opts'] = [];

/** The one thing that must not run: the model. What it is SENT is the test. */
class DZE_Marketing_Ai {
	const MENU_SLUG = 'dazont-ecom-ai';
	public static function complete( $system, $user, $model = '', $max = 2000, $timeout = 90 ) {
		$GLOBALS['asked'][] = [ 'system' => $system, 'user' => $user ];
		return json_encode( [ 'fr' => 'Ligne FR', 'de' => 'Zeile DE' ] );
	}
	public static function promo_i18n_prompt() { return 'Write the line a shop in that market would write.'; }
	public static function promo_i18n_on() { return true; }
	public static function get_settings() { return []; }
	public static function pending_count() { return 2; }
}
class DZE_Ai_Usage {
	public static function unit( $u = '' ) {}
	public static function finished( $u = '', $n = 1 ) {}
	public static function over_budget() { return false; }
	public static function budget_message() { return ''; }
}
class DZE_Wpml {
	public static function is_active() { return true; }
	public static function default_language() { return 'en'; }
	public static function get_active_languages() { return [ [ 'code' => 'en' ], [ 'code' => 'fr' ], [ 'code' => 'de' ] ]; }
}
class DZE_Health { public static function log( ...$a ) {} }
class DZE_Modules { public static function enabled( $id ) { return true; } }
class DZE_Restock { const MENU_SLUG = 'dazont-ecom'; }
// WordPress's own two calls, remembered: a page is registered, and a page is
// taken out of the menu without being unregistered.
$GLOBALS['menu'] = [];
function add_submenu_page( $parent, $title, $label, $cap, $slug, $cb = null ) {
	$GLOBALS['menu'][ $slug ] = [ 'label' => $label, 'shown' => true ];
	return 'x';
}
function remove_submenu_page( $parent, $slug ) {
	if ( isset( $GLOBALS['menu'][ $slug ] ) ) { $GLOBALS['menu'][ $slug ]['shown'] = false; }
	return [];
}
function number_format_i18n( $n ) { return (string) $n; }
function add_shortcode( ...$a ) {}
function register_setting( ...$a ) {}
function wp_create_nonce( $a = '' ) { return 'n'; }
function wp_unslash( $v ) { return $v; }

require __DIR__ . '/../' . $dir . '/includes/class-discounts.php';

$fails = 0;
$ran   = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok    %s\n", $what ); return; }
	$fails++;
	printf( "  FAIL  %s\n          got  %s\n          want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}

$langs = [ 'fr' => 'Français', 'de' => 'Deutsch' ];

echo "What goes with a promotion into another market\n";
$GLOBALS['asked'] = [];
$out = DZE_Discounts::translate_line( 'Patriot Day Sale — 20% off', $langs, [
	'start' => '2026-09-11', 'end' => '2026-09-14', 'discount' => '20%',
] );
$sent = (string) ( $GLOBALS['asked'][0]['user'] ?? '' );
ok( 'the line itself',                  false !== strpos( $sent, 'Patriot Day Sale' ), true );
ok( 'the day it starts',                false !== strpos( $sent, '2026-09-11' ), true );
ok( 'the day it ends',                  false !== strpos( $sent, '2026-09-14' ), true );
ok( 'the discount',                     false !== strpos( $sent, '20%' ), true );
ok( 'said to be ONE event everywhere',  false !== strpos( $sent, 'THE SAME ONE IN EVERY MARKET' ), true );
ok( 'every market is named',            false !== strpos( $sent, 'fr (Français)' ) && false !== strpos( $sent, 'de (Deutsch)' ), true );
ok( 'and the answers come back',        $out, [ 'fr' => 'Ligne FR', 'de' => 'Zeile DE' ] );

echo "The rule that stops a holiday being swapped for another\n";
ok( 'the occasion never changes',       false !== strpos( $sent, 'THE OCCASION NEVER CHANGES' ), true );
ok( 'and the case is named outright',   false !== strpos( $sent, '14 Juillet' ), true );
ok( 'with what to do instead',          false !== strpos( $sent, 'say the OFFER instead' ), true );
// The shop's prompt is still sent, whole: this rule is added to it, never
// written into it — a rule inside the editable text only reaches a shop that
// never customised it.
ok( "the shop's own instructions travel too",
	false !== strpos( $sent, 'Write the line a shop in that market would write.' ), true );
ok( 'and the rule OVERRIDES them, in words',
	false !== strpos( $sent, 'OVERRIDES THE INSTRUCTIONS ABOVE' ), true );

echo "A promotion with no dates on it yet\n";
$GLOBALS['asked'] = [];
DZE_Discounts::translate_line( 'Spring Sale', $langs );
$sent = (string) ( $GLOBALS['asked'][0]['user'] ?? '' );
ok( 'no empty date block is printed',   false !== strpos( $sent, 'THE SAME ONE IN EVERY MARKET' ), false );
ok( 'but the occasion rule still is',   false !== strpos( $sent, 'THE OCCASION NEVER CHANGES' ), true );

echo "The background pass hands over the event it holds\n";
$GLOBALS['opts']['dze_discount_rules'] = [ 'ev1' => [
	'type' => 'sale', 'title' => 'Patriot Day Sale', 'banner_text' => 'Patriot Day Sale',
	'start' => '2026-09-11', 'end' => '2026-09-14', 'percent' => 20, 'banner_text_i18n' => [],
] ];
$GLOBALS['asked'] = [];
DZE_Discounts::fill_i18n( 'ev1' );
$sent = (string) ( $GLOBALS['asked'][0]['user'] ?? '' );
ok( 'the same dates, written by itself', false !== strpos( $sent, '2026-09-11' ), true );
ok( 'and the same discount',             false !== strpos( $sent, '20%' ), true );

echo "One Marketing menu, opening on its first tab\n";
// "Pas besoin des menu marketing events et marketing. Juste marketing suffit,
// avec redirection vers le premier onglet (events & calendar)." Two lines in
// the menu saying nearly the same word, for one screen with tabs.
$GLOBALS['menu'] = [];
DZE_Discounts::instance()->register_menu();
$shown = array_keys( array_filter( $GLOBALS['menu'], static fn( $one ) => $one['shown'] ) );
ok( 'one entry in the menu, no more',   count( $shown ), 1 );
ok( 'and it opens on events & calendar', $shown[0] ?? '', DZE_Discounts::MENU_SLUG_EVENTS );
ok( 'called Marketing and nothing else',
	false !== strpos( (string) ( $GLOBALS['menu'][ DZE_Discounts::MENU_SLUG_EVENTS ]['label'] ?? '' ), 'Marketing' ), true );
ok( 'never "Marketing events" again',
	false !== strpos( (string) ( $GLOBALS['menu'][ DZE_Discounts::MENU_SLUG_EVENTS ]['label'] ?? '' ), 'Marketing events' ), false );
// The discount rules are a TAB of that screen: the page must still exist, or
// the tab opens on nothing.
ok( 'the discount rules page is still registered',
	isset( $GLOBALS['menu'][ DZE_Discounts::MENU_SLUG ] ), true );
ok( 'and only hidden from the menu',
	$GLOBALS['menu'][ DZE_Discounts::MENU_SLUG ]['shown'] ?? true, false );
// A page out of the menu highlights nothing, and a menu with no highlight is
// a shop that cannot tell where it is.
$_GET['page'] = DZE_Discounts::MENU_SLUG;
ok( 'the Marketing entry stays lit on it',
	DZE_Discounts::instance()->keep_menu_open( 'whatever' ), DZE_Discounts::MENU_SLUG_EVENTS );
$_GET['page'] = 'something-else';
ok( 'and nothing else is touched',
	DZE_Discounts::instance()->keep_menu_open( 'whatever' ), 'whatever' );
$_GET = [];

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
