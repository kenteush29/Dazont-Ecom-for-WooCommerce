<?php
/**
 * The shop, and a copy of the shop.
 *
 * Run before every release:  php tools/test-copy.php dazont-ecom
 *
 * A staging site is a copy of the shop, keys and scheduled hooks included.
 * Nobody has to press anything: one cron tick on the copy writes campaigns
 * into the real Klaviyo account and pushes feeds to the real Merchant Center.
 * This is the part that tells the two apart, and it has to be right in both
 * directions — a shop wrongly called a copy is a shop that has quietly
 * stopped sending.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
function __( $s, $d = '' ) { return $s; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); }
$GLOBALS['home'] = 'https://kula-tactical.com';
function home_url( $p = '/' ) { return $GLOBALS['home'] . $p; }
$GLOBALS['opts'] = [];
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['auto'][ $k ] = $a; $GLOBALS['opts'][ $k ] = $v; return true; }

require __DIR__ . '/../' . $dir . '/includes/class-site.php';

$fails = 0;
$ran   = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok    %s\n", $what ); return; }
	$fails++;
	printf( "  FAIL  %s\n          got  %s\n          want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}

echo "A shop updating to this version adopts itself, and nothing changes\n";
// The one thing that must not happen: a live shop reading this release and
// deciding it is a copy of itself. Nothing is recorded yet, so it records what
// it is standing on.
$GLOBALS['opts'] = [];
DZE_Site::learn();
ok( 'it writes down its own address',   DZE_Site::known(), 'kula-tactical.com' );
ok( 'and is not a copy',                DZE_Site::is_copy(), false );
ok( 'so it still writes outward',       DZE_Site::blocks( 'POST' ), false );
// Read only once: a shop that moves domain legitimately is not re-learned
// behind its back — that decision is a click, further down.
$GLOBALS['home'] = 'https://kula-tactical.fr';
DZE_Site::learn();
ok( 'a second look does not rewrite it', DZE_Site::known(), 'kula-tactical.com' );
$GLOBALS['home'] = 'https://kula-tactical.com';

echo "www is not another shop\n";
$GLOBALS['home'] = 'https://www.kula-tactical.com';
ok( 'the same shop with www',           DZE_Site::is_copy(), false );
$GLOBALS['home'] = 'https://KULA-TACTICAL.com';
ok( 'and in capitals',                  DZE_Site::is_copy(), false );

echo "The copy, which is where the damage would come from\n";
$GLOBALS['home'] = 'https://test.kula-tactical.com';
ok( 'a different address is a copy',    DZE_Site::is_copy(), true );
// Reading stays open: most of what a test site is for is looking at what the
// real accounts hold.
ok( 'reading is still allowed',         DZE_Site::blocks( 'GET' ), false );
ok( 'and HEAD too',                     DZE_Site::blocks( 'HEAD' ), false );
foreach ( [ 'POST', 'PUT', 'PATCH', 'DELETE', 'post', ' patch ' ] as $m ) {
	ok( "writing outward is refused ($m)", DZE_Site::blocks( $m ), true );
}
// The sentence names BOTH addresses. "Permission denied" on a test site reads
// as a bug and sends somebody looking for a key that is perfectly fine.
$why = DZE_Site::why( 'Klaviyo' );
ok( 'the refusal names the service',    false !== strpos( $why, 'Klaviyo' ), true );
ok( 'the shop it was set up on',        false !== strpos( $why, 'kula-tactical.com' ), true );
ok( 'and the site it is running on',    false !== strpos( $why, 'test.kula-tactical.com' ), true );

echo "Both ways out, and neither of them silent\n";
DZE_Site::adopt();
ok( 'adopting makes it the shop',       DZE_Site::is_copy(), false );
ok( 'and the address is the new one',   DZE_Site::known(), 'test.kula-tactical.com' );
ok( 'so it writes outward again',       DZE_Site::blocks( 'POST' ), false );
// A clone restored over the shop's own address cannot be told apart by its
// address. Said by hand, then — and it outranks the address.
DZE_Site::declare_copy();
ok( 'declared a copy, it is one',       DZE_Site::is_copy(), true );
ok( 'even on the recorded address',     DZE_Site::known(), 'test.kula-tactical.com' );
ok( 'and writing is refused again',     DZE_Site::blocks( 'PATCH' ), true );
DZE_Site::adopt();
ok( 'adopting clears that too',         DZE_Site::is_copy(), false );

echo "Neither option is read on every page of the shop\n";
// A row marked autoload is read on EVERY request, front included. These two
// are read in the admin and on the outward calls themselves.
ok( 'the address does not autoload',    $GLOBALS['auto'][ DZE_Site::OPT_HOME ] ?? true, false );
ok( 'nor the flag',                     $GLOBALS['auto'][ DZE_Site::OPT_COPY ] ?? true, false );

echo "The autopilot on a copy: nothing unattended, everything by hand\n";
// This is the balance the shop asked for. Blocking the whole autopilot on a
// test site would stop him testing the very thing he set the site up for; not
// blocking it means a cron tick, at three in the morning, spending his real
// budget on a site nobody is looking at. So: scheduled runs stop, and a run he
// presses himself goes through.
$GLOBALS['opts'] = [ DZE_Site::OPT_HOME => 'kula-tactical.com' ];
$GLOBALS['home'] = 'https://test.kula-tactical.com';
ok( 'a scheduled run does not happen',  DZE_Site::autopilot_ok( false ), false );
ok( 'one he presses himself does',      DZE_Site::autopilot_ok( true ), true );
$GLOBALS['home'] = 'https://kula-tactical.com';
ok( 'and the shop itself runs on its own', DZE_Site::autopilot_ok( false ), true );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
