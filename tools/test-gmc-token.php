<?php
/**
 * A Google connection that has been revoked.
 *
 * Run before every release:  php tools/test-gmc-token.php dazont-ecom
 *
 * The shop pressed Sync now and read this, five times over, once per feed:
 *   "EN · 711906774: Google token refresh failed: Token has been expired or
 *    revoked. | FR · … | DE · … | PL · … | ES · …"
 * Google's own words, repeated per market, and not one of them saying what to
 * do — while the screen that DOES the reconnecting went on showing a green
 * "Connected". Google says invalid_grant when the authorisation is gone: no
 * retry fixes it, so it is written down once, said in the shop's own words,
 * and shown where the reconnecting happens.
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
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function esc_attr__( $s, $d = '' ) { return $s; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return (string) $s; }
function absint( $n ) { return abs( (int) $n ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_unslash( $v ) { return $v; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); }
function add_action() {} function add_filter() {} function do_action() {} function apply_filters( $t, $v = null, ...$r ) { return $v; }
function is_admin() { return false; } // the constructor's admin hooks are not the subject
function admin_url( $p = '' ) { return 'http://shop.test/wp-admin/' . $p; }
$GLOBALS['home'] = 'https://kula.test';
function home_url( $p = '/' ) { return $GLOBALS['home'] . $p; }
function wp_nonce_url( $u, $a = '' ) { return $u; }
function wp_create_nonce( $a = '' ) { return 'n'; }
function human_time_diff( $from, $to = 0 ) { return max( 1, (int) round( ( ( $to ?: time() ) - $from ) / 60 ) ) . ' mins'; }
function wp_next_scheduled( ...$a ) { return 0; }
function wp_schedule_event( ...$a ) {} function wp_schedule_single_event( ...$a ) { return true; }
function wp_clear_scheduled_hook( ...$a ) {} function wp_unschedule_hook( ...$a ) {}
function wp_cache_delete( ...$a ) {}
function current_time( $t = 'timestamp' ) { return time(); }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?? time() ); }

function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
$GLOBALS['opts'] = [];
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
$GLOBALS['trans'] = [];
function get_transient( $k ) { return $GLOBALS['trans'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['trans'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['trans'][ $k ] ); return true; }

/** Google, answering the way Google answers a dead refresh token. */
$GLOBALS['asked'] = [];
$GLOBALS['reply'] = [];
function wp_remote_post( $url, $args = [] ) {
	$GLOBALS['asked'][] = [ 'url' => $url, 'body' => $args['body'] ?? [] ];
	return $GLOBALS['reply'];
}
function wp_remote_get( $url, $args = [] ) { return $GLOBALS['reply']; }
function wp_remote_request( $url, $args = [] ) { $GLOBALS['asked'][] = [ 'url' => $url ]; return $GLOBALS['reply']; }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 200 ); }
function is_wp_error( $t ) { return false; }
class DZE_Discounts { const MENU_SLUG_EVENTS = 'dazont-ecom-events'; public static function get_rules() { return []; } }
class DZE_Health { public static function log( ...$a ) {} }
class DZE_Modules { public static function enabled( $id ) { return true; } }

require __DIR__ . '/../' . $dir . '/includes/class-site.php';
// The catalogue of screens: every page reads its name and its tabs from it.
require __DIR__ . '/../' . $dir . '/includes/class-screens.php';
require __DIR__ . '/../' . $dir . '/includes/class-gmc.php';

