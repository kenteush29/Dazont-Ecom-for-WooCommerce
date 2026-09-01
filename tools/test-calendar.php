<?php
/**
 * The marketing calendar, with the emails on it.
 *
 * "En plus on va afficher les emails planifiés sur la vue calendrier." A
 * promotion is a coloured band across its days; an email is the day something
 * actually reaches a reader, and the two belong on one grid.
 *
 * This bench renders the REAL grid — the same method the Marketing Events page
 * embeds — against a fake shop, and checks what it hands the screen. The
 * drawing itself happens in the browser, so tools/js/calendar-emails.mjs asks
 * this same file for its HTML (php tools/test-calendar.php <dir> html) and
 * opens it in a real one: a grid that carries the right data and draws nothing
 * is the bug this pair exists to catch.
 *
 * Usage: php tools/test-calendar.php dazont-ecom [html]
 */

$dir = $argv[1] ?? 'dazont-ecom';
$as_html = 'html' === ( $argv[2] ?? '' );

define( 'ABSPATH', __DIR__ );
define( 'DZE_DIR', __DIR__ . '/../' . $dir . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['dze_opts'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['dze_opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['dze_opts'][ $k ] = $v; return true; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_shortcode( ...$a ) {}
function shortcode_atts( $pairs, $atts, $sc = '' ) { return array_merge( $pairs, (array) $atts ); }
function is_admin() { return true; }
function admin_url( $p = '' ) { return 'https://shop.test/wp-admin/' . $p; }
function add_query_arg( ...$a ) { return is_array( $a[0] ?? null ) ? ( ( $a[1] ?? '' ) . '?' . http_build_query( $a[0] ) ) : ''; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_html_e( $s, $d = '' ) { echo htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return (string) $s; }
function esc_attr__( $s, $d = '' ) { return (string) $s; }
function __( $s, $d = '' ) { return $s; }
function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function wp_rand( $a = 0, $b = 0 ) { return 1234; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function current_time( $t = 'timestamp' ) { return 'timestamp' === $t ? time() : gmdate( 'Y-m-d H:i:s' ); }
function date_i18n( $f, $ts = null ) { return gmdate( $f, $ts ?: time() ); }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?: time() ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_kses_post( $s ) { return (string) $s; }
function get_locale() { return 'en_US'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); }
class WP_Error {}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
$GLOBALS['wp_locale'] = null;

// The shop: two promotions, and the emails planned for them.
// Anchored to the MIDDLE of the month the calendar opens on, never to "in
// four days": a test written on the 28th would put its emails in the next
// month and fail for the calendar, which was right all along.
$today  = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
$in3    = $today->format( 'Y-m' ) . '-12';
$in4    = $today->format( 'Y-m' ) . '-14';
$in6    = $today->format( 'Y-m' ) . '-16';

class DZE_Modules {
	public static function enabled( $id ) { return empty( $GLOBALS['dze_off'][ $id ] ); }
}
class DZE_Discounts {
	const MENU_SLUG_EVENTS = 'dazont-ecom-marketing-events';
	public static function get_rules() { return $GLOBALS['dze_rules'] ?? []; }
}
class DZE_Klaviyo {
	public static function calendar( $skip = '' ) { return $GLOBALS['dze_cal'] ?? []; }
}

$GLOBALS['dze_rules'] = [
	'bts' => [ 'type' => 'sale', 'title' => 'Back to School Sale', 'start' => $in3, 'end' => $in6, 'percent' => 10, 'enabled' => true ],
];
$GLOBALS['dze_cal'] = [
	[ 'day' => $in4, 'rule' => 'bts', 'label' => 'Back to School Sale — Last chance', 'name' => 'Last chance',
		'title' => 'Back to School Sale', 'subject' => 'Two days left on 10% off', 'state' => 'scheduled',
		'url' => 'https://shop.test/wp-admin/admin.php?page=dazont-ecom-marketing-events&edit=bts' ],
	[ 'day' => $in6, 'rule' => 'patriot', 'label' => 'Patriot Day Sale — Warm-up', 'name' => 'Warm-up',
		'title' => 'Patriot Day Sale', 'subject' => 'Something is coming', 'state' => '',
		'url' => 'https://shop.test/wp-admin/admin.php?page=dazont-ecom-marketing-events&edit=patriot' ],
];

require __DIR__ . '/../' . $dir . '/includes/class-marketing-ai.php';

$grid = DZE_Marketing_Ai::instance()->calendar_grid_html( 1, true );
if ( $as_html ) {
	echo '<!doctype html><html><head><meta charset="utf-8"></head><body>', $grid, '</body></html>';
	exit( 0 );
}

$fails = 0;
$ran   = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok    %s\n", $what ); return; }
	$fails++;
	printf( "  FAIL  %s\n          got  %s\n          want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}

echo "The emails are on the calendar the shop plans with\n";
ok( 'the grid carries the emails',      false !== strpos( $grid, '"mails":[' ), true );
ok( 'each one with its day',            false !== strpos( $grid, '"day":"' . $in4 . '"' ), true );
ok( 'the name it is known by',          false !== strpos( $grid, '"name":"Last chance"' ), true );
ok( 'where it stands in Klaviyo',       false !== strpos( $grid, '"state":"scheduled"' ), true );
ok( 'and the promotion it belongs to',  false !== strpos( $grid, 'edit=bts' ), true );
ok( 'an email nobody has filed yet is there too',
	false !== strpos( $grid, '"name":"Warm-up"' ), true );

echo "The front end is not the marketing plan\n";
// A shop page draws the promotions; the emails a shop is preparing are
// nobody's business but the shop's — and the front pays for nothing it does
// not show.
$front = DZE_Marketing_Ai::instance()->calendar_grid_html( 1 );
ok( 'the shortcode draws no emails',    false !== strpos( $front, '"mails":[]' ), true );
ok( 'and still draws the promotions',   false !== strpos( $front, 'Back to School Sale' ), true );

echo "A module switched off leaves no trace\n";
$GLOBALS['dze_off'] = [ 'klaviyo' => true ];
$off = DZE_Marketing_Ai::instance()->calendar_grid_html( 1, true );
ok( 'no emails when the module is off', false !== strpos( $off, '"mails":[]' ), true );
ok( 'and the calendar still works',     false !== strpos( $off, 'dze-cal__grid' ) || false !== strpos( $off, 'dze-cal__body' ), true );
$GLOBALS['dze_off'] = [];

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
