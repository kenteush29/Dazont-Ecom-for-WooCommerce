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

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr__( $s, $d = '' ) { return $s; }
function current_user_can( $c ) { return true; }
function add_action( ...$a ) {}
function admin_url( $p = '' ) { return 'http://s/wp-admin/' . $p; }
function add_query_arg( $args, $url = '' ) { return $url . '?' . http_build_query( $args ); }
function wp_nonce_url( $u, $a = '' ) { return $u . '&_wpnonce=n'; }

require __DIR__ . '/../' . $dir . '/includes/class-site.php';

/** The line as the Health tab prints it. */
function line(): string {
	ob_start();
	DZE_Site::render_line();
	return trim( preg_replace( '/\s+/', ' ', wp_strip_tags( (string) ob_get_clean() ) ) );
}
function wp_strip_tags( $s ) { return html_entity_decode( strip_tags( (string) $s ), ENT_QUOTES ); }

/** The banner, as every admin screen of a copy prints it. */
function banner(): string {
	ob_start();
	DZE_Site::notice();
	return trim( preg_replace( '/\s+/', ' ', wp_strip_tags( (string) ob_get_clean() ) ) );
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

echo "The line says the STATE, and an address only when it is the reason\n";
// "Ca semble un peu bugé non ? En tout cas c'est tres mal fait." It printed
// "a copy of the shop (set up on test.kula-tactical.com, running on
// test.kula-tactical.com)" — the same address twice, offered as the evidence
// for a conclusion it does not support. It was a copy because he had SAID so,
// and the line showed the plugin's reckoning instead of that.
$GLOBALS['opts'] = [];
$GLOBALS['home'] = 'https://kula-tactical.com';
DZE_Site::learn();
ok( 'the shop knows why it is not a copy', DZE_Site::reason(), '' );
$said = line();
ok( 'it says what it is',               false !== strpos( $said, 'the shop itself' ), true );
ok( 'and shows no address at all',      false !== strpos( $said, 'kula-tactical.com' ), false );
ok( 'offering the one thing to press',  false !== strpos( $said, 'This site is a copy' ), true );

DZE_Site::declare_copy();
ok( 'a declared copy says so',          DZE_Site::reason(), 'declared' );
$said = line();
ok( 'it names the reason',              false !== strpos( $said, 'because you said so' ), true );
// The bug, in one check: the same address printed twice as an explanation.
ok( 'and shows no address',             false !== strpos( $said, 'kula-tactical.com' ), false );
ok( 'the consequence is beside it',     false !== strpos( $said, 'Nothing is sent' ), true );
ok( 'and the way back',                 false !== strpos( $said, 'This is now the shop' ), true );

DZE_Site::adopt();
$GLOBALS['home'] = 'https://test.kula-tactical.com';
ok( 'a moved address is a copy too',    DZE_Site::reason(), 'address' );
$said = line();
// HERE the two addresses are the reason, so here they are shown — and they
// differ, which is the whole point.
ok( 'it names the shop it belongs to',  false !== strpos( $said, 'kula-tactical.com' ), true );
ok( 'and the site it is running on',    false !== strpos( $said, 'test.kula-tactical.com' ), true );
ok( 'the consequence is beside it too', false !== strpos( $said, 'Nothing is sent' ), true );

// The banner and the line are two places, and they must not become two
// stories: both say the state in the same words, written once.
ok( 'the banner says the same state',   false !== strpos( banner(), DZE_Site::says() ), true );
ok( 'and the line does too',            false !== strpos( line(), DZE_Site::says() ), true );
// It shows on EVERY screen of a staging site: one sentence, not a paragraph.
ok( 'the banner is one short sentence', strlen( banner() ) < 220, true );
$GLOBALS['opts'] = [];
$GLOBALS['home'] = 'https://kula-tactical.com';
DZE_Site::learn();
ok( 'and the shop carries no banner',   banner(), '' );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