$fails = 0;
$ran   = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok    %s\n", $what ); return; }
	$fails++;
	printf( "  FAIL  %s\n          got  %s\n          want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}
/** The private token call, which is the whole subject. */
function token(): array {
	$m = new ReflectionMethod( 'DZE_Gmc', 'get_access_token' );
	$m->setAccessible( true );
	try { return [ (string) $m->invoke( DZE_Gmc::instance() ), '' ]; }
	catch ( Throwable $e ) { return [ '', $e->getMessage() ]; }
}
function connected( array $extra = [] ): void {
	$GLOBALS['opts'][ DZE_Gmc::OPT_CONNECTION ] = [ 'refresh_token' => 'r-token', 'email' => 'shop@kula.test' ] + $extra;
	$GLOBALS['opts']['dze_gmc_oauth'] = [ 'client_id' => 'cid', 'client_secret' => 'secret' ];
	$GLOBALS['trans'] = [];
	$GLOBALS['asked'] = [];
}

echo "A connection Google still honours\n";
connected();
$GLOBALS['reply'] = [ 'body' => json_encode( [ 'access_token' => 'A1', 'expires_in' => 3600 ] ) ];
[ $tok, $err ] = token();
ok( 'the token comes back',             $tok, 'A1' );
ok( 'nothing is flagged',               DZE_Gmc::broken_since(), 0 );

echo "The connection this shop actually had\n";
connected();
$GLOBALS['reply'] = [ 'body' => json_encode( [
	'error'             => 'invalid_grant',
	'error_description' => 'Token has been expired or revoked.',
] ) ];
[ $tok, $err ] = token();
ok( 'no token, and a sentence instead',  $tok, '' );
ok( 'in the shop\'s words, not Google\'s', false !== strpos( $err, 'Google has revoked this connection' ), true );
// The path is the one that EXISTS, built from the catalogue: this sentence
// sent the shop to "Settings → Google Merchant Center", then for months to
// "Marketing events" — a page renamed "Marketing" — because it was typed.
ok( 'with the one thing to do',          false !== strpos( $err, 'Connect Google account again' ), true );
ok( 'and where that screen really is',   false !== strpos( $err, DZE_Screens::name( 'marketing', 'gmc' ) ), true );
ok( 'which is the name the menu shows',  false !== strpos( $err, 'Dazont Ecom → Marketing → Google Merchant Center' ), true );
ok( 'and not the one it had before',     false !== strpos( $err, 'Marketing events' ), false );
ok( 'and it is written down',            DZE_Gmc::broken_since() > 0, true );
ok( 'the dead access token is dropped',  get_transient( 'dze_gmc_oauth_token' ), false );

echo "Five feeds, one refusal\n";
// The screenshot was the same error five times, one per market. Once it is
// known, Google is not asked again — the answer cannot be different.
$GLOBALS['asked'] = [];
$said = [];
for ( $i = 0; $i < 5; $i++ ) { $said[] = token()[1]; }
ok( 'Google is not asked again',         count( $GLOBALS['asked'] ), 0 );
ok( 'and every feed says the same thing', count( array_unique( $said ) ), 1 );
ok( 'which is the sentence, not the error',
	false !== strpos( $said[0], 'nothing will sync until it is reconnected' ), true );

echo "Reconnecting is the cure\n";
// What the OAuth callback writes when the shop authorises again.
$conn = DZE_Gmc::get_connection();
unset( $conn['broken'], $conn['broken_why'] );
$conn['refresh_token'] = 'fresh';
update_option( DZE_Gmc::OPT_CONNECTION, $conn, false );
ok( 'the flag goes with the new token',  DZE_Gmc::broken_since(), 0 );
$GLOBALS['reply'] = [ 'body' => json_encode( [ 'access_token' => 'A2', 'expires_in' => 3600 ] ) ];
ok( 'and syncing works again',           token()[0], 'A2' );

echo "A refusal that is NOT a revoked connection\n";
// A network hiccup or a client secret rotated by hand is a different thing
// and must not be filed as "revoked": the shop would be sent to reconnect an
// account that is perfectly connected.
connected();
$GLOBALS['reply'] = [ 'body' => json_encode( [ 'error' => 'invalid_client', 'error_description' => 'Unauthorized' ] ) ];
[ , $err ] = token();
ok( 'it is reported as it came',        false !== strpos( $err, 'Google token refresh failed: Unauthorized' ), true );
ok( 'and nothing is flagged',           DZE_Gmc::broken_since(), 0 );

echo "Whose fault it is, when Google Ads cannot be read\n";
// "Google Ads: link not readable — même sur les comptes avec un google ads
// actif." Two different faults wore the same words: an account whose Ads
// link genuinely cannot be read, and a CONNECTION that is gone — which makes
// every account say it at once and sends the shop hunting through Merchant
// Center for a problem that is one authorisation.
connected();
$GLOBALS['reply'] = [ 'body' => json_encode( [
	'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.',
] ) ];
token(); // the connection is marked gone
$ads = DZE_Gmc::instance()->ads_links_state( '5581970069' );
ok( 'it is named as the connection',    ! empty( $ads['gone'] ), true );
ok( 'and no link is claimed',           $ads['links'], [] );

// A live connection whose account simply refuses: that IS the account's own
// fault, and the reason is kept for the ⓘ.
connected();
$GLOBALS['trans'] = [];
$GLOBALS['reply'] = [ 'body' => json_encode( [ 'access_token' => 'A9', 'expires_in' => 3600 ] ) ];
token();
$GLOBALS['reply'] = [ 'response' => [ 'code' => 403 ], 'body' => json_encode( [ 'error' => [ 'message' => 'Merchant API disabled' ] ] ) ];
$ads = DZE_Gmc::instance()->ads_links_state( '711906774' );
ok( 'a live connection is not blamed',  ! empty( $ads['gone'] ), false );
ok( "and Google's own words are kept",  false !== strpos( (string) $ads['error'], 'Merchant API' ), true );

echo "A copy of the shop never pushes to the real Merchant Center\n";
// The same danger as Klaviyo, and worse for being invisible: a staging site
// carries the service account, and a feed pushed from it lands on the shop's
// own Google account. Refused where every call passes.
$GLOBALS['opts'][ DZE_Site::OPT_HOME ] = 'kula.test';
$GLOBALS['home'] = 'https://test.kula.test';
$GLOBALS['asked'] = [];
$GLOBALS['reply'] = [ 'body' => json_encode( [ 'name' => 'ok' ] ) ];
$dze_call = new ReflectionMethod( 'DZE_Gmc', 'request' );
$dze_call->setAccessible( true );
$dze_said = '';
try {
	$dze_call->invoke( DZE_Gmc::instance(), 'POST', 'https://merchantapi.googleapis.com/x', 'tok', [ 'a' => 1 ] );
} catch ( Throwable $e ) {
	$dze_said = $e->getMessage();
}
ok( 'the push is refused',              '' !== $dze_said, true );
ok( 'and nothing left the site',        $GLOBALS['asked'], [] );
ok( 'the refusal names Google',         false !== strpos( $dze_said, 'Google' ), true );
ok( 'and the site it is running on',    false !== strpos( $dze_said, 'test.kula.test' ), true );
// Reading is what keeps the screens useful on a test site.
$GLOBALS['asked'] = [];
$dze_call->invoke( DZE_Gmc::instance(), 'GET', 'https://merchantapi.googleapis.com/x', 'tok' );
ok( 'reading still goes through',       count( $GLOBALS['asked'] ), 1 );
// The shop itself is untouched by any of it.
$GLOBALS['home'] = 'https://kula.test';
$GLOBALS['asked'] = [];
$dze_call->invoke( DZE_Gmc::instance(), 'POST', 'https://merchantapi.googleapis.com/x', 'tok', [ 'a' => 1 ] );
ok( 'the shop itself pushes as before', count( $GLOBALS['asked'] ), 1 );
unset( $GLOBALS['opts'][ DZE_Site::OPT_HOME ] );

echo "\nWhy the connection keeps coming apart\n";
//
// "J'ai l'impression que ce n'est pas très fiable, déjà la 2e fois qu'il se
// déconnecte." It is not this plugin — the refresh path never throws the token
// away. Google expires a refresh token after SEVEN DAYS while the OAuth
// consent screen's publishing status is "Testing", which is a setting outside
// the plugin and invisible from inside it. The one thing that can recognise it
// is the gap between the two stamps.
$dze_day = 86400;
$dze_now = time();

// NOTHING MEASURED, NOTHING SAID. A sentence printed on every failure would be
// a guess pretending to be a reading, and the shop would act on it.
$GLOBALS['opts'][ DZE_Gmc::OPT_CONNECTION ] = [ 'refresh_token' => 'r' ];
ok( 'never connected, nothing measured',  DZE_Gmc::life_days(), null );
ok( 'and no pattern claimed',             DZE_Gmc::testing_pattern(), '' );

// A LIVE CONNECTION ANSWERS HOW LONG IT HAS HELD — the same question, asked
// before the answer is in.
$GLOBALS['opts'][ DZE_Gmc::OPT_CONNECTION ] = [ 'refresh_token' => 'r', 'connected' => $dze_now - 3 * $dze_day ];
ok( 'a live one says how long so far',    DZE_Gmc::life_days(), 3 );
ok( 'and still claims no pattern',        DZE_Gmc::testing_pattern(), '' );

// ONE LIFE IS A COINCIDENCE. Broken at seven days with nothing before it is
// not yet a pattern: a password change does exactly the same thing once.
$GLOBALS['opts'][ DZE_Gmc::OPT_CONNECTION ] = [
	'refresh_token' => 'r',
	'connected'     => $dze_now - 7 * $dze_day,
	'broken'        => $dze_now,
];
ok( 'one seven-day life is measured',     DZE_Gmc::life_days(), 7 );
ok( 'but not yet called a pattern',       DZE_Gmc::testing_pattern(), '' );

// A SECOND ONE THE SAME LENGTH IS.
$GLOBALS['opts'][ DZE_Gmc::OPT_CONNECTION ] = [
	'refresh_token' => 'r',
	'connected'     => $dze_now - 7 * $dze_day,
	'broken'        => $dze_now,
	'last_life'     => 7 * $dze_day,
];
$dze_why = DZE_Gmc::testing_pattern();
ok( 'twice the same, and it is named',    '' !== $dze_why, true );
ok( 'it says the figure it measured',     false !== strpos( $dze_why, '7 days' ), true );
ok( 'it names the real cause',            false !== strpos( $dze_why, 'Testing' ), true );
// A MESSAGE THAT NAMES A SCREEN IS A WAY TO THAT SCREEN — Google's own, which
// this plugin cannot link to, so it names the path exactly.
ok( 'and the one thing to press',         false !== strpos( $dze_why, 'Publish app' ), true );
ok( 'it does not blame the plugin',       false !== strpos( $dze_why, 'not a fault here' ), true );

// A CONNECTION THAT DIED IN AN HOUR IS NOT THIS. Revoked by hand, or a
// password changed — naming the seven-day rule there would send the shop to
// change a setting that was never the problem.
$GLOBALS['opts'][ DZE_Gmc::OPT_CONNECTION ] = [
	'refresh_token' => 'r',
	'connected'     => $dze_now - 3600,
	'broken'        => $dze_now,
	'last_life'     => 3600,
];
ok( 'a short life is not the seven-day rule', DZE_Gmc::testing_pattern(), '' );
// Nor is one that held for months.
$GLOBALS['opts'][ DZE_Gmc::OPT_CONNECTION ] = [
	'refresh_token' => 'r',
	'connected'     => $dze_now - 200 * $dze_day,
	'broken'        => $dze_now,
	'last_life'     => 190 * $dze_day,
];
ok( 'nor is one that lasted months',      DZE_Gmc::testing_pattern(), '' );

// AND THE FAILURE MESSAGE CARRIES THE MEASURED CAUSE rather than the generic
// remedy, because reconnecting works and then breaks again in a week.
$GLOBALS['opts'][ DZE_Gmc::OPT_CONNECTION ] = [
	'refresh_token' => 'r',
	'connected'     => $dze_now - 7 * $dze_day,
	'broken'        => $dze_now,
	'last_life'     => 7 * $dze_day,
];
ok( 'the refusal names the pattern',      false !== strpos( DZE_Gmc::broken_message(), 'Publish app' ), true );
$GLOBALS['opts'][ DZE_Gmc::OPT_CONNECTION ] = [ 'refresh_token' => 'r', 'broken' => $dze_now ];
ok( 'and falls back where it cannot tell',
	false !== strpos( DZE_Gmc::broken_message(), 'revoked this connection' ), true );

// WHAT HAS TO BE TRUE OUTSIDE THE PLUGIN is said before the first
// disconnection, not after the second.
ok( 'the condition is stated plainly',    false !== strpos( DZE_Gmc::keeps_said(), 'Publish app' ), true );
ok( 'with the seven days in it',          false !== strpos( DZE_Gmc::keeps_said(), 'seven days' ), true );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
